<?php

namespace Tests\Feature\Services;

use App\Events\CourrierStatutChange;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\TransfertService;
use App\Models\User;
use App\Notifications\CourrierNotification;
use App\Services\WorkflowService;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowService $workflow;

    private Service $service;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workflow = new WorkflowService;
        $this->responsable = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Responsable de service'])->id]);
        $this->service = Service::factory()->create(['responsable_id' => $this->responsable->id]);
    }

    private function collaborateur(): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Collaborateur'])->id,
            'service_id' => $this->service->id,
        ]);
    }

    // "statut"/"confidentialite" ont un défaut en base (migration), mais un
    // Model::create() ne relit pas les valeurs par défaut du SGBD dans
    // l'instance en mémoire — les expliciter ici évite un $courrier->statut/
    // confidentialite vide immédiatement après création.
    private function courrier(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'confidentialite' => 1,
            'service_id' => $this->service->id,
            'statut' => 'enregistre',
        ], $attributs));
    }

    public function test_le_cycle_complet_du_circuit_generique_fonctionne(): void
    {
        $collaborateur = $this->collaborateur();
        $courrier = $this->courrier();

        $this->workflow->affecter($courrier, $collaborateur, $this->responsable);
        $this->assertSame('affecte', $courrier->refresh()->statut);
        $this->assertSame($collaborateur->id, $courrier->affectationCourante->user_id);

        $this->workflow->demarrerTraitement($courrier, $collaborateur);
        $this->assertSame('en_traitement', $courrier->refresh()->statut);

        $this->workflow->soumettrePourValidation($courrier, $collaborateur, 'Réponse envoyée.');
        $this->assertSame('en_validation', $courrier->refresh()->statut);

        $this->workflow->valider($courrier, $this->responsable);
        $this->assertSame('traite', $courrier->refresh()->statut);

        $this->assertSame(
            ['affectation', 'traitement_demarre', 'soumis_validation', 'validation_acceptee'],
            CourrierHistorique::where('courrier_id', $courrier->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_le_responsable_ne_recoit_pas_de_notification_pour_ses_propres_actions_de_traitement(): void
    {
        Notification::fake();

        $courrier = $this->courrier();

        $this->workflow->affecter($courrier, $this->responsable, $this->responsable);
        $this->workflow->demarrerTraitement($courrier, $this->responsable);
        $this->workflow->soumettrePourValidation($courrier, $this->responsable);
        $this->workflow->valider($courrier, $this->responsable);

        Notification::assertNothingSent();
    }

    public function test_les_notifications_du_circuit_restent_envoyees_aux_autres_intervenants(): void
    {
        Notification::fake();

        $collaborateur = $this->collaborateur();
        $courrier = $this->courrier();

        $this->workflow->affecter($courrier, $collaborateur, $this->responsable);
        $this->workflow->demarrerTraitement($courrier, $collaborateur);
        $this->workflow->soumettrePourValidation($courrier, $collaborateur);
        $this->workflow->valider($courrier, $this->responsable);

        Notification::assertSentTo(
            $collaborateur,
            CourrierNotification::class,
            fn (CourrierNotification $notification) => $notification->action === 'affectation',
        );
        Notification::assertSentTo(
            $this->responsable,
            CourrierNotification::class,
            fn (CourrierNotification $notification) => $notification->action === 'soumis_validation',
        );
        Notification::assertSentTo(
            $collaborateur,
            CourrierNotification::class,
            fn (CourrierNotification $notification) => $notification->action === 'validation_acceptee',
        );
    }

    public function test_un_renvoi_pour_correction_repasse_en_traitement_avec_motif_trace(): void
    {
        $collaborateur = $this->collaborateur();
        $courrier = $this->courrier(['statut' => 'en_validation']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id]);

        $this->workflow->renvoyerPourCorrection($courrier, $this->responsable, 'Pièce manquante.');

        $this->assertSame('en_traitement', $courrier->refresh()->statut);
        $this->assertSame('Pièce manquante.', CourrierHistorique::where('courrier_id', $courrier->id)->latest('id')->first()->commentaire);
    }

    public function test_mise_en_attente_puis_reprise(): void
    {
        $courrier = $this->courrier(['statut' => 'en_traitement']);

        $this->workflow->mettreEnAttente($courrier, $this->responsable, 'En attente de pièce complémentaire.');
        $this->assertSame('en_attente_information', $courrier->refresh()->statut);

        $this->workflow->reprendre($courrier, $this->responsable);
        $this->assertSame('en_traitement', $courrier->refresh()->statut);
    }

    public function test_le_rejet_est_trace_avec_son_motif(): void
    {
        $courrier = $this->courrier(['statut' => 'affecte']);

        $this->workflow->rejeter($courrier, $this->responsable, 'Courrier hors périmètre.');

        $this->assertSame('rejete', $courrier->refresh()->statut);
        $this->assertSame('Courrier hors périmètre.', CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'rejet')->first()->commentaire);
    }

    // Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service
    // et acceptation") — plus de DGA : l'accueil transfère au service, qui
    // accepte (décharge numérotée) ou refuse avec motif.
    private function agent(): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id]);
    }

    public function test_transferer_au_service_met_le_courrier_en_attente_dacceptation(): void
    {
        $agent = $this->agent();
        $courrier = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $transfert = $this->workflow->transfererAuService($courrier, $this->service, $agent);

        $courrier->refresh();
        $this->assertSame('en_cours_de_transfert', $courrier->statut);
        $this->assertSame($this->service->id, $courrier->service_id);
        $this->assertSame(TransfertService::EN_ATTENTE, $transfert->statut);
        $this->assertSame($agent->id, $transfert->transfere_par_id);
        $this->assertSame(
            'transfert',
            CourrierHistorique::where('courrier_id', $courrier->id)->latest('id')->first()->action,
        );
    }

    public function test_transferer_a_un_service_sans_responsable_est_refuse(): void
    {
        $courrier = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $serviceSansResponsable = Service::factory()->create(['responsable_id' => null]);

        try {
            $this->workflow->transfererAuService($courrier, $serviceSansResponsable, $this->agent());
            $this->fail('Un service sans responsable ne doit jamais recevoir de transfert.');
        } catch (RuntimeException) {
            $this->assertSame('en_attente_de_transfert', $courrier->refresh()->statut);
            $this->assertSame(0, TransfertService::count());
        }
    }

    public function test_accepter_le_transfert_attribue_un_numero_de_decharge(): void
    {
        $courrier = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $this->workflow->transfererAuService($courrier, $this->service, $this->agent());

        $transfert = $this->workflow->accepterTransfert($courrier->fresh(), $this->responsable);

        $this->assertSame('enregistre', $courrier->refresh()->statut);
        $this->assertSame(TransfertService::ACCEPTE, $transfert->statut);
        $this->assertSame($this->responsable->id, $transfert->decide_par_id);
        $this->assertSame('ACC-'.now()->year.'-'.str_pad((string) $transfert->id, 6, '0', STR_PAD_LEFT), $transfert->numero_decharge);
        $this->assertSame(
            'transfert_accepte',
            CourrierHistorique::where('courrier_id', $courrier->id)->latest('id')->first()->action,
        );
    }

    public function test_refuser_le_transfert_renvoie_le_courrier_a_laccueil_sans_service(): void
    {
        $courrier = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $this->workflow->transfererAuService($courrier, $this->service, $this->agent());

        $transfert = $this->workflow->refuserTransfert($courrier->fresh(), $this->responsable, 'Pas pour notre service.');

        $courrier->refresh();
        $this->assertSame('en_attente_de_transfert', $courrier->statut);
        $this->assertNull($courrier->service_id);
        $this->assertSame(TransfertService::REFUSE, $transfert->statut);
        $this->assertSame('Pas pour notre service.', $transfert->motif_refus);
        $this->assertSame(
            'transfert_refuse',
            CourrierHistorique::where('courrier_id', $courrier->id)->latest('id')->first()->action,
        );
    }

    // Une décision ne se prend qu'une fois : un second clic (autre onglet,
    // double soumission) ne doit jamais refuser après coup.
    public function test_un_transfert_deja_accepte_ne_peut_plus_etre_refuse(): void
    {
        $courrier = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $this->workflow->transfererAuService($courrier, $this->service, $this->agent());
        $this->workflow->accepterTransfert($courrier->fresh(), $this->responsable);

        $this->expectException(RuntimeException::class);

        $this->workflow->refuserTransfert($courrier->fresh(), $this->responsable, 'Trop tard.');
    }

    // Fichiers rangés sous le segment provisoire "_en_attente" tant que le
    // service est inconnu : l'ACCEPTATION les déplace vers le dossier du
    // service (document principal et pièces jointes) — jamais le simple
    // transfert, qu'un refus pourrait annuler.
    public function test_accepter_le_transfert_deplace_les_fichiers_hors_du_dossier_dattente(): void
    {
        Storage::fake('s3');

        $annee = now()->year;
        $courrier = $this->courrier([
            'statut' => 'en_attente_de_transfert',
            'service_id' => null,
            'numero_reference' => "GEC-{$annee}-000042",
            'fichier_path' => "courriers/{$annee}/_en_attente/GEC-{$annee}-000042.pdf",
        ]);
        Storage::disk('s3')->put($courrier->fichier_path, 'contenu du scan');

        $pieceJointe = $courrier->piecesJointes()->create([
            'fichier_path' => "courriers/{$annee}/_en_attente/GEC-{$annee}-000042/piece.pdf",
            'nom_original' => 'piece.pdf',
            'type_mime' => 'application/pdf',
            'taille' => 10,
        ]);
        Storage::disk('s3')->put($pieceJointe->fichier_path, 'contenu de la piece');

        $this->workflow->transfererAuService($courrier, $this->service, $this->agent());
        $this->assertSame("courriers/{$annee}/_en_attente/GEC-{$annee}-000042.pdf", $courrier->refresh()->fichier_path, 'le transfert seul ne déplace rien');

        $this->workflow->accepterTransfert($courrier, $this->responsable);

        $courrier->refresh();
        $pieceJointe->refresh();

        $cheminAttendu = "courriers/{$annee}/{$this->service->code}/GEC-{$annee}-000042.pdf";
        $this->assertSame($cheminAttendu, $courrier->fichier_path);
        Storage::disk('s3')->assertExists($cheminAttendu);
        Storage::disk('s3')->assertMissing("courriers/{$annee}/_en_attente/GEC-{$annee}-000042.pdf");

        $cheminPieceAttendu = "courriers/{$annee}/{$this->service->code}/GEC-{$annee}-000042/piece.pdf";
        $this->assertSame($cheminPieceAttendu, $pieceJointe->fichier_path);
        Storage::disk('s3')->assertExists($cheminPieceAttendu);
    }

    // Bug réel (2026-09-24, GEC-2026-000003) : fichier déjà dans le dossier
    // du service, fichier_path resté sous _en_attente → aperçu en erreur 500.
    public function test_accepter_le_transfert_se_recale_sur_un_fichier_deja_deplace(): void
    {
        Storage::fake('s3');

        $annee = now()->year;
        $courrier = $this->courrier([
            'statut' => 'en_attente_de_transfert',
            'service_id' => null,
            'numero_reference' => "GEC-{$annee}-000043",
            'fichier_path' => "courriers/{$annee}/_en_attente/GEC-{$annee}-000043.pdf",
        ]);
        $cheminAttendu = "courriers/{$annee}/{$this->service->code}/GEC-{$annee}-000043.pdf";
        Storage::disk('s3')->put($cheminAttendu, 'déjà déplacé');

        $this->workflow->transfererAuService($courrier, $this->service, $this->agent());
        $this->workflow->accepterTransfert($courrier->fresh(), $this->responsable);

        $this->assertSame($cheminAttendu, $courrier->refresh()->fichier_path);
    }

    // Un échec de la COPIE DE SECOURS ne doit jamais laisser fichier_path
    // sur l'ancien chemin alors que le fichier principal a bien été déplacé.
    public function test_un_echec_de_la_copie_de_secours_met_quand_meme_a_jour_le_chemin(): void
    {
        Storage::fake('s3');
        config(['filesystems.disks.s3_backup.bucket' => 'gec-backup']);
        $secours = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $secours->shouldReceive('exists')->andReturn(true);
        $secours->shouldReceive('move')->andThrow(new RuntimeException('stockage de secours indisponible'));
        Storage::set('s3_backup', $secours);

        $annee = now()->year;
        $courrier = $this->courrier([
            'statut' => 'en_attente_de_transfert',
            'service_id' => null,
            'numero_reference' => "GEC-{$annee}-000044",
            'fichier_path' => "courriers/{$annee}/_en_attente/GEC-{$annee}-000044.pdf",
        ]);
        Storage::disk('s3')->put($courrier->fichier_path, 'contenu du scan');

        $this->workflow->transfererAuService($courrier, $this->service, $this->agent());
        $this->workflow->accepterTransfert($courrier->fresh(), $this->responsable);

        $cheminAttendu = "courriers/{$annee}/{$this->service->code}/GEC-{$annee}-000044.pdf";
        $this->assertSame($cheminAttendu, $courrier->refresh()->fichier_path);
        Storage::disk('s3')->assertExists($cheminAttendu);
    }

    // Un fichier déjà rangé sous le dossier d'un AUTRE service (re-numérisé
    // pendant un transfert ensuite refusé) rejoint aussi le service qui
    // accepte — plus seulement depuis "_en_attente" (Règle n°4).
    public function test_accepter_le_transfert_deplace_un_fichier_range_sous_un_autre_service(): void
    {
        Storage::fake('s3');

        $annee = now()->year;
        $courrier = $this->courrier([
            'statut' => 'en_attente_de_transfert',
            'service_id' => null,
            'numero_reference' => "GEC-{$annee}-000045",
            'fichier_path' => "courriers/{$annee}/AUT/GEC-{$annee}-000045.pdf",
        ]);
        Storage::disk('s3')->put($courrier->fichier_path, 'contenu du scan');

        $this->workflow->transfererAuService($courrier, $this->service, $this->agent());
        $this->workflow->accepterTransfert($courrier->fresh(), $this->responsable);

        $cheminAttendu = "courriers/{$annee}/{$this->service->code}/GEC-{$annee}-000045.pdf";
        $this->assertSame($cheminAttendu, $courrier->refresh()->fichier_path);
        Storage::disk('s3')->assertExists($cheminAttendu);
    }

    public function test_une_reaffectation_cree_une_nouvelle_ligne_sans_changer_le_statut(): void
    {
        $premier = $this->collaborateur();
        $second = $this->collaborateur();
        $courrier = $this->courrier(['statut' => 'en_traitement']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $premier->id]);

        $this->workflow->reaffecter($courrier, $second, $this->responsable, 'Absence du premier collaborateur.');

        $this->assertSame('en_traitement', $courrier->refresh()->statut, 'la réaffectation ne modifie pas le statut du circuit');
        $this->assertSame($second->id, $courrier->affectationCourante->user_id);
        $this->assertSame(2, Affectation::where('courrier_id', $courrier->id)->count(), 'append-only : aucune ligne mise à jour');
    }

    #[DataProvider('transitionsInvalidesProvider')]
    public function test_une_transition_hors_machine_a_etats_est_refusee(string $statutInitial, string $methode): void
    {
        $courrier = $this->courrier(['statut' => $statutInitial]);
        $collaborateur = $this->collaborateur();

        $this->expectException(RuntimeException::class);

        match ($methode) {
            'demarrerTraitement' => $this->workflow->demarrerTraitement($courrier, $collaborateur),
            'valider' => $this->workflow->valider($courrier, $this->responsable),
            'reprendre' => $this->workflow->reprendre($courrier, $collaborateur),
        };

        $this->assertSame($statutInitial, $courrier->refresh()->statut, 'aucun changement ne doit avoir été persisté');
    }

    public static function transitionsInvalidesProvider(): array
    {
        return [
            'traité → démarrer traitement' => ['traite', 'demarrerTraitement'],
            'enregistré → valider (pas encore affecté)' => ['enregistre', 'valider'],
            'en_traitement → reprendre (pas en attente)' => ['en_traitement', 'reprendre'],
            'en_attente_de_transfert → démarrer traitement (pas encore transféré)' => ['en_attente_de_transfert', 'demarrerTraitement'],
            'en_cours_de_transfert → démarrer traitement (pas encore accepté)' => ['en_cours_de_transfert', 'demarrerTraitement'],
        ];
    }

    // Module 4 — demande explicite de l'utilisateur : "make the parcours du
    // courier work live in real time at each step" — CourrierStatutChange
    // doit être diffusé à CHAQUE transition réelle de statut, pas seulement
    // pour l'auteur de l'action (voir ShowCourrier::statutChange(), le
    // listener côté fiche).
    public function test_chaque_transition_de_statut_diffuse_courrierstatutchange(): void
    {
        Event::fake([CourrierStatutChange::class]);

        $collaborateur = $this->collaborateur();
        $courrier = $this->courrier();

        $this->workflow->affecter($courrier, $collaborateur, $this->responsable);
        Event::assertDispatched(fn (CourrierStatutChange $e) => $e->courrierId === $courrier->id && $e->statut === 'affecte');

        $this->workflow->demarrerTraitement($courrier, $collaborateur);
        Event::assertDispatched(fn (CourrierStatutChange $e) => $e->statut === 'en_traitement');

        $this->workflow->soumettrePourValidation($courrier, $collaborateur);
        Event::assertDispatched(fn (CourrierStatutChange $e) => $e->statut === 'en_validation');

        $this->workflow->valider($courrier, $this->responsable);
        Event::assertDispatched(fn (CourrierStatutChange $e) => $e->statut === 'traite');

        Event::assertDispatchedTimes(CourrierStatutChange::class, 4);
    }

    public function test_transferer_et_accepter_diffusent_courrierstatutchange(): void
    {
        Event::fake([CourrierStatutChange::class]);

        $courrier = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->workflow->transfererAuService($courrier, $this->service, $this->agent());
        Event::assertDispatched(fn (CourrierStatutChange $e) => $e->courrierId === $courrier->id && $e->statut === 'en_cours_de_transfert');

        $this->workflow->accepterTransfert($courrier->fresh(), $this->responsable);
        Event::assertDispatched(fn (CourrierStatutChange $e) => $e->courrierId === $courrier->id && $e->statut === 'enregistre');
    }

    // Constaté par l'utilisateur le 2026-09-18 : en local, le serveur Reverb
    // n'est pas toujours joignable — `CourrierStatutChange` (ShouldBroadcastNow,
    // diffusion synchrone) lève alors une `BroadcastException`, qui ÉTEND
    // `RuntimeException`. Sans garde-fou, cette exception remontait jusqu'à
    // ShowCourrier::executer() et était affichée à l'utilisateur comme "la
    // fiche a changé entre-temps" — alors que la transition avait réellement
    // réussi (déjà commitée en base avant l'appel de diffusion). Ce test
    // simule cet échec de diffusion directement au niveau du driver.
    public function test_un_echec_de_diffusion_temps_reel_n_empeche_pas_la_transition(): void
    {
        Broadcast::extend('throwing', fn () => new class implements Broadcaster
        {
            public function auth($request) {}

            public function validAuthenticationResponse($request, $result) {}

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new BroadcastException('Connexion Reverb indisponible (simulation de test).');
            }
        });
        config(['broadcasting.default' => 'throwing']);

        $collaborateur = $this->collaborateur();
        $courrier = $this->courrier();

        // Ne doit pas lever d'exception malgré l'échec de la diffusion.
        $this->workflow->affecter($courrier, $collaborateur, $this->responsable);

        $this->assertSame('affecte', $courrier->refresh()->statut, 'la transition doit réussir même si la diffusion temps réel échoue');
    }

    public function test_la_charge_par_collaborateur_ne_compte_que_les_courriers_actifs(): void
    {
        $charge = $this->collaborateur();
        $libre = $this->collaborateur();

        Affectation::create(['courrier_id' => $this->courrier(['statut' => 'en_traitement'])->id, 'user_id' => $charge->id]);
        Affectation::create(['courrier_id' => $this->courrier(['statut' => 'affecte'])->id, 'user_id' => $charge->id]);
        Affectation::create(['courrier_id' => $this->courrier(['statut' => 'traite'])->id, 'user_id' => $charge->id]); // clôturé, ne compte pas

        $resultat = $this->workflow->chargeParCollaborateur($this->service->id);

        $this->assertSame(2, $resultat[$charge->id]);
        $this->assertArrayNotHasKey($libre->id, $resultat);
    }

    // Module 9 — "Dossiers & Archives" (2026-09-21) : "Traité" → "Archivé"
    // devient enfin une vraie transition, déclenchée uniquement par
    // ArchiverCourriersTraitesJob (jamais un bouton).
    public function test_archiver_automatiquement_transite_traite_vers_archive(): void
    {
        $courrier = $this->courrier(['statut' => 'traite']);

        $this->workflow->archiverAutomatiquement($courrier);

        $this->assertSame('archive', $courrier->refresh()->statut);
        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'auteur_id' => null,
            'action' => 'archivage_automatique',
        ]);
    }

    public function test_archiver_automatiquement_refuse_depuis_un_autre_statut(): void
    {
        $courrier = $this->courrier(['statut' => 'affecte']);

        $this->expectException(RuntimeException::class);

        $this->workflow->archiverAutomatiquement($courrier);
    }
}
