<?php

namespace Tests\Feature\Courriers;

use App\Jobs\IndexCourrierJob;
use App\Jobs\ProcessBrouillonOcr;
use App\Jobs\ReplicateFichierJob;
use App\Livewire\Backend\RegistrationForm;
use App\Models\Courrier;
use App\Models\CourrierBrouillon;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\RegleClassement;
use App\Models\Service;
use App\Models\TransfertService;
use App\Models\User;
use App\Notifications\CourrierNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationFormTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(['profil_id' => $profil->id]);
    }

    // Module 1 — "Type de document" en liste déroulante (demande explicite
    // de l'utilisateur, 2026-09-08), avec bascule vers un champ libre pour
    // "Autre" plutôt qu'une liste fermée qui empêcherait de préciser un cas
    // non prévu.

    public function test_choisir_autre_dans_la_liste_bascule_vers_un_champ_libre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->assertSet('typeDocumentPersonnalise', false)
            ->set('form.type_document', '__autre__')
            ->assertSet('typeDocumentPersonnalise', true)
            ->assertSet('form.type_document', '');
    }

    public function test_un_type_de_document_personnalise_peut_etre_enregistre(): void
    {
        // Volontairement PAS de Rule::in() sur type_document (voir
        // CourrierForm) : la liste ne restreint que le choix à la saisie,
        // jamais la donnée elle-même.
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'entrant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Cas particulier')
            ->set('form.type_document', '__autre__')
            ->set('form.type_document', 'Attestation de non-gage')
            ->set('form.mode_reception', 'email')
            ->set('form.priorite', 'normale')
            ->set('form.confidentialite', 1)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('courriers', ['type_document' => 'Attestation de non-gage']);
    }

    public function test_choisir_dans_la_liste_revient_depuis_le_champ_libre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.type_document', '__autre__')
            ->set('form.type_document', 'Un cas particulier')
            ->call('choisirTypeDocumentDansLaListe')
            ->assertSet('typeDocumentPersonnalise', false)
            ->assertSet('form.type_document', '');
    }

    public function test_un_agent_peut_enregistrer_un_courrier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);

        $this->actingAs($agent);

        $response = Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'entrant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Réclamation client')
            ->set('form.type_document', 'Réclamation')
            ->set('form.mode_reception', 'email')
            ->set('form.expediteur_nom', 'Jean Dupont')
            ->set('form.priorite', 'haute')
            ->set('form.confidentialite', 1)
            ->call('enregistrer');

        $response->assertHasNoErrors();

        $this->assertDatabaseCount('courriers', 1);

        $courrier = Courrier::firstOrFail();

        $this->assertMatchesRegularExpression(
            '/^GEC-'.now()->year.'-\d{6}$/',
            $courrier->numero_reference,
        );
        // "Réclamation" n'est pas un sinistre : un courrier ENTRANT attend
        // que l'accueil le transfère au service concerné (2026-10-23, voir
        // DECISIONS.md "Transfert direct au service et acceptation").
        $this->assertSame('en_attente_de_transfert', $courrier->statut);
        // Le service saisi ici (form.service_id ci-dessus) est ignoré pour un
        // courrier ENTRANT — il est choisi au moment du transfert.
        $this->assertNull($courrier->service_id);

        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)
            ->where('action', 'creation')
            ->where('auteur_id', $agent->id)
            ->count());
    }

    // Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service
    // et acceptation") — un sinistre est transféré AUTOMATIQUEMENT au service
    // proposé par les règles de classement (donnée de configuration, pas de
    // code), puis attend son acceptation comme tout autre courrier.
    public function test_un_sinistre_est_transfere_automatiquement_au_service_propose(): void
    {
        Notification::fake();
        $agent = $this->utilisateurAvecProfil('Agent');
        $responsableDsin = $this->utilisateurAvecProfil('Responsable de service');
        $dsin = Service::factory()->create(['code' => 'DSIN', 'responsable_id' => $responsableDsin->id]);
        RegleClassement::factory()->create([
            'mots_cles' => ['sinistre'],
            'service_propose_id' => $dsin->id,
        ]);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'entrant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.objet', 'Déclaration de sinistre')
            ->set('form.type_document', 'Sinistre')
            ->set('form.sous_type_sinistre', 'materiel')
            ->set('form.mode_reception', 'email')
            ->set('form.priorite', 'normale')
            ->set('form.confidentialite', 1)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $courrier = Courrier::firstOrFail();
        $this->assertSame('en_cours_de_transfert', $courrier->statut);
        $this->assertSame($dsin->id, $courrier->service_id);
        $this->assertSame(TransfertService::EN_ATTENTE, $courrier->dernierTransfert->statut);
        $this->assertSame($agent->id, $courrier->dernierTransfert->transfere_par_id);
        $this->assertTrue(CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'transfert')->exists());
        Notification::assertSentTo($responsableDsin, CourrierNotification::class, fn ($n) => $n->action === 'transfert' && $n->envoyePar?->id === $agent->id);
    }

    // Filet de sécurité : si la classification ne résout aucun service pour
    // un sinistre (règle absente ou mal configurée), le courrier reste "à
    // transférer" — jamais un courrier orphelin (Module 6).
    public function test_un_sinistre_sans_regle_de_classement_reste_a_transferer(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'entrant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.objet', 'Déclaration de sinistre')
            ->set('form.type_document', 'Sinistre')
            ->set('form.sous_type_sinistre', 'materiel')
            ->set('form.mode_reception', 'email')
            ->set('form.priorite', 'normale')
            ->set('form.confidentialite', 1)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $courrier = Courrier::firstOrFail();
        $this->assertSame('en_attente_de_transfert', $courrier->statut);
        $this->assertNull($courrier->service_id);
    }

    // Règle n°6 — un service forgé dans form.service_id (requête Livewire
    // manipulée) n'est jamais persisté pour un courrier ENTRANT : il ne peut
    // pas sauter le transfert ni l'acceptation par le service.
    public function test_un_service_force_pour_un_entrant_est_ignore(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DI']);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'entrant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.objet', 'Tentative de contournement')
            ->set('form.type_document', 'Lettre')
            ->set('form.mode_reception', 'email')
            ->set('form.priorite', 'normale')
            ->set('form.confidentialite', 1)
            ->set('form.service_id', $service->id)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $courrier = Courrier::firstOrFail();
        $this->assertSame('en_attente_de_transfert', $courrier->statut);
        $this->assertNull($courrier->service_id);
    }

    // Le sélecteur de service (cascade Site/Département/Service) ne s'affiche
    // que pour un courrier SORTANT ; un entrant choisit son service au
    // moment du transfert.
    public function test_le_selecteur_de_service_napparait_que_pour_un_sortant(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'entrant')
            ->assertSee('Le service sera choisi au moment du transfert')
            ->assertDontSee('wire:model.live="departementSelectionneId"', false)
            ->set('form.sens', 'sortant')
            ->assertDontSee('Le service sera choisi au moment du transfert');
    }

    public function test_un_courrier_sortant_non_sinistre_saute_la_validation_dga(): void
    {
        // Portée explicitement limitée à l'entrant pour l'instant — le
        // sortant garde le circuit actuel inchangé.
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Réponse à un client')
            ->set('form.type_document', 'Lettre')
            ->set('form.mode_reception', 'email')
            ->set('form.priorite', 'normale')
            ->set('form.confidentialite', 1)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame('enregistre', $service->courriers()->firstOrFail()->statut);
    }

    public function test_le_sous_type_sinistre_est_requis_pour_un_type_sinistre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $this->actingAs($agent);

        // "SINISTRE" en capitales : comparaison insensible à la casse et aux
        // accents, cohérente avec ClassificationService::normaliser().
        Livewire::test(RegistrationForm::class)
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Accident de la route')
            ->set('form.type_document', 'SINISTRE')
            ->call('enregistrer')
            ->assertHasErrors(['form.sous_type_sinistre' => 'required']);

        $this->assertDatabaseCount('courriers', 0);
    }

    public function test_le_sous_type_sinistre_est_enregistre_et_ignore_hors_sinistre(): void
    {
        // sens=sortant : ce test porte sur la persistance de sous_type_sinistre,
        // pas sur le routage entrant/DGA (voir test_un_courrier_sinistre_confirme_reste_enregistre_directement
        // pour ce cas) — sortant garde un service requis, permet de fetcher
        // via $service->courriers() comme avant.
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Accident de la route')
            ->set('form.type_document', 'Sinistre')
            ->set('form.sous_type_sinistre', 'materiel')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame('materiel', $service->courriers()->firstOrFail()->sous_type_sinistre);
    }

    // 2026-10-07 — "Référence externe" (V/Réf, V/DOS...) demandée explicitement
    // dans le formulaire d'enregistrement, mais UNIQUEMENT pour un sinistre
    // (voir RegistrationForm::$referenceExterne) : un vrai courrier "Cabinet
    // LADO Assistance" cite systématiquement sa propre référence de dossier,
    // utile pour rapprocher des échanges ultérieurs sur le même sinistre.
    public function test_la_reference_externe_est_enregistree_pour_un_sinistre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Accident de la route')
            ->set('form.type_document', 'Sinistre')
            ->set('form.sous_type_sinistre', 'materiel')
            ->set('referenceExterne', 'NSIA/DCO/DS/DNCF/.......07/26')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame('NSIA/DCO/DS/DNCF/.......07/26', $service->courriers()->firstOrFail()->reference_externe);
    }

    // Garde-fou — champ masqué hors sinistre (voir registrationForm.blade.php,
    // $form->estUnSinistre()) : une valeur laissée en mémoire Livewire après
    // que l'agent soit revenu sur un type non-sinistre ne doit jamais être
    // enregistrée malgré elle.
    public function test_la_reference_externe_est_ignoree_hors_sinistre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Réponse à un client')
            ->set('form.type_document', 'Lettre')
            ->set('referenceExterne', 'Une valeur qui ne devrait jamais être enregistrée')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertNull($service->courriers()->firstOrFail()->reference_externe);
    }

    public function test_une_piece_jointe_est_stockee_sur_le_disque_s3(): void
    {
        Storage::fake('s3');
        Queue::fake([ReplicateFichierJob::class]);

        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);

        $this->actingAs($agent);

        $response = Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier avec pièce jointe')
            ->set('form.type_document', 'Lettre')
            ->set('nouvellesPiecesJointes', [UploadedFile::fake()->create('copie.pdf', 100, 'application/pdf')])
            ->call('enregistrer');

        $response->assertHasNoErrors();

        $courrier = $service->courriers()->firstOrFail();
        $pieceJointe = $courrier->piecesJointes()->firstOrFail();

        $this->assertSame('copie.pdf', $pieceJointe->nom_original);
        $this->assertStringStartsWith("courriers/{$this->anneeCourante()}/DIR/{$courrier->numero_reference}/", $pieceJointe->fichier_path);
        Storage::disk('s3')->assertExists($pieceJointe->fichier_path);

        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)
            ->where('action', 'ajout_piece_jointe')
            ->count());

        // Règle n°4 (complétée) — la copie de secours part en Job, jamais dans
        // le cycle requête/réponse (Règle n°1).
        Queue::assertPushedOn('replication', ReplicateFichierJob::class, fn (ReplicateFichierJob $job) => $job->chemin === $pieceJointe->fichier_path);
    }

    public function test_lenregistrement_reussit_sans_piece_jointe(): void
    {
        Storage::fake('s3');

        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier sans pièce jointe')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame(0, $service->courriers()->firstOrFail()->piecesJointes()->count());
    }

    // Module "Organisation" v2 (2026-09-22, spec §16) — remplace
    // test_le_service_est_preselectionne_si_un_seul_service_existe :
    // l'ancien raccourci "un seul service au total -> présélectionné" ne
    // s'applique plus tel quel à une cascade à plusieurs niveaux (un seul
    // Site ET un seul Département ET un seul Service ne sont pas la même
    // chose que "un seul service" dans l'ancien modèle plat) — retiré plutôt
    // que fabriqué. Ce test vérifie à la place que la cascade
    // Site → Département résout bien un vrai service_id (spec §1 : un
    // Département peut être la destination finale sans Service en dessous).
    public function test_la_cascade_site_departement_resout_le_service_id(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);
        $departement = OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'parent_id' => $site->id,
            'service_id' => $service->id,
        ]);

        $this->actingAs($agent);

        $response = Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('siteSelectionneId', $site->id)
            ->set('departementSelectionneId', $departement->id)
            ->set('form.objet', 'Courrier de test')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer');

        $response->assertHasNoErrors();

        $this->assertDatabaseHas('courriers', ['service_id' => $service->id]);
    }

    // 2026-09-23, clarification explicite de l'utilisateur (cas réel : un
    // Département RACINE — parent_id null — existe déjà en base sans Site
    // au-dessus, faute de nom de site réel confirmé) : la cascade doit
    // rester utilisable pour un courrier sortant même sans Site choisi.
    public function test_la_cascade_resout_le_service_id_via_un_departement_racine_sans_site(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DSIN']);
        $departementRacine = OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'parent_id' => null,
            'service_id' => $service->id,
        ]);

        $this->actingAs($agent);

        $response = Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->assertSet('siteSelectionneId', null)
            ->set('departementSelectionneId', $departementRacine->id)
            ->set('form.objet', 'Courrier de test')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer');

        $response->assertHasNoErrors();

        $this->assertDatabaseHas('courriers', ['service_id' => $service->id]);
    }

    public function test_lobjet_recoit_un_texte_generique_quand_le_courrier_devient_confidentiel(): void
    {
        // Demande explicite de l'utilisateur (2026-09-07), après discussion
        // avec la réception : un courrier confidentiel n'est jamais ouvert
        // par l'agent qui l'enregistre — l'objet réel n'est donc jamais
        // connu. Objet reste obligatoire (règle métier Module 1), mais un
        // texte générique lui est imposé dès que Confidentialité passe à
        // confidentiel/très confidentiel, tant que l'agent n'a rien saisi.
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->assertSet('form.objet', '')
            ->set('form.confidentialite', 2)
            ->assertSet('form.objet', 'Correspondance confidentielle (non ouverte)')
            ->assertSee('l\'agent qui enregistre');
    }

    public function test_le_texte_generique_confidentiel_necrase_pas_un_objet_deja_saisi(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.objet', 'Réclamation sinistre auto')
            ->set('form.confidentialite', 3)
            ->assertSet('form.objet', 'Réclamation sinistre auto');
    }

    // Bug réel trouvé le 2026-09-10 (revue de code) : un objet extrait par
    // OCR (flux scan-first) restait affiché/enregistré tel quel après
    // bascule en confidentiel, car preremplirDepuisBrouillon() le pré-remplit
    // AVANT que l'agent choisisse la confidentialité, et l'ancien garde-fou
    // ("écraser seulement si vide") ne s'appliquait donc jamais — l'objet
    // réel du courrier était censé rester inconnu à ce stade (2026-09-07).
    public function test_lobjet_extrait_par_ocr_est_ecrase_quand_le_courrier_devient_confidentiel(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "Objet : Réclamation sinistre auto véhicule immatriculé\n",
        ]);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.objet', 'Réclamation sinistre auto véhicule immatriculé')
            ->set('form.confidentialite', 2)
            ->assertSet('form.objet', 'Correspondance confidentielle (non ouverte)');
    }

    // Contre-exemple : si l'agent modifie l'objet proposé par l'OCR AVANT de
    // basculer en confidentiel, sa saisie manuelle n'est pas écrasée — même
    // principe que test_le_texte_generique_confidentiel_necrase_pas_un_objet_deja_saisi.
    public function test_un_objet_ocr_modifie_par_lagent_nest_pas_ecrase_en_confidentiel(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "Objet : Réclamation sinistre auto\n",
        ]);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->set('form.objet', 'Objet corrigé par l\'agent')
            ->set('form.confidentialite', 2)
            ->assertSet('form.objet', 'Objet corrigé par l\'agent');
    }

    public function test_le_service_nest_pas_preselectionne_si_plusieurs_services_existent(): void
    {
        // sens=sortant : le service reste requis uniquement pour le sortant
        // depuis le 2026-09-15 (voir DECISIONS.md) — pour un entrant, ne pas
        // choisir de service n'est plus une erreur du tout.
        $agent = $this->utilisateurAvecProfil('Agent');
        Service::factory()->create(['code' => 'DIR']);
        Service::factory()->create(['code' => 'FIN']);

        $this->actingAs($agent);

        $response = Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.objet', 'Courrier de test')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer');

        $response->assertHasErrors(['form.service_id' => 'required']);
    }

    // 2026-09-15 (voir DECISIONS.md, synchronisation SRS-GEC.pdf) : pour un
    // courrier ENTRANT, ne pas choisir de service n'est PLUS une erreur — le
    // champ n'existe même plus dans le formulaire, c'est le DGA/ADJ DGA qui
    // le choisira au moment de valider le transfert.
    public function test_labsence_de_service_nest_pas_une_erreur_pour_un_courrier_entrant(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'entrant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.objet', 'Courrier de test')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('courriers', 1);
    }

    public function test_un_collaborateur_ne_peut_pas_acceder_au_formulaire(): void
    {
        $collaborateur = $this->utilisateurAvecProfil('Collaborateur');

        $this->actingAs($collaborateur);

        Livewire::test(RegistrationForm::class)->assertForbidden();
    }

    // 2026-09-15 (voir DECISIONS.md, synchronisation SRS-GEC.pdf) : le
    // compteur n'est plus par (année, service) mais global par année — un
    // courrier entrant n'a plus systématiquement de service connu au moment
    // de la génération (voir NumeroReferenceGenerator::generer()). Les deux
    // courriers ci-dessous ont volontairement des services DIFFÉRENTS (l'un
    // sortant vers FIN, l'autre entrant sans service) pour prouver que la
    // séquence est bien partagée entre eux, pas cloisonnée par service.
    public function test_les_numeros_de_reference_sont_sequentiels_par_annee(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'FIN']);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier de test 1')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer');

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'entrant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.objet', 'Courrier de test 2')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer');

        $references = Courrier::orderBy('id')->pluck('numero_reference');

        $this->assertSame("GEC-{$this->anneeCourante()}-000001", $references[0]);
        $this->assertSame("GEC-{$this->anneeCourante()}-000002", $references[1]);
    }

    private function brouillon(User $agent, array $attributs = []): CourrierBrouillon
    {
        return CourrierBrouillon::create(array_merge([
            'fichier_path' => 'brouillons/uuid-test.pdf',
            'nom_original' => 'scan.pdf',
            'type_mime' => 'application/pdf',
            'taille' => 1000,
            'cree_par_id' => $agent->id,
            'ocr_statut' => 'reussi',
            'texte_ocr' => 'Texte reconnu sur le scan.',
            'ocr_confiance' => 88,
            'numero_tampon_detecte' => "NSIA ASSURANCES 21 JUIL '26 10:26:48-1789553",
        ], $attributs));
    }

    // 2026-09-24 — retour réel de l'utilisateur (capture d'écran) :
    // "Aperçu du courrier" affichait le CODE JS DU PANNEAU comme texte
    // visible sur la page. Cause trouvée : un commentaire à l'intérieur de
    // l'attribut x-data="{ ... }" (délimité par des guillemets DROITS)
    // contenait lui-même un guillemet droit — pour le navigateur, ce
    // guillemet referme prématurément l'attribut HTML, et tout le reste du
    // JS (jusqu'au prochain guillemet droit trouvé par accident) fuit comme
    // texte de page. Règle : AUCUN guillemet droit " dans un commentaire ou
    // une chaîne à l'intérieur d'un x-data="..." — guillemets français «»
    // uniquement (voir notes internes). Ce test extrait le
    // bloc x-data du panneau d'aperçu et vérifie qu'il n'en contient aucun.
    public function test_lattribut_xdata_du_panneau_apercu_ne_contient_aucun_guillemet_droit(): void
    {
        Storage::fake('s3');
        $agent = $this->utilisateurAvecProfil('Agent');
        Storage::disk('s3')->put('brouillons/uuid-test.pdf', 'contenu du scan');
        $brouillon = $this->brouillon($agent);
        $this->actingAs($agent);

        $html = Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])->html();

        $ancrage = strpos($html, 'activerDeplacement');
        $this->assertNotFalse($ancrage, 'Le panneau "Aperçu du document" ne s\'est pas affiché.');

        $debut = strrpos(substr($html, 0, $ancrage), 'x-data="{');
        $finAttribut = strpos($html, '}"', $ancrage);
        $bloc = substr($html, $debut + strlen('x-data="'), $finAttribut - ($debut + strlen('x-data="')) + 1);

        $this->assertStringNotContainsString('"', $bloc, 'Un guillemet droit dans le bloc x-data casse l\'attribut HTML.');
    }

    public function test_un_brouillon_prerempli_la_date_et_est_finalise_a_lenregistrement(): void
    {
        Storage::fake('s3');
        Queue::fake([ReplicateFichierJob::class, IndexCourrierJob::class]);

        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        Storage::disk('s3')->put('brouillons/uuid-test.pdf', 'contenu du scan');
        $brouillon = $this->brouillon($agent);

        $this->actingAs($agent);

        $composant = Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.date_mouvement', '2026-07-21') // pré-rempli depuis le tampon
            ->assertSee('scan.pdf')
            ->set('form.sens', 'sortant')
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier scanné')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $courrier = $service->courriers()->firstOrFail();

        $this->assertStringStartsWith("courriers/{$this->anneeCourante()}/DIR/{$courrier->numero_reference}.", $courrier->fichier_path);
        Storage::disk('s3')->assertExists($courrier->fichier_path);
        Storage::disk('s3')->assertMissing('brouillons/uuid-test.pdf');
        $this->assertSame('Texte reconnu sur le scan.', $courrier->texte_ocr);
        $this->assertSame('reussi', $courrier->ocr_statut);
        $this->assertSame(88, $courrier->ocr_confiance);
        $this->assertSame("NSIA ASSURANCES 21 JUIL '26 10:26:48-1789553", $courrier->numero_tampon_detecte);

        $this->assertDatabaseCount('courrier_brouillons', 0);

        // Règle n°5 — bug réel trouvé le 2026-09-10 (revue de code) : la
        // finalisation d'un brouillon n'écrivait jusque-là aucune entrée
        // d'historique, contrairement au scan classique (ScanForm::numeriser()).
        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'auteur_id' => $agent->id,
            'action' => 'numerisation',
        ]);

        Queue::assertPushedOn('replication', ReplicateFichierJob::class, fn (ReplicateFichierJob $job) => $job->chemin === $courrier->fichier_path);
        Queue::assertPushedOn('indexation', IndexCourrierJob::class, fn (IndexCourrierJob $job) => $job->courrier->id === $courrier->id);

        // Deux vagues : à la création (objet/expéditeur) puis après finalisation
        // (texte OCR exploitable) — même double déclenchement qu'un scan classique.
        Queue::assertPushed(IndexCourrierJob::class, 2);

        $composant->assertDontSee('scan.pdf'); // liste "en attente" vidée après finalisation
    }

    // 2026-09-15 (voir DECISIONS.md, synchronisation SRS-GEC.pdf) : un
    // courrier ENTRANT non-sinistre n'a pas de service à l'enregistrement —
    // le document scanné (et une éventuelle pièce jointe) est provisoirement
    // rangé sous le segment "_en_attente" (voir Courrier::segmentClassement()),
    // déplacé plus tard par WorkflowService::validerService() une fois le DGA
    // passé (voir WorkflowServiceTest).
    public function test_le_document_dun_courrier_entrant_est_range_sous_en_attente_avant_validation_dga(): void
    {
        Storage::fake('s3');
        Queue::fake([ReplicateFichierJob::class, IndexCourrierJob::class]);

        $agent = $this->utilisateurAvecProfil('Agent');
        Storage::disk('s3')->put('brouillons/uuid-test.pdf', 'contenu du scan');
        $brouillon = $this->brouillon($agent);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->set('form.sens', 'entrant')
            ->set('form.objet', 'Courrier scanné')
            ->set('form.type_document', 'Lettre')
            ->set('nouvellesPiecesJointes', [UploadedFile::fake()->create('copie.pdf', 100, 'application/pdf')])
            ->call('enregistrer')
            ->assertHasNoErrors();

        $courrier = Courrier::firstOrFail();

        $this->assertNull($courrier->service_id);
        $this->assertStringStartsWith("courriers/{$this->anneeCourante()}/_en_attente/{$courrier->numero_reference}.", $courrier->fichier_path);
        Storage::disk('s3')->assertExists($courrier->fichier_path);

        $pieceJointe = $courrier->piecesJointes()->firstOrFail();
        $this->assertStringStartsWith("courriers/{$this->anneeCourante()}/_en_attente/{$courrier->numero_reference}/", $pieceJointe->fichier_path);
        Storage::disk('s3')->assertExists($pieceJointe->fichier_path);
    }

    // 2026-10-23, demande explicite de l'utilisateur : « l'agent doit pouvoir
    // choisir plus d'un fichier pour enregistrer un courrier, comme une
    // facture et un simple courrier envoyés par la même personne ».
    public function test_plusieurs_pieces_jointes_peuvent_etre_jointes_en_une_seule_selection(): void
    {
        Storage::fake('s3');
        Queue::fake([ReplicateFichierJob::class]);
        $service = Service::factory()->create(['code' => 'DIR']);
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Facture et lettre du même expéditeur')
            ->set('form.type_document', 'Lettre')
            ->set('nouvellesPiecesJointes', [
                UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('lettre.pdf', 100, 'application/pdf'),
            ])
            ->assertSet('nouvellesPiecesJointes', [])
            ->assertCount('piecesJointes', 2)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $courrier = $service->courriers()->firstOrFail();

        $this->assertEqualsCanonicalizing(['facture.pdf', 'lettre.pdf'], $courrier->piecesJointes()->pluck('nom_original')->all());
        foreach ($courrier->piecesJointes as $pieceJointe) {
            Storage::disk('s3')->assertExists($pieceJointe->fichier_path);
        }
        $this->assertSame(2, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'ajout_piece_jointe')->count());
        Queue::assertPushed(ReplicateFichierJob::class, 2);
    }

    // Les fichiers peuvent être choisis en plusieurs fois (le champ se vide
    // après chaque choix, la liste s'allonge) et retirés avant l'enregistrement.
    public function test_les_pieces_jointes_se_cumulent_et_peuvent_etre_retirees_avant_lenregistrement(): void
    {
        Storage::fake('s3');
        Queue::fake([ReplicateFichierJob::class]);
        $service = Service::factory()->create(['code' => 'DIR']);
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'sortant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier avec plusieurs pièces')
            ->set('form.type_document', 'Lettre')
            ->set('nouvellesPiecesJointes', [UploadedFile::fake()->create('erreur.pdf', 100, 'application/pdf')])
            ->set('nouvellesPiecesJointes', [
                UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->image('recu.jpg', 1200, 1600),
            ])
            ->assertCount('piecesJointes', 3)
            ->call('retirerPieceJointe', 0)
            ->assertCount('piecesJointes', 2)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(['facture.pdf', 'recu.jpg'], $service->courriers()->firstOrFail()->piecesJointes()->pluck('nom_original')->all());
    }

    public function test_un_fichier_au_mauvais_format_est_refuse_sans_toucher_aux_fichiers_deja_choisis(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(RegistrationForm::class)
            ->set('nouvellesPiecesJointes', [UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf')])
            ->set('nouvellesPiecesJointes', [UploadedFile::fake()->create('virus.exe', 100, 'application/octet-stream')])
            ->assertHasErrors('piecesJointes')
            ->assertCount('piecesJointes', 1)
            ->assertSet('nouvellesPiecesJointes', []);
    }

    public function test_dix_pieces_jointes_au_maximum(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        $fichiers = array_map(fn (int $i) => UploadedFile::fake()->create("piece-{$i}.pdf", 10, 'application/pdf'), range(1, 12));

        Livewire::test(RegistrationForm::class)
            ->set('nouvellesPiecesJointes', $fichiers)
            ->assertCount('piecesJointes', 10)
            ->assertHasErrors('piecesJointes');
    }

    public function test_juin_et_juillet_ne_sont_pas_confondus_dans_la_date_du_tampon(): void
    {
        // "JUI" seul (3 premières lettres) est ambigu entre juin et juillet en
        // français — la désambiguïsation garde le mot abrégé en entier.
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillonJuin = $this->brouillon($agent, ['numero_tampon_detecte' => "NSIA ASSURANCES 5 JUIN '26 09:00:00-1000001"]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillonJuin->id])
            ->assertSet('form.date_mouvement', '2026-06-05');
    }

    // Bug réel trouvé en revue de code (2026-09-10) : Carbon::createFromDate()
    // ne lève pas d'exception pour un jour hors plage (avril n'a que 30
    // jours) — il fait déborder silencieusement sur le mois suivant (1er
    // mai) au lieu de renvoyer une erreur. Comme cette date est la seule
    // proposition automatique jamais signalée "à vérifier", une date fausse
    // aurait pu être enregistrée sans aucun signal visible pour l'agent.
    // Depuis le 2026-09-22, un tampon invalide retombe sur la date du jour
    // (comme l'absence de tampon) plutôt que de laisser le champ vide.
    public function test_une_date_de_tampon_hors_plage_calendaire_nest_pas_silencieusement_decalee(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'numero_tampon_detecte' => "NSIA ASSURANCES 31 AVR '26 10:26:48-1789553", // avril n'a que 30 jours
            'texte_ocr' => 'Texte reconnu sur le scan, sans date au format "le JJ MOIS AAAA".',
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.date_mouvement', now()->format('Y-m-d')); // ni décalée silencieusement (1er mai), ni exception
    }

    public function test_le_service_et_le_type_sont_proposes_depuis_le_texte_ocr_du_brouillon(): void
    {
        // "le système doit détecter les informations et les mettre dans un
        // service correspondant, en attente de validation humaine" — réutilise
        // le moteur de règles du Module 3, appliqué au texte OCR du brouillon.
        $agent = $this->utilisateurAvecProfil('Agent');
        // Deux services : la présélection prouve que c'est bien la règle de
        // classement qui a joué, pas le raccourci "un seul service existe".
        Service::factory()->create(['code' => 'DIR']);
        $sinistres = Service::factory()->create(['code' => 'SIN']);
        RegleClassement::factory()->create([
            'mots_cles' => ['sinistre'],
            'type_document_propose' => 'Réclamation',
            'service_propose_id' => $sinistres->id,
        ]);
        $brouillon = $this->brouillon($agent, ['texte_ocr' => "Objet : Déclaration de sinistre\n\nDéclaration de sinistre automobile suite à un accident."]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.objet', 'Déclaration de sinistre')
            ->assertSet('form.service_id', $sinistres->id)
            ->assertSet('form.type_document', 'Réclamation')
            ->assertSet('champsProposesAutomatiquement', true)
            ->assertSee('à vérifier');
    }

    // 2026-10-30 (« loading state … when it starts extracting OCR and
    // pre-filling ») — écran de chargement tant que l'OCR du document
    // principal tourne ; retiré et champs pré-remplis dès la fin (événement
    // temps réel brouillonOcrTermine()).
    public function test_lecran_de_chargement_saffiche_pendant_lextraction_puis_disparait(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, ['ocr_statut' => 'en_cours', 'texte_ocr' => null, 'numero_tampon_detecte' => null]);
        $this->actingAs($agent);

        $test = Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSee(__('Extraction du texte et pré-remplissage…'))
            ->assertSee(__('Continuer sans attendre'));

        $brouillon->update(['ocr_statut' => 'reussi', 'texte_ocr' => "Yaoundé, le 07 Janvier 2026\n\nObjet : Défis Actuels\n\nMonsieur,"]);

        $test->call('brouillonOcrTermine')
            ->assertDontSee(__('Extraction du texte et pré-remplissage…'))
            ->assertSet('form.objet', 'Défis Actuels');
    }

    public function test_lobjet_est_extrait_de_la_convention_objet_deux_points(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, ['texte_ocr' => "Yaoundé, le 07 Janvier 2026\n\nObjet : Défis Actuels\n\nMonsieur,"]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.objet', 'Défis Actuels')
            ->assertSet('champsProposesAutomatiquement', true);
    }

    public function test_le_destinataire_est_propose_depuis_la_civilite_du_texte_ocr(): void
    {
        // Décision du 2026-09-04 (voir DECISIONS.md "Destinataire proposé
        // automatiquement") : même principe que l'objet, risque de faux
        // positif accepté explicitement par l'utilisateur pour ce champ.
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "Mardi le 21 Juillet 2026\n\nMonsieur Le Directeur Général de NSIA Assurances\n\nObjet : Défis Actuels\n\nMonsieur,",
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.destinataire', 'Monsieur Le Directeur Général de NSIA Assurances')
            ->assertSet('champsProposesAutomatiquement', true)
            ->assertSee('à vérifier');
    }

    public function test_le_destinataire_et_lobjet_sont_proposes_depuis_un_document_sans_mention_objet(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-04, "FORMAVISION.COM") :
        // ni "Objet :", ni "à l'attention de" — un "A" isolé introduit le
        // bloc adresse sur plusieurs lignes, et un intitulé différent
        // ("Solutions innovantes : ...") juste avant la formule d'appel
        // tient lieu d'objet.
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'numero_tampon_detecte' => null,
            'texte_ocr' => "Yaoundé, le 07 Janvier 2026\n\nA\nMonsieur le Directeur Général\nNSIA ASSURANCE\nYaoundé\n\n"
                ."Solutions innovantes : Optimisez votre bureau avec nos fournitures connectées.\n\n"
                ."Monsieur le Directeur Général,\n\nNous vous prions d'accorder une attention particulière à l'étude de notre offre.",
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.destinataire', "Monsieur le Directeur Général\nNSIA ASSURANCE\nYaoundé")
            ->assertSet('form.objet', 'Optimisez votre bureau avec nos fournitures connectées.')
            ->assertSet('champsProposesAutomatiquement', true)
            ->assertSee('à vérifier');
    }

    public function test_la_date_par_defaut_est_celle_du_jour_sans_tampon(): void
    {
        // Demande explicite de l'utilisateur (2026-09-22) : sans tampon (plus
        // utilisé, voir DECISIONS.md), la date écrite par l'expéditeur
        // (dateDepuisTexteCourrier(), retirée) est celle de RÉDACTION/ENVOI
        // du courrier, pas sa date de RÉCEPTION chez Nsia — proposer la date
        // du jour de l'enregistrement est un point de départ bien plus
        // fiable, que l'agent corrige librement si besoin (courrier traité
        // en retard sur un lot).
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'numero_tampon_detecte' => null,
            'texte_ocr' => "Mardi le 21 Juillet 2026\n\nMonsieur Le Directeur Général de NSIA Assurances\n\nObjet : Défis Actuels",
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.date_mouvement', now()->format('Y-m-d'));
    }

    public function test_le_tampon_reste_prioritaire_sur_la_date_du_jour(): void
    {
        // Le tampon (une trace RÉELLE de dépôt/réception, quand il est
        // détecté) reste une source plus fiable que le simple jour de
        // l'enregistrement — jamais écrasé par la date du jour.
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => 'Objet : Défis Actuels',
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.date_mouvement', '2026-07-21');
    }

    public function test_les_bandeaux_a_verifier_ne_fuient_pas_vers_le_courrier_suivant_saisi_a_la_main(): void
    {
        // Bug réel constaté lors de la revue adversariale du 2026-09-04 :
        // champsProposesAutomatiquement n'était jamais remis à false après
        // un enregistrement réussi. Le composant reste monté (l'agent peut
        // enchaîner un autre courrier, voir brouillonsEnAttente) — sans ce
        // reset, les bandeaux "à vérifier" d'un brouillon précédent
        // restaient affichés sous des valeurs pourtant saisies entièrement à
        // la main pour le suivant.
        Storage::fake('s3');
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        Storage::disk('s3')->put('brouillons/uuid-test.pdf', 'contenu du scan');
        $brouillon = $this->brouillon($agent, [
            'numero_tampon_detecte' => null,
            'texte_ocr' => "Mardi le 21 Juillet 2026\n\nMonsieur Le Directeur Général de NSIA Assurances\n\nObjet : Défis Actuels",
        ]);

        $this->actingAs($agent);

        $composant = Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id]);

        $composant->assertSet('champsProposesAutomatiquement', true)
            ->set('form.service_id', $service->id)
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $composant->assertSet('champsProposesAutomatiquement', false);

        // Deuxième courrier, entièrement saisi à la main, sans brouillon.
        $composant->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier saisi à la main')
            ->set('form.type_document', 'Lettre')
            ->set('form.destinataire', 'Un destinataire tapé au clavier')
            ->set('form.expediteur_organisation', 'Une organisation tapée au clavier');

        $composant->assertDontSee('Proposé automatiquement');
    }

    public function test_le_telephone_et_ladresse_de_lexpediteur_sont_proposes_depuis_le_texte_ocr_du_brouillon(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "ITSC Sarl Cybersécurité — Pentesting\n\nMardi le 21 Juillet 2026\n\nObjet : Défis Actuels\n\nContacts : (00237)658239075 BP 2138 Yaoundé Cameroun",
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.expediteur_telephone', '(00237)658239075')
            ->assertSet('form.expediteur_adresse', 'BP 2138 Yaoundé Cameroun')
            ->assertSet('champsProposesAutomatiquement', true)
            ->assertSee('à vérifier');
    }

    public function test_lemail_de_lexpediteur_est_propose_depuis_le_texte_ocr_du_brouillon(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-17, "The Best
        // Group (TBG)", TBG/CMR/NSIA) : un email en en-tête sans aucun label
        // devant ("info@thebest-group.com").
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "info@thebest-group.com\n+237 694 006 485\n\nConcerne : Accompagnement",
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.expediteur_email', 'info@thebest-group.com')
            ->assertSet('champsProposesAutomatiquement', true)
            ->assertSee('à vérifier');
    }

    public function test_le_rc_et_le_niu_sont_proposes_depuis_le_texte_ocr_du_brouillon(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "ITSC Sarl Cybersécurité — Pentesting\n\nMardi le 21 Juillet 2026\n\nObjet : Défis Actuels\n\n"
                .'N° RC/YAO/2019/B/433 - Contribuable : M051912784615T - NIU : M051912784615T',
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.expediteur_rc', 'RC/YAO/2019/B/433')
            ->assertSet('form.expediteur_niu', 'M051912784615T')
            ->assertSet('champsProposesAutomatiquement', true)
            ->assertSee('à vérifier');
    }

    public function test_lorganisation_expeditrice_est_proposee_depuis_le_texte_ocr_du_brouillon(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "ITSC Sarl Cybersécurité — Pentesting, Analyse de risque réseau - Audit.\n\nMardi le 21 Juillet 2026\n\nMonsieur Le Directeur Général de NSIA Assurances\n\nObjet : Défis Actuels",
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.expediteur_organisation', 'ITSC Sarl')
            ->assertSet('champsProposesAutomatiquement', true)
            ->assertSee('à vérifier');
    }

    public function test_le_nom_de_lexpediteur_est_propose_depuis_le_texte_ocr_du_brouillon(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "Objet : Réclamation\n\nJe soussigné Jean Dupont, déclare avoir été victime d'un accident.",
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.expediteur_nom', 'Jean Dupont')
            ->assertSet('champsProposesAutomatiquement', true)
            ->assertSee('à vérifier');
    }

    public function test_le_mode_de_reception_est_propose_depuis_le_texte_ocr_du_brouillon(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "De : jean.dupont@gmail.com\nEnvoyé : lundi 3 mars\n\nObjet : Réclamation",
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.mode_reception', 'email')
            ->assertSet('modeReceptionProposeAutomatiquement', true)
            ->assertSee('à vérifier');
    }

    public function test_le_mode_de_reception_naffiche_pas_a_verifier_quand_il_nest_pas_propose(): void
    {
        // Non-régression ciblée : mode_reception a toujours une valeur par
        // défaut ('depot_physique', jamais vide) — sans indicateur dédié, le
        // garde-fou "indicateur partagé + champ non vide" utilisé par les
        // autres champs afficherait "à vérifier" en permanence dès qu'un
        // AUTRE champ (ici l'objet) est proposé.
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, [
            'texte_ocr' => "Objet : Défis Actuels\n\nAucun signal de mode de réception ici.",
        ]);

        $this->actingAs($agent);

        $html = Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id]);

        $html->assertSet('form.objet', 'Défis Actuels')
            ->assertSet('form.mode_reception', 'depot_physique')
            ->assertSet('champsProposesAutomatiquement', true)
            ->assertSet('modeReceptionProposeAutomatiquement', false);

        $this->assertSame(1, substr_count($html->html(), 'à vérifier'), 'le bandeau "à vérifier" ne doit apparaître que pour l\'objet');
    }

    public function test_seul_lobjet_propose_naffiche_pas_a_verifier_sur_service_et_type(): void
    {
        // Bug constaté en conditions réelles (2026-09-04) : le bandeau "à
        // vérifier" s'affichait sur TOUS les champs dès qu'UN SEUL était
        // proposé (indicateur global au lieu d'un indicateur par champ) —
        // ici, seul l'objet est extrait, aucune règle ne correspond.
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, ['texte_ocr' => "Objet : Défis Actuels\n\nAucun mot-clé de règle ici."]);

        $this->actingAs($agent);

        $html = Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id]);

        $html->assertSet('form.objet', 'Défis Actuels')
            ->assertSet('form.type_document', '')
            ->assertSet('form.service_id', null)
            ->assertSet('champsProposesAutomatiquement', true);

        // Une seule occurrence du texte "à vérifier" (celle du bandeau
        // objet), pas une par champ vide.
        $occurrences = substr_count($html->html(), 'à vérifier');
        $this->assertSame(1, $occurrences, 'le texte "à vérifier" ne doit apparaître que pour l\'objet, pas pour service/type restés vides');
    }

    public function test_sans_mention_objet_le_champ_reste_vide(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, ['texte_ocr' => 'Un courrier qui ne mentionne jamais explicitement son objet.']);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('form.objet', '')
            ->assertSet('champsProposesAutomatiquement', false);
    }

    public function test_aucune_proposition_si_locr_du_brouillon_a_echoue(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        RegleClassement::factory()->create(['mots_cles' => ['sinistre'], 'type_document_propose' => 'Réclamation']);
        $brouillon = $this->brouillon($agent, ['ocr_statut' => 'echec_qualite', 'texte_ocr' => 'sinistre sinistre sinistre']);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('champsProposesAutomatiquement', false)
            ->assertSet('form.type_document', '');
    }

    public function test_aucune_regle_ne_correspond_le_formulaire_reste_vide(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, ['texte_ocr' => 'Un texte quelconque sans rapport avec une règle configurée.']);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSet('champsProposesAutomatiquement', false)
            ->assertSet('form.type_document', '')
            ->assertSet('form.service_id', null);
    }

    public function test_un_agent_ne_peut_pas_utiliser_le_brouillon_dun_autre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $autreAgent = $this->utilisateurAvecProfil('Agent');
        // source par défaut du helper brouillon() = 'manuel' : seul un
        // brouillon "dossier_surveille" bénéficie de l'exception ci-dessous
        // (voir CourrierBrouillonPolicy::utiliser()), un scan manuel reste
        // personnel à son créateur comme avant.
        $brouillon = $this->brouillon($autreAgent);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])->assertForbidden();
    }

    // 2026-10-08 — retour utilisateur réel : le dossier surveillé attribue
    // le brouillon importé à QUELLE QUE SOIT la session active au moment
    // précis de l'import, pas forcément celle de l'agent qui ouvre ensuite
    // "Enregistrer un courrier" sur le même poste partagé — sans cette
    // exception, un agent ne voyait/n'utilisait JAMAIS les documents que le
    // dossier surveillé venait pourtant bien d'importer, dès qu'une autre
    // session avait déclenché l'import. Volontairement PAS
    // brouillons.utiliser_tout (explicitement refusé par l'utilisateur,
    // "not the whole thing the admin can see" — ce privilège couvrirait
    // AUSSI les scans MANUELS d'autres agents, hors sujet) : voir
    // CourrierBrouillonPolicy::utiliser(), troisième branche dédiée à
    // source === SOURCE_DOSSIER_SURVEILLE.
    public function test_un_agent_peut_utiliser_un_brouillon_dossier_surveille_dun_autre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $autreAgent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($autreAgent, ['source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])->assertHasNoErrors();
    }

    public function test_la_soumission_est_bloquee_si_locr_du_brouillon_est_encore_en_cours(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $brouillon = $this->brouillon($agent, ['ocr_statut' => 'en_cours', 'texte_ocr' => null]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier scanné')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasErrors(['brouillonId']);

        $this->assertDatabaseCount('courriers', 0);
        // Le brouillon reste intact, rien n'a été déplacé.
        $this->assertDatabaseCount('courrier_brouillons', 1);
    }

    // Bug réel trouvé le 2026-09-10 (revue de code) : le garde-fou ci-dessus
    // ne couvrait que 'en_cours', pas 'non_traite' (l'état initial d'un
    // brouillon tout juste créé, avant même que ProcessBrouillonOcr démarre
    // — ex. queue en retard/worker arrêté). Sans ce cas, enregistrer()
    // pouvait finaliser (et supprimer) le brouillon avant que le job OCR ne
    // tourne, perdant le texte/tampon pour toujours une fois le job exécuté
    // sur une ligne qui n'existe plus.
    public function test_la_soumission_est_bloquee_si_locr_du_brouillon_nest_pas_encore_demarre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $brouillon = $this->brouillon($agent, ['ocr_statut' => 'non_traite', 'texte_ocr' => null]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier scanné')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasErrors(['brouillonId']);

        $this->assertDatabaseCount('courriers', 0);
        $this->assertDatabaseCount('courrier_brouillons', 1);
    }

    public function test_un_brouillon_deja_finalise_nest_pas_retraite_une_seconde_fois(): void
    {
        // Simule une double soumission (deux onglets) : le brouillon porte déjà
        // finalise_le au moment où enregistrer() tente de le réclamer.
        Storage::fake('s3');

        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $brouillon = $this->brouillon($agent, ['finalise_le' => now()]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->set('form.sens', 'sortant')
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier scanné')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $courrier = $service->courriers()->firstOrFail();

        $this->assertNull($courrier->fichier_path, 'un brouillon déjà réclamé par une autre requête n\'est pas re-déplacé');
        // Le brouillon déjà finalisé n'est pas supprimé par cette seconde tentative.
        $this->assertDatabaseCount('courrier_brouillons', 1);
    }

    // Bug réel trouvé en revue de code (2026-09-10) : le brouillon avait été
    // répliqué vers le disque de secours dès sa création
    // (BrouillonScanService::creer()), mais finaliserBrouillon() ne
    // supprimait jamais cette copie au chemin brouillons/... — orpheline
    // pour toujours une fois le document définitif répliqué à son tour vers
    // courriers/....
    public function test_la_copie_de_secours_du_brouillon_est_supprimee_a_la_finalisation(): void
    {
        config(['filesystems.disks.s3_backup.bucket' => 'test-backup']);
        Storage::fake('s3');
        Storage::fake('s3_backup');
        Queue::fake([ReplicateFichierJob::class, IndexCourrierJob::class]);

        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        Storage::disk('s3')->put('brouillons/uuid-test.pdf', 'contenu du scan');
        Storage::disk('s3_backup')->put('brouillons/uuid-test.pdf', 'contenu du scan (copie de secours)');
        $brouillon = $this->brouillon($agent);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier scanné')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasNoErrors();

        Storage::disk('s3_backup')->assertMissing('brouillons/uuid-test.pdf');
    }

    public function test_le_champ_piece_jointe_precise_que_le_scan_est_deja_le_document_principal(): void
    {
        // Confusion réelle rapportée par l'utilisateur : le champ "Pièce
        // jointe" restait vide même après un scan, laissant penser que rien
        // n'avait été pris en compte — alors que le document scanné devient
        // déjà automatiquement le fichier principal à l'enregistrement
        // (finaliserBrouillon()). Le champ sert à un usage différent (fichier
        // EN PLUS du scan), désormais précisé dans la vue.
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSee('Fichiers supplémentaires')
            ->assertSee('sera automatiquement rattaché comme document principal')
            ->assertDontSee('Un courrier physique sans copie numérique sera numérisé au Module 2.');
    }

    public function test_le_champ_piece_jointe_garde_son_texte_par_defaut_sans_brouillon(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->assertSee('Un courrier physique sans copie numérique sera numérisé au Module 2.')
            ->assertDontSee('Fichiers supplémentaires');
    }

    public function test_les_documents_scannes_non_enregistres_sont_listes(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $autreAgent = $this->utilisateurAvecProfil('Agent');

        $lemien = $this->brouillon($agent, ['nom_original' => 'mon-scan.pdf']);
        // source par défaut du helper brouillon() = 'manuel' : reste
        // personnel à son créateur, contrairement à un import
        // 'dossier_surveille' (voir le test suivant).
        $this->brouillon($autreAgent, ['nom_original' => 'pas-le-mien.pdf']);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->assertSee('mon-scan.pdf')
            ->assertDontSee('pas-le-mien.pdf');
    }

    // 2026-10-08 — retour utilisateur réel, voir le commentaire détaillé de
    // test_un_agent_peut_utiliser_un_brouillon_dossier_surveille_dun_autre()
    // plus haut : un import "dossier_surveille" est visible par N'IMPORTE
    // QUEL agent, pas seulement son créateur — contrairement au test
    // ci-dessus (source 'manuel', qui reste personnel).
    public function test_les_imports_dossier_surveille_dun_autre_agent_sont_listes(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $autreAgent = $this->utilisateurAvecProfil('Agent');

        $this->brouillon($autreAgent, [
            'nom_original' => 'scan-autre-agent.pdf',
            'source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE,
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)->assertSee('scan-autre-agent.pdf');
    }

    // Cohérence avec CourrierBrouillonPolicy::utiliser() qui autorise déjà
    // un Administrateur à UTILISER n'importe quel brouillon — la liste doit
    // le lui permettre de les DÉCOUVRIR, pas seulement les siens (retour
    // utilisateur "shouldn't the administrator see all", 2026-09-09).
    public function test_un_administrateur_voit_les_brouillons_de_tous_les_agents(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');

        $this->brouillon($agent, ['nom_original' => 'scan-agent.pdf']);

        $this->actingAs($admin);

        Livewire::test(RegistrationForm::class)
            ->assertSee('scan-agent.pdf')
            ->assertSee($agent->name);
    }

    // Vraie pagination (demande explicite de l'utilisateur, 2026-09-14 :
    // "10 per table") — remplace le plafond fixe + compteur "non affiché"
    // du 2026-09-10 (bug réel : la limite de la liste, pensée à l'origine
    // pour un agent seul, s'applique désormais à TOUS les agents pour un
    // Administrateur, et masquait silencieusement les plus anciens en cas
    // de dépassement — exactement "courriers perdus ou oubliés", PRD.md).
    public function test_les_brouillons_sont_pagines_a_dix_par_page(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');

        // created_at explicite et distinct (forceFill : hors $fillable) —
        // sans ça, les 13 lignes créées dans la même seconde ne sont pas
        // départagées de façon fiable par ->latest(), rendant l'ordre (et
        // donc ce test) indéterministe.
        for ($i = 1; $i <= 13; $i++) {
            $this->brouillon($agent, ['nom_original' => "scan-{$i}.pdf"])
                ->forceFill(['created_at' => now()->addSeconds($i)])
                ->save();
        }

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->assertSee('scan-13.pdf') // le plus récent, page 1
            ->assertDontSee('scan-1.pdf') // le plus ancien, page 2
            ->call('gotoPage', 2, 'brouillonsPage')
            ->assertSee('scan-1.pdf')
            ->assertDontSee('scan-13.pdf');
    }

    // Bug réel trouvé le 2026-09-09 : un brouillon déjà finalisé (fichier
    // déjà déplacé/supprimé par finaliserBrouillon()) réapparaissait dans
    // la liste juste après un enregistrement, puisque brouillonId repasse
    // à 0 et que rien n'excluait plus les brouillons finalisés.
    public function test_un_brouillon_deja_finalise_ne_reapparait_pas_dans_la_liste(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->brouillon($agent, ['nom_original' => 'deja-enregistre.pdf', 'finalise_le' => now()]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->assertDontSee('deja-enregistre.pdf');
    }

    // Module 1/2 — le statut OCR affiché dans la liste déroulante (et le
    // bandeau du brouillon sélectionné) doit se mettre à jour EN DIRECT
    // quand ProcessBrouillonOcr termine, sans que l'agent recharge la page
    // (voir DECISIONS.md "Watcher automatique sur le formulaire
    // d'enregistrement" — retour explicite de l'utilisateur : "it is only
    // when i refresh the page that it changes status"). brouillonOcrTermine()
    // est le handler appelé par Livewire quand l'évènement broadcast arrive
    // (testé ici directement, l'infrastructure Echo/Reverb elle-même n'est
    // pas testable en PHPUnit).

    public function test_le_statut_ocr_dun_autre_brouillon_en_attente_se_met_a_jour_sans_recharger(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        // Nom neutre (pas "en-cours.pdf") : évite toute collision avec le
        // texte "(en cours)" qu'on vérifie ci-dessous — sinon le nom de
        // fichier lui-même ferait passer l'assertion à tort.
        $autre = $this->brouillon($agent, ['nom_original' => 'document-test.pdf', 'ocr_statut' => 'en_cours']);

        $this->actingAs($agent);

        // Parenthèse incluse : "(en cours)" est le format exact rendu par
        // le select (voir registrationForm.blade.php) — une recherche sans
        // parenthèse matcherait aussi "Envoi en cours…" (indicateur
        // wire:loading du champ pièce jointe, sans rapport).
        $component = Livewire::test(RegistrationForm::class)
            ->assertSee('(en cours)');

        // Simule ProcessBrouillonOcr::handle() qui termine pendant que
        // l'agent est déjà sur la page (le job tourne dans un autre
        // processus, pas via ce composant).
        $autre->update(['ocr_statut' => 'reussi']);

        $component->call('brouillonOcrTermine')
            ->assertSee('(reussi)')
            ->assertDontSee('(en cours)');
    }

    public function test_le_bandeau_du_brouillon_selectionne_se_met_aussi_a_jour(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $brouillon = $this->brouillon($agent, ['ocr_statut' => 'en_cours', 'numero_tampon_detecte' => null]);

        $this->actingAs($agent);

        $component = Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->assertSee(__('extraction en cours…'));

        $brouillon->update(['ocr_statut' => 'reussi', 'numero_tampon_detecte' => "NSIA ASSURANCES 21 JUIL '26 10:26:48-1789553"]);

        $component->call('brouillonOcrTermine')
            ->assertDontSee(__('extraction en cours…'))
            ->assertSee("NSIA ASSURANCES 21 JUIL '26 10:26:48-1789553");
    }

    // Module 1/2 — dossier surveillé, watcher automatique directement sur
    // cette page (2026-09-09, voir DECISIONS.md "Watcher automatique sur le
    // formulaire d'enregistrement") : même point d'entrée que
    // ScanPremier::numeriserAutomatique() (tests miroir), la logique
    // partagée passant par BrouillonScanService.

    public function test_un_agent_peut_scanner_automatiquement_depuis_le_formulaire_denregistrement(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('document', UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf'))
            ->call('numeriserAutomatique')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $brouillon = CourrierBrouillon::firstOrFail();

        $this->assertSame($agent->id, $brouillon->cree_par_id);
        Storage::disk('s3')->assertExists($brouillon->fichier_path);
        Queue::assertPushedOn('ocr', ProcessBrouillonOcr::class, fn (ProcessBrouillonOcr $job) => $job->brouillon->id === $brouillon->id);
        Queue::assertPushedOn('replication', ReplicateFichierJob::class, fn (ReplicateFichierJob $job) => $job->chemin === $brouillon->fichier_path);
    }

    public function test_numeriser_automatique_retourne_lid_du_brouillon_pour_le_js(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(RegistrationForm::class)
            ->set('document', UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf'))
            ->call('numeriserAutomatique')
            ->assertReturned(function (array $valeur) {
                $brouillon = CourrierBrouillon::firstOrFail();

                return $valeur['brouillonId'] === $brouillon->id
                    && $valeur['nomOriginal'] === 'scan.pdf';
            });
    }

    // Pas de test dédié "non autorisé ne peut pas scanner automatiquement"
    // ici : RegistrationForm::mount() appelle déjà authorize('create', ...)
    // avant même que numeriserAutomatique() puisse être atteinte — couvert
    // par test_un_collaborateur_ne_peut_pas_acceder_au_formulaire() plus
    // haut (contrairement à ScanPremier, dont mount() n'autorise pas,
    // seules numeriser()/numeriserAutomatique() le font).

    public function test_une_image_trop_petite_est_refusee_en_mode_automatique_depuis_lenregistrement(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(RegistrationForm::class)
            ->set('document', UploadedFile::fake()->image('photo.jpg', 200, 200))
            ->call('numeriserAutomatique')
            ->assertHasErrors(['document']);

        $this->assertDatabaseCount('courrier_brouillons', 0);
    }

    // 2026-09-22 — même correctif que ScanPremierTest : un import automatique
    // (dossier surveillé) doit être tracé avec la source dédiée pour
    // apparaître dans le tableau admin de ScanPremier.
    public function test_le_scan_automatique_est_trace_avec_la_source_dossier_surveille(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(RegistrationForm::class)
            ->set('document', UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf'))
            ->call('numeriserAutomatique');

        $this->assertSame(CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE, CourrierBrouillon::firstOrFail()->source);
    }

    // "Importer un fichier" (2026-09-17, bouton manuel de cette page) est
    // l'AUTRE point d'entrée manuel de BrouillonScanService::creer() (avec
    // ScanPremier::numeriser()) — doit rester tracé comme manuel, jamais
    // apparaître dans le tableau admin "dossier surveillé".
    public function test_limport_manuel_est_trace_avec_la_source_manuel(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(RegistrationForm::class)
            ->set('documentsImportes', [UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf')])
            ->call('importerFichier');

        $this->assertSame(CourrierBrouillon::SOURCE_MANUEL, CourrierBrouillon::firstOrFail()->source);
    }

    // 2026-10-23, demande explicite de l'utilisateur (étape 1 « Scan /
    // Import du document ») : « on doit pouvoir sélectionner plusieurs
    // fichiers ici, pas un seul » — ex. une lettre et la facture du même
    // expéditeur. Le premier fichier devient le document principal (lu pour
    // le pré-remplissage), les autres sont joints au même courrier.
    public function test_plusieurs_fichiers_importes_en_une_fois_le_premier_devient_le_document_principal(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        $composant = Livewire::test(RegistrationForm::class)
            ->set('documentsImportes', [
                UploadedFile::fake()->create('lettre.pdf', 300, 'application/pdf'),
                UploadedFile::fake()->create('facture.pdf', 300, 'application/pdf'),
            ])
            ->call('importerFichier')
            ->assertHasNoErrors()
            ->assertSet('documentsImportes', []);

        $lettre = CourrierBrouillon::where('nom_original', 'lettre.pdf')->firstOrFail();
        $facture = CourrierBrouillon::where('nom_original', 'facture.pdf')->firstOrFail();

        $composant
            ->assertSet('brouillonId', $lettre->id)
            ->assertSet('brouillonsSupplementairesIds', [$facture->id])
            ->assertSee('Autres documents de ce courrier');

        $this->assertSame([CourrierBrouillon::SOURCE_MANUEL], CourrierBrouillon::pluck('source')->unique()->values()->all());
        Queue::assertPushed(ProcessBrouillonOcr::class, 2);
    }

    // Un document déjà scanné et en attente (ex. la facture passée au
    // scanner juste après la lettre) est joint au courrier : à
    // l'enregistrement il devient une pièce jointe, rangée sous le même
    // numéro de référence (Règle n°4), tracée (Règle n°5), et son brouillon
    // disparaît de la liste d'attente. Son OCR non encore lancé ne bloque
    // pas l'enregistrement — seul le document principal est lu.
    public function test_un_document_scanne_joint_devient_une_piece_jointe_a_lenregistrement(): void
    {
        Storage::fake('s3');
        Queue::fake([ReplicateFichierJob::class, IndexCourrierJob::class]);

        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        Storage::disk('s3')->put('brouillons/uuid-test.pdf', 'contenu de la lettre');
        Storage::disk('s3')->put('brouillons/facture.pdf', 'contenu de la facture');
        $principal = $this->brouillon($agent);
        $facture = $this->brouillon($agent, [
            'fichier_path' => 'brouillons/facture.pdf',
            'nom_original' => 'facture.pdf',
            'ocr_statut' => 'non_traite',
            'texte_ocr' => null,
            'numero_tampon_detecte' => null,
        ]);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $principal->id])
            ->assertSee('Joindre aussi un document scanné en attente')
            ->call('joindreBrouillon', $facture->id)
            ->assertSet('brouillonsSupplementairesIds', [$facture->id])
            ->set('form.sens', 'sortant')
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Lettre et facture')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertSet('brouillonsSupplementairesIds', []);

        $courrier = $service->courriers()->firstOrFail();
        $pieceJointe = $courrier->piecesJointes()->sole();

        $this->assertSame('facture.pdf', $pieceJointe->nom_original);
        $this->assertStringStartsWith("courriers/{$this->anneeCourante()}/DIR/{$courrier->numero_reference}/", $pieceJointe->fichier_path);
        Storage::disk('s3')->assertExists($pieceJointe->fichier_path);
        Storage::disk('s3')->assertMissing('brouillons/facture.pdf');
        $this->assertDatabaseCount('courrier_brouillons', 0);
        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'action' => 'ajout_piece_jointe',
            'commentaire' => 'facture.pdf',
        ]);
        Queue::assertPushedOn('replication', ReplicateFichierJob::class, fn (ReplicateFichierJob $job) => $job->chemin === $pieceJointe->fichier_path);
    }

    // L'ordre de sélection ne suit pas toujours l'intention de l'agent : un
    // document joint peut devenir le document principal, et un document
    // retiré redevient simplement « en attente ».
    public function test_un_document_joint_peut_devenir_principal_ou_etre_retire(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $facture = $this->brouillon($agent, ['nom_original' => 'facture.pdf']);
        $lettre = $this->brouillon($agent, ['nom_original' => 'lettre.pdf']);
        $annexe = $this->brouillon($agent, ['nom_original' => 'annexe.pdf']);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $facture->id])
            ->call('joindreBrouillon', $lettre->id)
            ->call('joindreBrouillon', $annexe->id)
            ->call('utiliserCommePrincipal', $lettre->id)
            ->assertSet('brouillonId', $lettre->id)
            ->assertSet('brouillonsSupplementairesIds', [$facture->id, $annexe->id])
            ->call('retirerBrouillon', $annexe->id)
            ->assertSet('brouillonsSupplementairesIds', [$facture->id])
            // « Annuler » sur le document principal : le premier document
            // joint prend sa place.
            ->call('annulerDocument')
            ->assertSet('brouillonId', $facture->id)
            ->assertSet('brouillonsSupplementairesIds', []);

        $this->assertDatabaseCount('courrier_brouillons', 3);
        $this->assertSame(0, CourrierBrouillon::whereNotNull('finalise_le')->count());
    }

    // Règle n°6 — jamais fait confiance à un ID de brouillon venu du client :
    // le document scanné manuellement par un autre agent ne peut être joint
    // ni par l'action, ni en forgeant la liste des documents joints.
    public function test_un_agent_ne_peut_pas_joindre_le_document_scanne_dun_autre_agent(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $autreAgent = $this->utilisateurAvecProfil('Agent');
        $principal = $this->brouillon($agent);
        $brouillonAutre = $this->brouillon($autreAgent, ['nom_original' => 'autre.pdf']);

        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class, ['brouillonId' => $principal->id])
            ->call('joindreBrouillon', $brouillonAutre->id)
            ->assertForbidden();

        Livewire::test(RegistrationForm::class, ['brouillonId' => $principal->id])
            ->set('brouillonsSupplementairesIds', [$brouillonAutre->id])
            ->assertForbidden();
    }

    public function test_dix_documents_scannes_au_maximum_par_courrier(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        $fichiers = array_map(fn (int $i) => UploadedFile::fake()->create("scan-{$i}.pdf", 10, 'application/pdf'), range(1, 11));

        Livewire::test(RegistrationForm::class)
            ->set('documentsImportes', $fichiers)
            ->call('importerFichier')
            ->assertHasErrors('documentsImportes')
            ->assertSet('documentsImportes', []);

        $this->assertDatabaseCount('courrier_brouillons', 0);
    }

    // 2026-10-29 (« where can i see the mes courrier it should be a button at
    // the bottom of the register courrier form ») — même droit que la page.
    public function test_le_formulaire_mene_a_mes_courriers_pour_qui_peut_les_voir(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->assertTrue($agent->hasPrivilege('courriers.voir_mes_courriers'), 'privilège par défaut de l\'agent');

        $this->actingAs($agent);
        Livewire::test(RegistrationForm::class)
            ->assertSee(__('Mes courriers'))
            ->assertSeeHtml(e(route('courriers.mes-courriers')));

        $agent->profil->privileges()->detach(Privilege::where('cle', 'courriers.voir_mes_courriers')->value('id'));

        $this->actingAs(User::find($agent->id));
        Livewire::test(RegistrationForm::class)
            ->assertDontSeeHtml(e(route('courriers.mes-courriers')));
    }

    private function anneeCourante(): int
    {
        return (int) now()->year;
    }
}
