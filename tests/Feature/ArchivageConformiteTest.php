<?php

namespace Tests\Feature;

use App\Jobs\ConvertirPdfaJob;
use App\Jobs\RecalculerConservationJob;
use App\Jobs\ReplicateFichierJob;
use App\Jobs\ScellerFichierJob;
use App\Jobs\VerifierIntegriteFichiersJob;
use App\Livewire\Backend\SecuriteAudit;
use App\Livewire\Backend\ShowCourrier;
use App\Models\ApercuDocument;
use App\Models\Courrier;
use App\Models\CourrierConsultation;
use App\Models\CourrierHistorique;
use App\Models\DureeConservation;
use App\Models\EmpreinteFichier;
use App\Models\PieceJointe;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\ApercuSecurise;
use App\Services\ConservationService;
use App\Services\EtatSysteme;
use App\Services\WorkflowService;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Livewire\Livewire;
use Tests\TestCase;

// Module 9 — conformité de l'archivage (2026-10-30, « fix all of them », voir
// DECISIONS.md « Archivage : conformité ») : journal des consultations,
// empreintes SHA-256, copie PDF/A, durées de conservation et destruction,
// verrou technique de l'historique, garde-fous de déploiement.
class ArchivageConformiteTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    private function avecPrivileges(array $cles): User
    {
        $user = User::factory()->create(['profil_id' => null]);
        $user->privilegesDirectes()->attach(Privilege::whereIn('cle', $cles)->pluck('id'));

        return User::find($user->id);
    }

    private function courrier(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'priorite' => 'normale',
            'service_id' => Service::factory()->create()->id,
            'statut' => 'en_traitement',
        ], $attributs));
    }

    // Archive dont l'échéance de conservation est passée (archivée il y a
    // 11 ans, durée par défaut 10 ans).
    private function archiveEchue(array $attributs = []): Courrier
    {
        $courrier = $this->courrier(['statut' => 'archive'] + $attributs);
        $courrier->forceFill(['archive_le' => now()->subYears(11)])->saveQuietly();
        app(ConservationService::class)->appliquer($courrier);

        return $courrier->fresh();
    }

    private function assertLeve(string $classe, Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($classe, $e);

            return;
        }

        $this->fail("{$classe} attendue, aucune exception levée.");
    }

    // ===== 5. Verrou technique de l'historique (Règle n°5) =====

    public function test_lhistorique_refuse_toute_modification_ou_suppression(): void
    {
        $entree = CourrierHistorique::create(['courrier_id' => $this->courrier()->id, 'action' => 'creation', 'commentaire' => 'original']);

        $this->assertLeve(LogicException::class, fn () => $entree->update(['commentaire' => 'falsifié']));
        $this->assertLeve(LogicException::class, fn () => $entree->delete());

        $this->assertDatabaseHas('courrier_historiques', ['id' => $entree->id, 'commentaire' => 'original']);
    }

    public function test_le_journal_des_consultations_refuse_toute_modification_ou_suppression(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));
        CourrierConsultation::enregistrer($this->courrier()->id, 'fiche');
        $ligne = CourrierConsultation::firstOrFail();

        $this->assertLeve(LogicException::class, fn () => $ligne->update(['action' => 'apercu']));
        $this->assertLeve(LogicException::class, fn () => $ligne->delete());

        $this->assertDatabaseHas('courrier_consultations', ['id' => $ligne->id, 'action' => 'fiche']);
    }

    // ===== 1. Journal des consultations =====

    // 2026-11-09 — plus de téléchargement : fiche, aperçu sécurisé du
    // document et d'une pièce jointe, impression du bordereau.
    public function test_chaque_ouverture_consultation_et_impression_est_journalisee(): void
    {
        Storage::fake('s3');
        $admin = $this->utilisateur('Administrateur');
        $courrier = $this->courrier(['fichier_path' => 'courriers/doc.pdf']);
        $piece = PieceJointe::create(['courrier_id' => $courrier->id, 'fichier_path' => 'courriers/pj.pdf', 'nom_original' => 'pj.pdf', 'type_mime' => 'application/pdf', 'taille' => 12]);
        $this->apercuPret(ApercuSecurise::TYPE_COURRIER, $courrier->id);
        $this->apercuPret(ApercuSecurise::TYPE_PIECE_JOINTE, $piece->id);

        $this->actingAs($admin);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id]);
        $this->get(route('apercus.manifeste', ['courrier', $courrier->id]))->assertOk();
        $this->get(route('apercus.manifeste', ['piece-jointe', $piece->id]))->assertOk();
        $this->get(route('courriers.bordereau', $courrier))->assertOk();

        $this->assertEqualsCanonicalizing(
            ['fiche', 'apercu', 'apercu_piece_jointe', 'impression'],
            CourrierConsultation::where('courrier_id', $courrier->id)->where('user_id', $admin->id)->pluck('action')->all(),
        );
        $this->assertDatabaseHas('courrier_consultations', ['action' => 'apercu_piece_jointe', 'piece_jointe_id' => $piece->id]);
    }

    // La visionneuse interroge l'aperçu plusieurs fois pour un seul
    // affichage (doublons constatés sur la vraie base le 2026-11-06).
    public function test_une_meme_consultation_nest_inscrite_quune_fois_par_minute(): void
    {
        $courrier = $this->courrier(['fichier_path' => 'courriers/doc.pdf']);
        $this->apercuPret(ApercuSecurise::TYPE_COURRIER, $courrier->id);
        $this->actingAs($this->utilisateur('Administrateur'));

        $this->get(route('apercus.manifeste', ['courrier', $courrier->id]))->assertOk();
        $this->get(route('apercus.manifeste', ['courrier', $courrier->id]))->assertOk();
        $this->assertSame(1, CourrierConsultation::where('action', 'apercu')->count());

        $this->travel(CourrierConsultation::SECONDES_REGROUPEMENT + 1)->seconds();
        $this->get(route('apercus.manifeste', ['courrier', $courrier->id]))->assertOk();
        $this->assertSame(2, CourrierConsultation::where('action', 'apercu')->count());
    }

    private function apercuPret(string $type, int $id): void
    {
        ApercuDocument::create(['fichier_type' => $type, 'fichier_id' => $id, 'statut' => ApercuDocument::STATUT_PRET, 'pages' => [[1240, 1754]], 'genere_le' => now()]);
    }

    public function test_le_journal_napparait_sur_la_fiche_quavec_le_privilege(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $courrier = $this->courrier(['service_id' => Service::factory()->create(['responsable_id' => $responsable->id])->id]);

        $this->actingAs($responsable);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertDontSee(__('Fiche consultée'));

        $this->actingAs($this->utilisateur('Administrateur'));
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Fiche consultée'))
            ->assertSee($responsable->name);
    }

    // ===== 2. Empreintes SHA-256 =====

    public function test_un_fichier_rattache_est_scelle_en_file_dattente(): void
    {
        config(['services.archivage.scellement_auto' => true]);
        Queue::fake();

        $courrier = $this->courrier(['fichier_path' => 'courriers/doc.pdf']);

        Queue::assertPushed(ScellerFichierJob::class, fn ($job) => $job->type === EmpreinteFichier::TYPE_COURRIER && $job->id === $courrier->id);
    }

    public function test_la_verification_detecte_un_fichier_modifie_ou_disparu_et_alerte_une_seule_fois(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('courriers/a.pdf', 'original A');
        Storage::disk('s3')->put('courriers/b.pdf', 'original B');
        $a = $this->courrier(['fichier_path' => 'courriers/a.pdf']);
        $b = $this->courrier(['fichier_path' => 'courriers/b.pdf']);

        $verifier = fn () => app()->call([new VerifierIntegriteFichiersJob, 'handle']);

        // Premier passage : les fichiers sans empreinte sont scellés.
        $verifier();
        $empreinteA = EmpreinteFichier::where('fichier_type', EmpreinteFichier::TYPE_COURRIER)->where('fichier_id', $a->id)->firstOrFail();
        $this->assertSame(hash('sha256', 'original A'), $empreinteA->sha256);
        $this->assertSame(2, EmpreinteFichier::where('statut', EmpreinteFichier::STATUT_OK)->count());
        $this->assertNotNull(Cache::get(VerifierIntegriteFichiersJob::CLE_DERNIERE_VERIFICATION));

        Storage::disk('s3')->put('courriers/a.pdf', 'contenu falsifié');
        Storage::disk('s3')->delete('courriers/b.pdf');

        $verifier();
        $verifier();

        $this->assertSame(EmpreinteFichier::STATUT_ALTEREE, $empreinteA->fresh()->statut);
        $this->assertSame(hash('sha256', 'original A'), $empreinteA->fresh()->sha256);
        $this->assertSame(EmpreinteFichier::STATUT_MANQUANT, EmpreinteFichier::where('fichier_id', $b->id)->value('statut'));
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $a->id)->where('action', 'integrite_alerte')->count());
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $b->id)->where('action', 'integrite_alerte')->count());
    }

    // Cas réel du 2026-11-05 (GEC-2026-000012) : pièces jointes scellées sous
    // « _en_attente », puis rangées dans le dossier du service à
    // l'acceptation — signalées introuvables à tort.
    public function test_une_piece_jointe_deplacee_apres_scellement_reste_conforme(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('courriers/2026/_en_attente/GEC-1/pj.pdf', 'pièce jointe');
        $courrier = $this->courrier();
        $piece = PieceJointe::create(['courrier_id' => $courrier->id, 'fichier_path' => 'courriers/2026/_en_attente/GEC-1/pj.pdf', 'nom_original' => 'pj.pdf', 'type_mime' => 'application/pdf', 'taille' => 12]);
        $verifier = fn () => app()->call([new VerifierIntegriteFichiersJob, 'handle']);
        $verifier();

        Storage::disk('s3')->move('courriers/2026/_en_attente/GEC-1/pj.pdf', 'courriers/2026/DI/GEC-1/pj.pdf');
        $piece->update(['fichier_path' => 'courriers/2026/DI/GEC-1/pj.pdf']);
        $verifier();

        $empreinte = EmpreinteFichier::where('fichier_type', EmpreinteFichier::TYPE_PIECE_JOINTE)->where('fichier_id', $piece->id)->firstOrFail();
        $this->assertSame(EmpreinteFichier::STATUT_OK, $empreinte->statut);
        $this->assertSame('courriers/2026/DI/GEC-1/pj.pdf', $empreinte->chemin);
        $this->assertSame(0, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'integrite_alerte')->count());
    }

    public function test_une_fausse_alerte_est_close_dans_lhistorique(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('courriers/2026/DI/GEC-1/pj.pdf', 'pièce jointe');
        $courrier = $this->courrier();
        $piece = PieceJointe::create(['courrier_id' => $courrier->id, 'fichier_path' => 'courriers/2026/DI/GEC-1/pj.pdf', 'nom_original' => 'pj.pdf', 'type_mime' => 'application/pdf', 'taille' => 12]);
        EmpreinteFichier::create(['fichier_type' => EmpreinteFichier::TYPE_PIECE_JOINTE, 'fichier_id' => $piece->id, 'chemin' => 'courriers/2026/_en_attente/GEC-1/pj.pdf', 'sha256' => hash('sha256', 'pièce jointe'), 'scelle_le' => now(), 'statut' => EmpreinteFichier::STATUT_MANQUANT]);

        app()->call([new VerifierIntegriteFichiersJob, 'handle']);

        $this->assertSame(EmpreinteFichier::STATUT_OK, EmpreinteFichier::where('fichier_id', $piece->id)->value('statut'));
        $this->assertDatabaseHas('courrier_historiques', ['courrier_id' => $courrier->id, 'action' => 'integrite_retablie']);
    }

    public function test_le_deplacement_dune_piece_jointe_relance_le_scellement(): void
    {
        config(['services.archivage.scellement_auto' => true]);
        Queue::fake();
        $piece = PieceJointe::create(['courrier_id' => $this->courrier()->id, 'fichier_path' => 'courriers/2026/_en_attente/GEC-1/pj.pdf', 'nom_original' => 'pj.pdf', 'type_mime' => 'application/pdf', 'taille' => 12]);
        $piece->update(['fichier_path' => 'courriers/2026/DI/GEC-1/pj.pdf']);
        $piece->update(['nom_original' => 'renommé.pdf']);

        Queue::assertPushed(ScellerFichierJob::class, fn ($job) => $job->type === EmpreinteFichier::TYPE_PIECE_JOINTE && $job->id === $piece->id);
        Queue::assertPushedTimes(ScellerFichierJob::class, 2);
    }

    // ===== 4. Copie d'archive PDF/A =====

    public function test_larchivage_met_la_copie_pdfa_en_file_dattente_et_fixe_lecheance(): void
    {
        config(['services.archivage.pdfa_auto' => true]);
        Queue::fake();
        $courrier = $this->courrier(['statut' => 'traite']);

        app(WorkflowService::class)->archiverAutomatiquement($courrier);

        Queue::assertPushed(ConvertirPdfaJob::class, fn ($job) => $job->courrierId === $courrier->id);
        $courrier->refresh();
        $this->assertTrue($courrier->archive_le->isToday());
        $this->assertSame(today()->addYears(10)->toDateString(), $courrier->conserver_jusqua->toDateString());
    }

    public function test_la_copie_pdfa_est_ecrite_a_cote_de_loriginal_sans_le_modifier(): void
    {
        Storage::fake('s3');
        Queue::fake([ReplicateFichierJob::class]);
        config(['services.ghostscript.binary' => 'C:/gs/bin/gswin64c.exe']);
        Storage::disk('s3')->put('courriers/2026/TST/doc.pdf', '%PDF original');

        Process::fake(function (PendingProcess $process) {
            $sortie = collect($process->command)->first(fn ($argument) => str_starts_with($argument, '-sOutputFile='));
            file_put_contents(substr($sortie, strlen('-sOutputFile=')), '%PDF-A copie');

            return Process::result();
        });

        $courrier = $this->courrier(['statut' => 'archive', 'fichier_path' => 'courriers/2026/TST/doc.pdf']);
        app()->call([new ConvertirPdfaJob($courrier->id), 'handle']);

        $this->assertSame('courriers/2026/TST/doc-pdfa.pdf', $courrier->fresh()->fichier_pdfa_path);
        $this->assertSame('%PDF-A copie', Storage::disk('s3')->get('courriers/2026/TST/doc-pdfa.pdf'));
        $this->assertSame('%PDF original', Storage::disk('s3')->get('courriers/2026/TST/doc.pdf'));
        Process::assertRan(fn ($process) => in_array('-dPDFA=2', $process->command, true) && in_array('-dSAFER', $process->command, true));
        Queue::assertPushed(ReplicateFichierJob::class);
    }

    // ===== 3. Durées de conservation et destruction =====

    public function test_lecheance_suit_la_duree_du_type_sinon_la_duree_par_defaut(): void
    {
        DureeConservation::create(['type_document' => 'Facture', 'annees' => 5]);
        $conservation = app(ConservationService::class);

        $facture = $this->courrier(['statut' => 'archive', 'type_document' => 'Facture']);
        $lettre = $this->courrier(['statut' => 'archive', 'type_document' => 'Lettre']);
        $facture->forceFill(['archive_le' => '2020-03-15 10:00:00'])->saveQuietly();
        $lettre->forceFill(['archive_le' => '2020-03-15 10:00:00'])->saveQuietly();

        $this->assertSame('2025-03-15', $conservation->echeance($facture)->toDateString());
        $this->assertSame('2030-03-15', $conservation->echeance($lettre)->toDateString());
    }

    public function test_le_recalcul_reprend_la_date_darchivage_de_lhistorique(): void
    {
        $courrier = $this->courrier(['statut' => 'archive']);
        CourrierHistorique::forceCreate(['courrier_id' => $courrier->id, 'action' => 'archivage_automatique', 'created_at' => '2020-01-10 09:00:00']);

        app()->call([new RecalculerConservationJob, 'handle']);

        $courrier->refresh();
        $this->assertSame('2020-01-10', $courrier->archive_le->toDateString());
        $this->assertSame('2030-01-10', $courrier->conserver_jusqua->toDateString());
    }

    public function test_un_administrateur_detruit_une_archive_echue_avec_motif_et_confirmation(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('courriers/doc.pdf', 'original');
        Storage::disk('s3')->put('courriers/pj.pdf', 'pièce jointe');
        $admin = $this->utilisateur('Administrateur');
        $archive = $this->archiveEchue(['fichier_path' => 'courriers/doc.pdf']);
        $piece = PieceJointe::create(['courrier_id' => $archive->id, 'fichier_path' => 'courriers/pj.pdf', 'nom_original' => 'pj.pdf', 'type_mime' => 'application/pdf', 'taille' => 12]);
        EmpreinteFichier::create(['fichier_type' => EmpreinteFichier::TYPE_COURRIER, 'fichier_id' => $archive->id, 'chemin' => 'courriers/doc.pdf', 'sha256' => hash('sha256', 'original'), 'scelle_le' => now(), 'statut' => EmpreinteFichier::STATUT_OK]);

        $this->actingAs($admin);
        $page = Livewire::test(SecuriteAudit::class)
            ->set('onglet', 'conservation')
            ->assertSee($archive->numero_reference)
            ->call('ouvrirAction', $archive->id, 'detruire')
            ->set('motif', 'Échéance légale atteinte')
            ->set('confirmation', 'mauvaise référence')
            ->call('executerAction')
            ->assertHasErrors('confirmation');

        $this->assertFalse($archive->fresh()->trashed());

        $page->set('confirmation', $archive->numero_reference)->call('executerAction')->assertHasNoErrors();

        $this->assertSoftDeleted('courriers', ['id' => $archive->id]);
        Storage::disk('s3')->assertMissing('courriers/doc.pdf');
        Storage::disk('s3')->assertMissing('courriers/pj.pdf');
        $this->assertSame(EmpreinteFichier::STATUT_DETRUIT, EmpreinteFichier::where('fichier_id', $archive->id)->value('statut'));
        $this->assertDatabaseHas('courrier_historiques', ['courrier_id' => $archive->id, 'action' => 'destruction', 'auteur_id' => $admin->id]);
        $this->assertDatabaseHas('pieces_jointes', ['id' => $piece->id]);
    }

    public function test_une_archive_sous_gel_juridique_ne_peut_pas_etre_detruite(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));
        $archive = $this->archiveEchue();

        Livewire::test(SecuriteAudit::class)
            ->call('ouvrirAction', $archive->id, 'geler')
            ->set('motif', 'Litige en cours')
            ->call('executerAction')
            ->assertHasNoErrors();

        $this->assertTrue($archive->fresh()->gel_juridique);
        $this->assertDatabaseHas('courrier_historiques', ['courrier_id' => $archive->id, 'action' => 'gel_juridique', 'commentaire' => 'Litige en cours']);

        Livewire::test(SecuriteAudit::class)
            ->call('ouvrirAction', $archive->id, 'detruire')
            ->set('motif', 'Tentative de destruction')
            ->set('confirmation', $archive->numero_reference)
            ->call('executerAction')
            ->assertForbidden();

        $this->assertFalse($archive->fresh()->trashed());
    }

    public function test_la_prolongation_repousse_lecheance_et_laisse_une_trace(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));
        $archive = $this->archiveEchue();
        $echeance = $archive->conserver_jusqua;

        Livewire::test(SecuriteAudit::class)
            ->call('ouvrirAction', $archive->id, 'prolonger')
            ->set('anneesProlongation', '2')
            ->set('motif', 'Demande du service juridique')
            ->call('executerAction')
            ->assertHasNoErrors();

        $this->assertSame($echeance->addYears(2)->toDateString(), $archive->fresh()->conserver_jusqua->toDateString());
        $this->assertDatabaseHas('courrier_historiques', ['courrier_id' => $archive->id, 'action' => 'conservation_prolongee']);
    }

    // ===== Droits (Règle n°6/7) =====

    public function test_securite_et_audit_est_refuse_sans_privilege(): void
    {
        $this->actingAs($this->utilisateur('Agent'))->get(route('admin.securite-audit'))->assertForbidden();

        $this->actingAs($this->utilisateur('Administrateur'))->get(route('admin.securite-audit'))
            ->assertOk()
            ->assertSee(__('Journal des consultations'));
    }

    public function test_les_actions_de_conservation_exigent_leur_propre_privilege(): void
    {
        $auditeur = $this->avecPrivileges(['administration.audit']);
        $archive = $this->archiveEchue();

        $this->actingAs($auditeur);
        Livewire::test(SecuriteAudit::class)->call('ouvrirAction', $archive->id, 'detruire')->assertForbidden();
        Livewire::test(SecuriteAudit::class)->set('dureeDefaut', '3')->call('enregistrerDureeDefaut')->assertForbidden();

        $this->assertFalse($archive->fresh()->trashed());
    }

    public function test_les_archives_sans_echeance_sont_signalees_et_recalculables(): void
    {
        Queue::fake();
        $this->courrier(['statut' => 'archive']);

        $this->actingAs($this->avecPrivileges(['administration.audit']));
        Livewire::test(SecuriteAudit::class)->set('onglet', 'conservation')
            ->assertSee(trans_choice(':n archive n\'a pas encore d\'échéance de conservation (archivée avant cette fonctionnalité, ou recalcul de la nuit manqué).|:n archives n\'ont pas encore d\'échéance de conservation (archivées avant cette fonctionnalité, ou recalcul de la nuit manqué).', 1, ['n' => 1]))
            ->assertDontSee(__('Recalculer maintenant'))
            ->call('recalculerEcheances')
            ->assertForbidden();
        Queue::assertNotPushed(RecalculerConservationJob::class);

        $this->actingAs($this->utilisateur('Administrateur'));
        Livewire::test(SecuriteAudit::class)->set('onglet', 'conservation')
            ->assertSee(__('Recalculer maintenant'))
            ->call('recalculerEcheances');
        Queue::assertPushed(RecalculerConservationJob::class);
    }

    public function test_la_verification_manuelle_part_en_file_dattente(): void
    {
        Queue::fake();
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(SecuriteAudit::class)->call('lancerVerification');

        Queue::assertPushed(VerifierIntegriteFichiersJob::class);
    }

    // ===== Fiche : carte « Archivage et intégrité » =====

    public function test_la_fiche_dune_archive_montre_echeance_integrite_et_copie_pdfa(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));
        $archive = $this->archiveEchue(['fichier_path' => 'courriers/doc.pdf']);
        $archive->forceFill(['fichier_pdfa_path' => 'courriers/doc-pdfa.pdf'])->saveQuietly();
        EmpreinteFichier::create(['fichier_type' => EmpreinteFichier::TYPE_COURRIER, 'fichier_id' => $archive->id, 'chemin' => 'courriers/doc.pdf', 'sha256' => hash('sha256', 'original'), 'scelle_le' => now(), 'statut' => EmpreinteFichier::STATUT_OK]);

        Livewire::test(ShowCourrier::class, ['courrierId' => $archive->id])
            ->assertSee(__('Archivage et intégrité'))
            ->assertSee($archive->conserver_jusqua->format('d/m/Y'))
            ->assertSee(__('Intact'))
            ->assertSee(hash('sha256', 'original'))
            // 2026-11-09 — jamais téléchargeable.
            ->assertSee(__('Conservée sur le serveur'))
            ->assertDontSee('version=pdfa', false);
    }

    // ===== 6. Garde-fous de déploiement =====

    public function test_le_planificateur_est_signale_arrete_sans_battement_recent(): void
    {
        $this->assertFalse(EtatSysteme::planificateurActif());

        Cache::forever(EtatSysteme::CLE_BATTEMENT, now()->subMinutes(30)->toIso8601String());
        $this->assertFalse(EtatSysteme::planificateurActif());

        Cache::forever(EtatSysteme::CLE_BATTEMENT, now()->subMinutes(2)->toIso8601String());
        $this->assertTrue(EtatSysteme::planificateurActif());
    }

    public function test_le_bandeau_planificateur_arrete_ne_saffiche_quaux_auditeurs(): void
    {
        $this->actingAs($this->utilisateur('Agent'))->get(route('profile.edit'))->assertDontSee(__('Le planificateur ne tourne pas'));
        $this->actingAs($this->utilisateur('Administrateur'))->get(route('profile.edit'))->assertSee(__('Le planificateur ne tourne pas'));

        Cache::forever(EtatSysteme::CLE_BATTEMENT, now()->toIso8601String());
        $this->actingAs($this->utilisateur('Administrateur'))->get(route('profile.edit'))->assertDontSee(__('Le planificateur ne tourne pas'));
    }

    public function test_la_copie_de_secours_sur_le_meme_serveur_est_detectee(): void
    {
        config(['filesystems.disks.s3_backup.bucket' => '']);
        $this->assertSame('absente', EtatSysteme::etatSauvegarde());

        config([
            'filesystems.disks.s3.endpoint' => 'http://127.0.0.1:9000',
            'filesystems.disks.s3_backup.endpoint' => 'http://127.0.0.1:9000',
            'filesystems.disks.s3_backup.bucket' => 'gec-backup',
        ]);
        $this->assertSame('meme_serveur', EtatSysteme::etatSauvegarde());

        config(['filesystems.disks.s3_backup.endpoint' => 'https://sauvegarde.exemple.net']);
        $this->assertSame('separee', EtatSysteme::etatSauvegarde());
    }

    // Poste éteint la nuit (constaté du 30/10 au 05/11) : une tâche de 02:00
    // ou 03:00 manquée repart dans l'heure, jamais si elle a tourné récemment.
    public function test_une_tache_de_nuit_manquee_est_rattrapee(): void
    {
        Artisan::call('schedule:list');
        $evenement = fn (string $nom) => collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === $nom);

        foreach ([
            'integrite-fichiers-rattrapage' => VerifierIntegriteFichiersJob::CLE_DERNIERE_VERIFICATION,
            'conservation-archives-rattrapage' => RecalculerConservationJob::CLE_DERNIER_PASSAGE,
        ] as $nom => $cle) {
            $rattrapage = $evenement($nom);
            $this->assertNotNull($rattrapage, "{$nom} absent du planificateur");

            Cache::forget($cle);
            $this->assertTrue($rattrapage->filtersPass(app()), "{$nom} : jamais passée");

            Cache::forever($cle, now()->subHours(30)->toIso8601String());
            $this->assertTrue($rattrapage->filtersPass(app()), "{$nom} : nuit manquée");

            Cache::forever($cle, now()->subHours(2)->toIso8601String());
            $this->assertFalse($rattrapage->filtersPass(app()), "{$nom} : passée récemment");
        }

        Cache::forget(RecalculerConservationJob::CLE_DERNIER_PASSAGE);
        app()->call([new RecalculerConservationJob, 'handle']);
        $this->assertNotNull(Cache::get(RecalculerConservationJob::CLE_DERNIER_PASSAGE));
    }

    public function test_les_taches_de_conformite_sont_planifiees(): void
    {
        Artisan::call('schedule:list');
        $sortie = Artisan::output();

        foreach (['integrite-fichiers', 'conservation-archives', 'archives-pdfa', 'battement-planificateur'] as $tache) {
            $this->assertStringContainsString($tache, $sortie);
        }
    }
}
