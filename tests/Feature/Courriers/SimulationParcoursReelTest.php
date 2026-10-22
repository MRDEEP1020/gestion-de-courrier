<?php

namespace Tests\Feature\Courriers;

use App\Jobs\ArchiverCourriersTraitesJob;
use App\Jobs\IndexCourrierJob;
use App\Jobs\ProcessBrouillonOcr;
use App\Livewire\Backend\CourrierList;
use App\Livewire\Backend\DossierClassementList;
use App\Livewire\Backend\MesCourriers;
use App\Livewire\Backend\OrganisationIndex;
use App\Livewire\Backend\RegistrationForm;
use App\Livewire\Backend\RegleList;
use App\Livewire\Backend\ScanPremier;
use App\Livewire\Backend\ShowCourrier;
use App\Livewire\Backend\UserList;
use App\Models\Courrier;
use App\Models\CourrierBrouillon;
use App\Models\DossierClassement;
use App\Models\OrganizationUnit;
use App\Models\Service;
use App\Models\User;
use App\Services\ClassificationService;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Simulation "vie réelle" demandée par l'utilisateur (2026-09-24) : trois
// courriers parcourent tout le circuit, de la réceptionniste à l'archivage,
// en passant UNIQUEMENT par les vrais composants/pages de l'application et
// les comptes pilotes réels (DatabaseSeeder → ComptesTestPiloteSeeder),
// jamais par des données fabriquées à la main. La configuration que
// l'administrateur ferait dans l'interface (organisation, destinataires de
// transfert, règles de classement, niveau de confidentialité) est elle
// aussi faite via les pages d'administration. Base SQLite en mémoire
// (phpunit.xml) — jamais la vraie base `gec`.
//
//   Courrier A — lettre normale scannée : scan → enregistrement → transfert
//                DGA → validation service (DI) → affectation → traitement →
//                renvoi pour correction → validation → archivage.
//   Courrier B — déclaration de sinistre : routée directement à DSIN (saute
//                la DGA) → affectation → mise en attente d'information →
//                reprise → validation → archivage.
//   Courrier C — courrier confidentiel jamais ouvert : envoyé directement à
//                la DGA, niveau 3 → la DGA le marque comme remis → archivage.
//
// Un rapport lisible (parcours + matrice des pages par compte) est écrit
// dans storage/logs/simulation-parcours.md à chaque exécution.
class SimulationParcoursReelTest extends TestCase
{
    use RefreshDatabase;

    private array $journal = [];

    private array $comptes = [];

    public function test_trois_courriers_de_la_reception_a_larchivage(): void
    {
        try {
            $this->scenario();
        } finally {
            $this->ecrireRapport();
        }
    }

