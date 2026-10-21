<?php

namespace Tests\Feature\Services;

use App\Events\CourrierStatutChange;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
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
    // confidentialite vide immédiatement après création. "confidentialite"
    // constaté en écrivant validerService() (2026-09-21, voir
    // WorkflowService::validerService() — comparaison $confidentialite !==
    // $courrier->confidentialite déclenchait à tort une trace "modifiée"
    // pour un courrier déjà réellement 'normale' en base).
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

    // Module 1/4 — la réceptionniste transfère explicitement le courrier à
    // un destinataire choisi (2026-09-15, voir DECISIONS.md "Destinataires
    // de transfert").
    public function test_transferer_fait_passer_le_courrier_en_cours_de_transfert(): void
    {
        $agent = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id]);
        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);
        $courrier = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->workflow->transferer($courrier, $dga, $agent);

        $courrier->refresh();
        $this->assertSame('en_cours_de_transfert', $courrier->statut);
        $this->assertSame($dga->id, $courrier->destinataire_transfert_id);
        $this->assertSame(
            'transfert',
            CourrierHistorique::where('courrier_id', $courrier->id)->latest('id')->first()->action,
        );
    }

    // Module 1/4 — validation DGA/ADJ du service, demande explicite de
    // l'utilisateur (2026-09-08).

    public function test_valider_le_service_confirme_le_statut_et_applique_le_service_choisi(): void
    {
        $autreService = Service::factory()->create();
        $courrier = $this->courrier(['statut' => 'en_cours_de_transfert', 'service_id' => $autreService->id]);
        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);

        $this->workflow->validerService($courrier, $this->service->id, 1, $dga);

        $courrier->refresh();
        $this->assertSame('enregistre', $courrier->statut);
        $this->assertSame($this->service->id, $courrier->service_id);
        $this->assertSame(
            'service_valide_dga',
            CourrierHistorique::where('courrier_id', $courrier->id)->latest('id')->first()->action,
        );
    }

    // 2026-09-21 — demande explicite de l'utilisateur : le niveau de
    // confidentialité RÉELLEMENT opposable (voir CourrierPolicy::
    // niveauSuffisant()) n'est plus figé au choix de la réceptionniste —
    // la DGA peut le confirmer ou le corriger en validant le service.
    public function test_valider_le_service_applique_le_niveau_de_confidentialite_choisi_par_la_dga(): void
    {
        $courrier = $this->courrier(['statut' => 'en_cours_de_transfert', 'confidentialite' => 1]);
        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);

        $this->workflow->validerService($courrier, $this->service->id, 3, $dga);

        $this->assertSame(3, $courrier->refresh()->confidentialite);
        $this->assertSame(
            'confidentialite_modifiee_dga',
            CourrierHistorique::where('courrier_id', $courrier->id)->latest('id')->first()->action,
        );
    }

    // Si la DGA garde le niveau déjà choisi par la réceptionniste, aucune
    // trace "modifiée" bruyante ne doit apparaître.
    public function test_valider_le_service_ne_trace_rien_si_le_niveau_de_confidentialite_est_inchange(): void
    {
        $courrier = $this->courrier(['statut' => 'en_cours_de_transfert', 'confidentialite' => 2]);
        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);

        $this->workflow->validerService($courrier, $this->service->id, 2, $dga);

        $this->assertSame(2, $courrier->refresh()->confidentialite);
        $this->assertFalse(
            CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'confidentialite_modifiee_dga')->exists(),
        );
    }

    // 2026-09-15 (voir DECISIONS.md, synchronisation SRS-GEC.pdf) : le
    // service n'est plus saisi par l'agent, `service_id` vaut donc toujours
    // null avant validation — le libellé "confirmé"/"choisi (différent)"
    // compare désormais à `service_propose_id` (la suggestion du Module 3),
    // la seule référence encore pertinente, pas à `service_id`.
    public function test_valider_le_service_qui_suit_la_proposition_est_trace_comme_confirme(): void
    {
        $courrier = $this->courrier(['statut' => 'en_cours_de_transfert', 'service_id' => null, 'service_propose_id' => $this->service->id]);
        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);

        $this->workflow->validerService($courrier, $this->service->id, 1, $dga);

        $this->assertSame(
            'Service confirmé par la DGA',
            CourrierHistorique::where('courrier_id', $courrier->id)->latest('id')->first()->commentaire,
        );
    }

    public function test_valider_un_service_different_de_la_proposition_est_trace_differemment(): void
    {
        $autreService = Service::factory()->create();
        $courrier = $this->courrier(['statut' => 'en_cours_de_transfert', 'service_id' => null, 'service_propose_id' => $autreService->id]);
        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);

        $this->workflow->validerService($courrier, $this->service->id, 1, $dga);

        $this->assertSame(
            'Service choisi par la DGA (différent de la proposition)',
            CourrierHistorique::where('courrier_id', $courrier->id)->latest('id')->first()->commentaire,
        );
    }

    // Fichiers rangés sous le segment provisoire "_en_attente" (voir
    // Courrier::segmentClassement()) tant que le service était inconnu —
    // validerService() doit les déplacer vers le vrai dossier service, tant
    // le document principal que les pièces jointes, et mettre à jour
    // fichier_path en conséquence.
    public function test_valider_le_service_deplace_les_fichiers_hors_du_dossier_dattente(): void
    {
        Storage::fake('s3');

        $annee = now()->year;
        $courrier = $this->courrier([
            'statut' => 'en_cours_de_transfert',
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

        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);

        $this->workflow->validerService($courrier, $this->service->id, 1, $dga);

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
    public function test_valider_le_service_se_recale_sur_un_fichier_deja_deplace(): void
    {
        Storage::fake('s3');

        $annee = now()->year;
        $courrier = $this->courrier([
            'statut' => 'en_cours_de_transfert',
            'service_id' => null,
            'numero_reference' => "GEC-{$annee}-000043",
            'fichier_path' => "courriers/{$annee}/_en_attente/GEC-{$annee}-000043.pdf",
        ]);
        $cheminAttendu = "courriers/{$annee}/{$this->service->code}/GEC-{$annee}-000043.pdf";
        Storage::disk('s3')->put($cheminAttendu, 'déjà déplacé');

        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);
        $this->workflow->validerService($courrier, $this->service->id, 1, $dga);

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
            'statut' => 'en_cours_de_transfert',
            'service_id' => null,
            'numero_reference' => "GEC-{$annee}-000044",
            'fichier_path' => "courriers/{$annee}/_en_attente/GEC-{$annee}-000044.pdf",
        ]);
        Storage::disk('s3')->put($courrier->fichier_path, 'contenu du scan');

        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);
        $this->workflow->validerService($courrier, $this->service->id, 1, $dga);

        $cheminAttendu = "courriers/{$annee}/{$this->service->code}/GEC-{$annee}-000044.pdf";
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
            'en_cours_de_transfert → démarrer traitement (service pas encore validé)' => ['en_cours_de_transfert', 'demarrerTraitement'],
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

    public function test_transferer_diffuse_courrierstatutchange(): void
    {
        Event::fake([CourrierStatutChange::class]);

        $agent = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id]);
        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);
        $courrier = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->workflow->transferer($courrier, $dga, $agent);

        Event::assertDispatched(fn (CourrierStatutChange $e) => $e->courrierId === $courrier->id && $e->statut === 'en_cours_de_transfert');
    }

    public function test_valider_le_service_diffuse_courrierstatutchange(): void
    {
        Event::fake([CourrierStatutChange::class]);

        $courrier = $this->courrier(['statut' => 'en_cours_de_transfert', 'service_id' => null]);
        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id]);

        $this->workflow->validerService($courrier, $this->service->id, 1, $dga);

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