    private function scenario(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->seed();

        foreach ([
            'admin' => 'admin@test.local',
            'agent' => 'agent@test.local',
            'dga' => 'dga@test.local',
            'responsable_di' => 'responsable.di@test.local',
            'collaborateur_di' => 'collaborateur.di@test.local',
            'responsable_dsin' => 'responsable.dsin@test.local',
            'collaborateur_dsin' => 'collaborateur.dsin@test.local',
        ] as $cle => $email) {
            $this->comptes[$cle] = User::where('email', $email)->firstOrFail();
        }

        $di = Service::where('code', 'DI')->firstOrFail();
        $dsin = Service::where('code', 'DSIN')->firstOrFail();

        // ============ 0. Configuration par l'administrateur ============
        $this->section('0. Configuration initiale par l\'administrateur');
        $admin = $this->comptes['admin'];
        $this->actingAs($admin);

        $organisation = Livewire::test(OrganisationIndex::class);
        $organisation->call('ouvrirCreation', null)
            ->set('nomNoeud', 'Siège')
            ->set('typeNoeud', OrganizationUnit::TYPE_SITE)
            ->call('enregistrerNoeud')
            ->assertHasNoErrors();
        $site = OrganizationUnit::where('name', 'Siège')->firstOrFail();

        foreach (['Direction Informatique' => $di, 'Direction des Sinistres' => $dsin] as $nom => $service) {
            Livewire::test(OrganisationIndex::class)
                ->call('ouvrirCreation', $site->id)
                ->set('nomNoeud', $nom)
                ->set('typeNoeud', OrganizationUnit::TYPE_DEPARTMENT)
                ->set('servicePontNoeud', $service->id)
                ->call('enregistrerNoeud')
                ->assertHasNoErrors();
        }
        $departementDi = OrganizationUnit::where('name', 'Direction Informatique')->firstOrFail();
        $this->ok('Organisation : site « Siège » + départements DI et DSIN reliés aux services réels (/admin/organisation).');

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $this->comptes['agent']->id)
            ->call('ajouterDestinataire', $this->comptes['dga']->id)
            ->assertHasNoErrors();
        $this->assertTrue($this->comptes['agent']->destinatairesTransfert()->whereKey($this->comptes['dga']->id)->exists());
        $this->ok('Utilisateurs : la DGA ajoutée aux destinataires de transfert de l\'agent (/admin/utilisateurs).');

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $this->comptes['dga']->id)
            ->set('editionNiveauConfidentialite', 3)
            ->call('enregistrerEdition')
            ->assertHasNoErrors();
        $this->assertSame(3, $this->comptes['dga']->fresh()->niveau_confidentialite);
        $this->ok('Utilisateurs : niveau de confidentialité de la DGA relevé à 3.');

        // Cas réel : l'admin corrige juste le téléphone d'un collaborateur —
        // son service ne doit pas disparaître au passage.
        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $this->comptes['collaborateur_di']->id)
            ->set('editionTelephone', '+225 07 00 00 00')
            ->call('enregistrerEdition')
            ->assertHasNoErrors();
        $serviceApresEdition = $this->comptes['collaborateur_di']->fresh()->service_id;
        $this->verifier($serviceApresEdition === $di->id, 'Modifier le téléphone du collaborateur DI conserve son service.', 'Modifier le téléphone du collaborateur DI a changé son service ('.var_export($serviceApresEdition, true).' au lieu de '.$di->id.').');

        Livewire::test(RegleList::class)
            ->call('nouvelle')
            ->set('nom', 'Sinistres')
            ->set('motsCles', 'sinistre')
            ->set('champs', ['objet'])
            ->set('serviceProposeId', $dsin->id)
            ->call('enregistrer')
            ->assertHasNoErrors();
        Livewire::test(RegleList::class)
            ->call('nouvelle')
            ->set('nom', 'Informatique')
            ->set('motsCles', 'informatique, logiciel')
            ->set('champs', ['objet'])
            ->set('serviceProposeId', $di->id)
            ->call('enregistrer')
            ->assertHasNoErrors();
        $this->ok('Règles de classement : « sinistre » → DSIN, « informatique/logiciel » → DI (/admin/regles-classement).');

        // Comptes rechargés après la configuration, comme à une vraie
        // reconnexion (sinon la DGA garderait son ancien niveau en mémoire).
        $this->comptes = array_map(fn (User $u) => $u->fresh(), $this->comptes);

        // ============ Courrier A — lettre normale, scannée ============
        $this->section('Courrier A — lettre normale scannée (circuit complet avec DGA)');
        $agent = $this->comptes['agent'];
        $this->actingAs($agent);

        Livewire::test(ScanPremier::class)
            ->set('document', UploadedFile::fake()->create('lettre-easytech.pdf', 120, 'application/pdf'))
            ->call('numeriser')
            ->assertHasNoErrors()
            ->assertRedirect();
        $brouillon = CourrierBrouillon::latest('id')->firstOrFail();
        Queue::assertPushedOn('ocr', ProcessBrouillonOcr::class);
        $this->ok('Agent : scan du PDF (/courriers/numeriser) → brouillon créé, OCR mis en file « ocr ».');

        // Le worker OCR (Tesseract) n'est pas lancé en test : on applique le
        // résultat qu'il écrirait, tel quel (voir ProcessBrouillonOcr::handle()).
        $brouillon->update([
            'ocr_statut' => 'reussi',
            'ocr_confiance' => 91,
            'texte_ocr' => "EASYTECH GROUP SA\nAbidjan, le 20 septembre 2026\nÀ l'attention de la Direction Informatique\nObjet : Renouvellement de la licence logiciel de gestion\nMadame, Monsieur, ...",
        ]);

        $formulaire = Livewire::withQueryParams(['brouillonId' => $brouillon->id])->test(RegistrationForm::class);
        $objetPropose = $formulaire->get('form.objet');
        $this->verifier(filled($objetPropose), "Formulaire pré-rempli par l'OCR (objet proposé : « {$objetPropose} »).", 'Le formulaire n\'a rien pré-rempli à partir du texte OCR.');

        $formulaire
            ->set('form.sens', 'entrant')
            ->set('form.objet', 'Renouvellement de la licence logiciel de gestion')
            ->set('form.type_document', 'Lettre')
            ->set('form.expediteur_organisation', 'EASYTECH GROUP SA')
            ->set('form.mode_reception', 'depot_physique')
            ->set('form.priorite', 'normale')
            ->set('form.confidentialite', 1)
            ->call('enregistrer')
            ->assertHasNoErrors();
        $courrierA = Courrier::where('objet', 'Renouvellement de la licence logiciel de gestion')->firstOrFail();
        $this->assertSame('en_attente_de_transfert', $courrierA->statut);
        $this->assertNotNull($courrierA->fichier_path);
        $this->assertTrue(Storage::disk('s3')->exists($courrierA->fichier_path));
        $this->ok("Agent : courrier enregistré ({$courrierA->numero_reference}), statut « en attente de transfert », PDF rangé sous {$courrierA->fichier_path}.");

        Queue::assertPushedOn('indexation', IndexCourrierJob::class);
        (new IndexCourrierJob($courrierA))->handle(app(ClassificationService::class));
        $courrierA->refresh();
        $this->verifier($courrierA->service_propose_id === $di->id, 'Classement automatique : DI proposé.', 'Classement automatique : aucune proposition DI (service_propose_id = '.var_export($courrierA->service_propose_id, true).').');

        Livewire::test(MesCourriers::class)->assertSee($courrierA->numero_reference);
        $this->ok('Agent : le courrier apparaît dans « Mes courriers ».');

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierA->id])
            ->set('destinataireTransfertChoisi', $this->comptes['dga']->id)
            ->call('transferer')
            ->assertHasNoErrors();
        $this->assertSame('en_cours_de_transfert', $courrierA->fresh()->statut);
        $this->ok('Agent : « Transférer » à la DGA → « en cours de transfert ».');

        $this->matricePages('Courrier A en attente de la DGA', $courrierA);

        $dga = $this->comptes['dga'];
        $this->actingAs($dga);
        $this->listeEnCours()->assertSee($courrierA->numero_reference);
        $validation = Livewire::test(ShowCourrier::class, ['courrierId' => $courrierA->id]);
        $this->verifier(
            $validation->get('departementSelectionneId') === $departementDi->id,
            'DGA : la cascade Site/Département est pré-remplie sur « Direction Informatique ».',
            'DGA : la cascade n\'est pas pré-remplie sur la proposition (départ. = '.var_export($validation->get('departementSelectionneId'), true).').'
        );
        $validation
            ->set('siteSelectionneId', $site->id)
            ->set('departementSelectionneId', $departementDi->id)
            ->call('validerService')
            ->assertHasNoErrors();
        $courrierA->refresh();
        $this->assertSame('enregistre', $courrierA->statut);
        $this->assertSame($di->id, $courrierA->service_id);
        $this->ok('DGA : service DI confirmé → « enregistré », visible par le responsable DI.');
        $this->verifier(
            ! str_contains((string) $courrierA->fichier_path, '_en_attente'),
            "Le PDF a été déplacé dans le dossier du service ({$courrierA->fichier_path}).",
            "Le PDF est resté sous le dossier provisoire ({$courrierA->fichier_path})."
        );

        $responsableDi = $this->comptes['responsable_di'];
        $collaborateurDi = $this->comptes['collaborateur_di'];
        $this->actingAs($responsableDi);
        $this->listeEnCours()->assertSee($courrierA->numero_reference);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierA->id])
            ->assertSet('collaborateurSelectionne', $collaborateurDi->id)
            ->call('affecter')
            ->assertHasNoErrors();
        $this->assertSame('affecte', $courrierA->fresh()->statut);
        $this->ok('Responsable DI : affecté au collaborateur DI (présélectionné, moins chargé).');

        $this->actingAs($collaborateurDi);
        $this->listeEnCours()->assertSee($courrierA->numero_reference);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierA->id])
            ->call('demarrerTraitement')->assertHasNoErrors()
            ->set('commentaireCirculation', 'Devis du fournisseur vérifié, renouvellement proposé.')
            ->call('soumettrePourValidation')->assertHasNoErrors();
        $this->assertSame('en_validation', $courrierA->fresh()->statut);
        $this->ok('Collaborateur DI : traitement démarré puis soumis pour validation.');

        $this->actingAs($responsableDi);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierA->id])
            ->set('motifRenvoi', 'Joindre le comparatif des offres.')
            ->call('renvoyerPourCorrection')->assertHasNoErrors();
        $this->assertSame('en_traitement', $courrierA->fresh()->statut);
        $this->ok('Responsable DI : renvoyé pour correction (motif obligatoire).');

        $this->actingAs($collaborateurDi);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierA->id])
            ->call('soumettrePourValidation')->assertHasNoErrors();

        $this->actingAs($responsableDi);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierA->id])
            ->call('valider')->assertHasNoErrors();
        $this->assertSame('traite', $courrierA->fresh()->statut);
        $this->ok('Collaborateur re-soumet, responsable valide → « traité ».');

        // Classement dans un dossier personnel (Dossiers & Archives).
        $this->actingAs($collaborateurDi);
        Livewire::test(DossierClassementList::class)
            ->call('ouvrirCreation', null)
            ->set('nomDossier', 'Contrats fournisseurs 2026')
            ->call('creerDossier')
            ->assertHasNoErrors();
        $dossier = DossierClassement::where('nom', 'Contrats fournisseurs 2026')->firstOrFail();
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierA->id])
            ->call('ouvrirClassement')
            ->set('dossierAClasserId', $dossier->id)
            ->call('classerDansDossier')
            ->assertHasNoErrors();
        $this->assertSame($dossier->id, $courrierA->fresh()->dossier_classement_id);
        $this->ok('Collaborateur DI : dossier « Contrats fournisseurs 2026 » créé, courrier classé dedans.');

        // Un dossier personnel ne masque jamais le courrier aux acteurs de
        // son circuit (DECISIONS.md 2026-09-24).
        $this->actingAs($responsableDi);
        $this->assertSame(200, $this->get(route('courriers.show', $courrierA->id))->getStatusCode());
        $this->actingAs($agent);
        $this->assertSame(200, $this->get(route('courriers.show', $courrierA->id))->getStatusCode());
        $this->ok('Responsable DI et agent créateur voient toujours le courrier rangé dans le dossier personnel du collaborateur.');

        // ============ Courrier B — sinistre ============
        $this->section('Courrier B — déclaration de sinistre (routage direct DSIN)');
        $this->actingAs($agent);
        Livewire::test(RegistrationForm::class)
            ->set('form.sens', 'entrant')
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.objet', 'Déclaration de sinistre automobile — véhicule AB-123-CI')
            ->set('form.type_document', 'Sinistre')
            ->set('form.sous_type_sinistre', 'materiel')
            ->set('form.expediteur_nom', 'Koffi Yao')
            ->set('form.mode_reception', 'email')
            ->set('form.priorite', 'haute')
            ->set('form.confidentialite', 1)
            ->call('enregistrer')
            ->assertHasNoErrors();
        $courrierB = Courrier::where('objet', 'like', 'Déclaration de sinistre automobile%')->firstOrFail();
        $this->assertSame('enregistre', $courrierB->statut);
        $this->assertSame($dsin->id, $courrierB->service_id);
        $this->ok("Agent : sinistre enregistré ({$courrierB->numero_reference}) → directement « enregistré » chez DSIN, sans DGA.");

        $this->actingAs($this->comptes['collaborateur_di']);
        $this->verifier(
            $this->get(route('courriers.show', $courrierB->id))->getStatusCode() === 403,
            'Collaborateur DI : accès refusé (403) au sinistre de DSIN.',
            'Collaborateur DI : peut ouvrir le sinistre de DSIN (devrait être refusé).'
        );

        $responsableDsin = $this->comptes['responsable_dsin'];
        $collaborateurDsin = $this->comptes['collaborateur_dsin'];
        $this->actingAs($responsableDsin);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierB->id])
            ->assertSet('collaborateurSelectionne', $collaborateurDsin->id)
            ->call('affecter')->assertHasNoErrors();

        $this->actingAs($collaborateurDsin);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierB->id])
            ->call('demarrerTraitement')->assertHasNoErrors()
            ->set('motifAttente', 'Constat amiable manquant — demandé à l\'assuré.')
            ->call('mettreEnAttente')->assertHasNoErrors();
        $this->assertSame('en_attente_information', $courrierB->fresh()->statut);
        $this->ok('Responsable DSIN affecte ; collaborateur DSIN démarre puis met en attente (pièce manquante).');

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierB->id])
            ->call('reprendre')->assertHasNoErrors()
            ->call('soumettrePourValidation')->assertHasNoErrors();

        $this->actingAs($responsableDsin);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierB->id])
            ->call('valider')->assertHasNoErrors();
        $this->assertSame('traite', $courrierB->fresh()->statut);
        $this->ok('Pièce reçue : reprise, soumission, validation par le responsable DSIN → « traité ».');

        // ============ Courrier C — confidentiel ============
        // 2026-10-07 — RegistrationFormConfidentiel fusionné dans
        // RegistrationForm (voir DECISIONS.md "fusion explicitement
        // demandée") : basculerModeConfidentiel(true) remplace le mount()
        // direct d'un composant dédié, form.destinataire/form.confidentialite
        // (CourrierForm, déjà réutilisés par ce mode) remplacent
        // nomEnveloppe/niveauConfidentialite, destinataireChoix (format
        // "user-<id>") remplace destinataireSystemeId.
        $this->section('Courrier C — courrier confidentiel (jamais ouvert, niveau 3)');
        $this->actingAs($agent);
        Livewire::test(RegistrationForm::class)
            ->call('basculerModeConfidentiel', true)
            ->set('form.destinataire', 'Monsieur le Directeur Général — PERSONNEL')
            ->set('form.confidentialite', 3)
            ->set('destinataireChoix', "user-{$dga->id}")
            ->call('enregistrer')
            ->assertHasNoErrors();
        $courrierC = Courrier::where('destinataire', 'Monsieur le Directeur Général — PERSONNEL')->firstOrFail();
        $this->assertNull($courrierC->fichier_path);
        $this->ok("Agent : confidentiel enregistré ({$courrierC->numero_reference}) sans scan, envoyé directement à la DGA.");

        $this->verifier(
            $this->get(route('courriers.accuse-reception', $courrierC->id))->getStatusCode() === 200,
            'Agent : accusé de réception imprimable (200).',
            'Agent : accusé de réception du confidentiel inaccessible ('.$this->get(route('courriers.accuse-reception', $courrierC->id))->getStatusCode().').'
        );
        $this->verifier(
            $this->get(route('courriers.show', $courrierC->id))->getStatusCode() === 403,
            'Agent (niveau 1) : fiche du confidentiel refusée (403).',
            'Agent (niveau 1) : peut ouvrir la fiche d\'un courrier de niveau 3.'
        );

        $this->actingAs($this->comptes['responsable_di']);
        $this->assertSame(403, $this->get(route('courriers.show', $courrierC->id))->getStatusCode());
        $this->ok('Responsable DI (non destinataire) : fiche du confidentiel refusée (403).');

        $this->actingAs($dga);
        $this->assertSame(200, $this->get(route('courriers.show', $courrierC->id))->getStatusCode());
        $this->listeEnCours()->assertSee($courrierC->numero_reference);
        $this->ok('DGA (destinataire, niveau 3) : le pli apparaît dans « À traiter » et sa fiche s\'ouvre (200).');

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierC->id])
            ->assertSet('peutCloturerConfidentiel', true)
            ->assertSee('Marquer comme remis')
            ->call('cloturerConfidentiel')
            ->assertHasNoErrors();
        $this->assertSame('traite', $courrierC->fresh()->statut);
        $this->ok('DGA : « Marquer comme remis » → « traité ».');

        // ============ Archivage automatique (Scheduler) ============
        $this->section('Archivage automatique (tâche planifiée)');
        (new ArchiverCourriersTraitesJob)->handle(app(WorkflowService::class));
        $this->assertSame('archive', $courrierA->fresh()->statut);
        $this->assertSame('archive', $courrierB->fresh()->statut);
        $this->assertSame('archive', $courrierC->fresh()->statut);
        $this->ok('ArchiverCourriersTraitesJob : A, B et C passent « archivé ».');

        $this->actingAs($dga);
        $this->assertSame(200, $this->get(route('courriers.show', $courrierC->id))->getStatusCode());
        $this->ok('DGA : garde la consultation du pli confidentiel archivé.');

        $this->actingAs($collaborateurDi);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierA->id])
            ->assertSet('courrierId', $courrierA->id);
        $this->verifier(
            ! $collaborateurDi->can('traiter', $courrierA->fresh()) && ! $responsableDi->can('valider', $courrierA->fresh()),
            'Courrier archivé : plus aucune action de circuit possible (lecture seule).',
            'Courrier archivé : des actions de circuit restent possibles.'
        );

        $this->actingAs($admin);
        $this->assertSame(200, $this->get(route('courriers.rechercher', ['recherche' => $courrierA->numero_reference]))->getStatusCode());
        $this->ok('Admin : le courrier archivé se retrouve par la recherche (/courriers/rechercher).');

        // ============ Historique ============
        $this->section('Historique immuable de chaque courrier');
        foreach (['A' => $courrierA, 'B' => $courrierB, 'C' => $courrierC] as $lettre => $courrier) {
            // Ordre tel qu'affiché sur la fiche (plus récent en tête), remis
            // dans l'ordre chronologique : doit correspondre exactement à
            // l'ordre d'insertion, même pour des entrées de la même seconde.
            $actions = $courrier->historiques()->pluck('action')->reverse()->values()->all();
            $this->assertSame($courrier->historiques()->reorder()->orderBy('id')->pluck('action')->all(), $actions);
            $this->ok("Courrier {$lettre} ({$courrier->numero_reference}) : ".implode(' → ', $actions));
        }

        $this->matricePages('État final (A et B archivés)', $courrierA);
    }

    // "Tous les courriers" filtré sur les courriers encore en circuit —
    // remplace l'ancienne page "Transferts" (WorkflowQueue, supprimée le
    // 2026-09-24).
    private function listeEnCours()
    {
        return Livewire::withQueryParams(['statut' => CourrierList::STATUT_ACTIFS])->test(CourrierList::class);
    }

    // Toutes les pages GET de l'application, visitées par chaque compte.
    private function matricePages(string $moment, Courrier $courrier): void
    {
        $pages = [
            'Tableau de bord' => route('dashboard'),
            'Numériser' => route('courriers.numeriser-nouveau'),
            'Nouveau courrier' => route('courriers.nouveau'),
            'Courrier confidentiel' => route('courriers.confidentiel'),
            'Mes courriers' => route('courriers.mes-courriers'),
            'Courriers enregistrés' => route('courriers.enregistres'),
            'Courriers en cours' => route('courriers.rechercher', ['statut' => CourrierList::STATUT_ACTIFS]),
            'Rechercher' => route('courriers.rechercher'),
            'Dossiers & Archives' => route('dossiers-classement.index'),
            'Règles de classement' => route('admin.regles'),
            'Organisation' => route('admin.organisation'),
            'Profils' => route('admin.profils'),
            'Utilisateurs' => route('admin.utilisateurs'),
            'Paramètres système' => route('admin.parametres'),
            'Dossier surveillé' => route('admin.dossier-surveille'),
            'Réglages profil' => route('profile.edit'),
            'Apparence' => route('appearance.edit'),
            "Fiche {$courrier->numero_reference}" => route('courriers.show', $courrier->id),
            'Modifier la fiche' => route('courriers.modifier', $courrier->id),
            'Bordereau' => route('courriers.bordereau', $courrier->id),
            'Document PDF' => route('courriers.document', $courrier->id),
        ];

        $lignes = ["\n### Pages visitées — {$moment}\n", '| Page | '.implode(' | ', array_keys($this->comptes)).' |', '|---|'.str_repeat('---|', count($this->comptes))];
        $erreurs = [];

        foreach ($pages as $nom => $url) {
            $cellules = [];

            foreach ($this->comptes as $cle => $compte) {
                $this->actingAs($compte->fresh());
                $statut = $this->get($url)->getStatusCode();
                $cellules[] = $statut;

                if ($statut >= 500) {
                    $erreurs[] = "{$nom} ({$cle}) → {$statut}";
                }
            }

            $lignes[] = "| {$nom} | ".implode(' | ', $cellules).' |';
        }

        $this->journal = [...$this->journal, ...$lignes, ''];

        foreach ($erreurs as $erreur) {
            $this->journal[] = "- ❌ ERREUR SERVEUR : {$erreur}";
        }

        $this->assertSame([], $erreurs, 'Pages en erreur 500 : '.implode(', ', $erreurs));
    }

    private function section(string $titre): void
    {
        $this->journal[] = "\n## {$titre}\n";
    }

    private function ok(string $message): void
    {
        $this->journal[] = "- ✅ {$message}";
    }

    // Constat métier non bloquant : consigné dans le rapport (✅ ou ⚠️)
    // sans arrêter la simulation, pour voir tout le parcours d'un coup.
    private function verifier(bool $condition, string $succes, string $probleme): void
    {
        $this->journal[] = $condition ? "- ✅ {$succes}" : "- ⚠️ {$probleme}";
    }

    private function ecrireRapport(): void
    {
        $entete = '# Simulation du parcours réel — '.now()->format('Y-m-d H:i')."\n\nComptes : ".implode(', ', array_map(fn (User $u) => "{$u->name} ({$u->email})", $this->comptes))."\n";

        file_put_contents(storage_path('logs/simulation-parcours.md'), $entete.implode("\n", $this->journal)."\n");
    }
}
