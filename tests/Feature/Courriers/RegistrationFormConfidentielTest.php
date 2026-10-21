<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\RegistrationForm;
use App\Livewire\Backend\ScanForm;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Module 1 — "Cas particulier : courrier confidentiel" (specifications-modules-GEC.md,
// voir DECISIONS.md "Courrier confidentiel") : jamais scanné, jamais d'OCR,
// jamais de classification, envoyé directement à un destinataire choisi.
// 2026-10-07 — RegistrationFormConfidentiel fusionné dans RegistrationForm
// (voir DECISIONS.md "fusion explicitement demandée") : ce fichier teste
// désormais le MODE confidentiel ($modeConfidentiel) de ce même composant,
// pas une classe séparée. basculerModeConfidentiel(true) remplace le
// mount() direct d'un composant dédié comme point d'entrée réaliste dans
// ce mode.
class RegistrationFormConfidentielTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    public function test_un_agent_enregistre_un_courrier_confidentiel_sans_jamais_le_scanner(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $dga = $this->utilisateurAvecProfil('DGA');
        $agent->destinatairesTransfert()->attach($dga->id);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->call('basculerModeConfidentiel', true)
            ->set('form.destinataire', 'Monsieur le Directeur Général')
            ->set('form.date_mouvement', '2026-09-15')
            ->set('form.confidentialite', 3)
            ->set('destinataireChoix', "user-{$dga->id}")
            ->call('enregistrer')
            ->assertHasNoErrors();

        $courrier = Courrier::latest('id')->firstOrFail();

        $this->assertSame('Monsieur le Directeur Général', $courrier->destinataire);
        $this->assertSame('entrant', $courrier->sens);
        $this->assertSame(3, $courrier->confidentialite);
        $this->assertSame('enregistre', $courrier->statut);
        $this->assertNull($courrier->service_id);
        $this->assertNull($courrier->fichier_path);
        $this->assertNull($courrier->texte_ocr);
        $this->assertSame($dga->id, $courrier->destinataire_transfert_id);
        $this->assertSame(2, CourrierHistorique::where('courrier_id', $courrier->id)->count());
    }

    // 2026-10-07 — un pli confidentiel est parfois adressé à tout un
    // service ("Direction Générale", "RH"), pas seulement à une personne
    // nommée : il atterrit chez le RESPONSABLE du service (lui seul reçoit
    // réellement le pli), qui peut ensuite réaffecter en interne comme pour
    // un courrier normal.
    public function test_un_agent_envoie_un_pli_confidentiel_a_tout_un_service(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $responsable = $this->utilisateurAvecProfil('Responsable de service');
        $service = Service::factory()->create(['nom' => 'Ressources Humaines', 'responsable_id' => $responsable->id]);
        $agent->destinatairesTransfertServices()->attach($service->id);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->call('basculerModeConfidentiel', true)
            ->set('form.destinataire', 'Service RH')
            ->set('form.date_mouvement', '2026-10-07')
            ->set('form.confidentialite', 3)
            ->set('destinataireChoix', "service-{$service->id}")
            ->call('enregistrer')
            ->assertHasNoErrors();

        $courrier = Courrier::latest('id')->firstOrFail();

        $this->assertSame($responsable->id, $courrier->destinataire_transfert_id);
        $this->assertStringContainsString('Ressources Humaines', CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'transfert')->first()->commentaire);
    }

    // Un service SANS responsable désigné ne peut pas recevoir un pli
    // directement — il n'y aurait personne pour réellement le réceptionner.
    public function test_un_service_sans_responsable_est_refuse(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['nom' => 'Service Sans Responsable', 'responsable_id' => null]);
        $agent->destinatairesTransfertServices()->attach($service->id);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->call('basculerModeConfidentiel', true)
            ->set('form.destinataire', 'Service Sans Responsable')
            ->set('form.date_mouvement', '2026-10-07')
            ->set('destinataireChoix', "service-{$service->id}")
            ->call('enregistrer')
            ->assertHasErrors('destinataireChoix');

        $this->assertSame(0, Courrier::count());
    }

    // "Un accusé de réception est généré et remis au déposant" : la
    // réceptionniste qui vient d'enregistrer le courrier doit pouvoir
    // l'imprimer même si son niveau est inférieur à celui du courrier
    // (2026-09-23 — auparavant 403) ; un autre agent, non.
    public function test_lagent_createur_imprime_laccuse_mais_pas_un_autre_agent(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $dga = $this->utilisateurAvecProfil('DGA');
        $agent->destinatairesTransfert()->attach($dga->id);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->call('basculerModeConfidentiel', true)
            ->set('form.destinataire', 'Monsieur le Directeur Général')
            ->set('form.confidentialite', 3)
            ->set('destinataireChoix', "user-{$dga->id}")
            ->call('enregistrer');

        $courrier = Courrier::latest('id')->firstOrFail();

        $this->get(route('courriers.accuse-reception', $courrier))->assertOk();
        // La fiche, elle, reste refusée (niveau 1 < 3).
        $this->get(route('courriers.show', $courrier->id))->assertForbidden();

        $this->actingAs($this->utilisateurAvecProfil('Agent'))
            ->get(route('courriers.accuse-reception', $courrier))
            ->assertForbidden();
    }

    // Règle n°6 — même garde-fou que ShowCourrier/MesCourriers : un
    // destinataire hors de la liste autorisée de l'agent est refusé.
    public function test_un_destinataire_non_autorise_est_refuse(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $dga = $this->utilisateurAvecProfil('DGA');
        // Pas de attach() : $dga n'est pas dans la liste autorisée de $agent.
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->call('basculerModeConfidentiel', true)
            ->set('form.destinataire', 'Madame la Directrice')
            ->set('form.date_mouvement', '2026-09-15')
            ->set('destinataireChoix', "user-{$dga->id}")
            ->call('enregistrer')
            ->assertHasErrors('destinataireChoix');

        $this->assertSame(0, Courrier::count());
    }

    public function test_le_nom_sur_lenveloppe_est_obligatoire(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $dga = $this->utilisateurAvecProfil('DGA');
        $agent->destinatairesTransfert()->attach($dga->id);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->call('basculerModeConfidentiel', true)
            ->set('form.destinataire', '')
            ->set('form.date_mouvement', '2026-09-15')
            ->set('destinataireChoix', "user-{$dga->id}")
            ->call('enregistrer')
            ->assertHasErrors('form.destinataire');

        $this->assertSame(0, Courrier::count());
    }

    // Un Collaborateur n'a pas même le privilège de base courriers.creer —
    // mount() (authorize('create', ...), partagé avec le mode normal) le
    // bloque avant d'atteindre quoi que ce soit de spécifique au mode
    // confidentiel. Le gate confidentiel lui-même (authorize('creerConfidentiel', ...))
    // est couvert séparément par MenuPrivilegesTest (accès direct à
    // /courriers/confidentiel) pour un profil qui, lui, a courriers.creer
    // mais pas courriers.creer_confidentiel.
    public function test_un_collaborateur_na_pas_acces_au_formulaire(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Collaborateur'));

        Livewire::test(RegistrationForm::class)->assertForbidden();
    }

    // Défense en profondeur (voir ScanForm::numeriser()) : même un courrier
    // déjà enregistré ne peut pas être scanné après coup si sa
    // confidentialité change (ex. via EditForm — hors périmètre de cette
    // tâche, voir DECISIONS.md, mais le garde-fou protège quand même ce cas).
    public function test_un_courrier_confidentiel_ne_peut_pas_etre_scanne(): void
    {
        Storage::fake('s3');
        // niveau_confidentialite explicite (2026-09-21, voir
        // CourrierConfidentialiteTest) : sans clearance suffisante, l'agent
        // ne pourrait même plus OUVRIR ce courrier 'confidentiel' (niveau 2)
        // — CourrierPolicy::update() le bloquerait avant d'atteindre le
        // garde-fou $estConfidentiel que ce test vise réellement à prouver.
        $agent = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id, 'niveau_confidentialite' => 2]);
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-2026-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Correspondance confidentielle (non ouverte)',
            'type_document' => 'Correspondance confidentielle',
            'mode_reception' => 'depot_physique',
            'confidentialite' => 2,
            'statut' => 'enregistre',
        ]);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);
        $this->actingAs($agent);

        Livewire::test(ScanForm::class, ['courrierId' => $courrier->id])
            ->set('document', UploadedFile::fake()->image('scan.jpg', 1700, 2200))
            ->call('numeriser');

        $this->assertNull($courrier->refresh()->fichier_path);
        $this->assertSame('non_traite', $courrier->ocr_statut);
    }

    // Garde-fou direct ajouté par la fusion (voir
    // RegistrationForm::numeriserAutomatique()/importerFichier()) : même si
    // une requête forgée appelait ces actions pendant que $modeConfidentiel
    // est actif côté serveur, aucun brouillon ne doit être créé.
    public function test_importer_un_fichier_est_ignore_en_mode_confidentiel(): void
    {
        Storage::fake('s3');
        $agent = $this->utilisateurAvecProfil('Agent');
        $dga = $this->utilisateurAvecProfil('DGA');
        $agent->destinatairesTransfert()->attach($dga->id);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->call('basculerModeConfidentiel', true)
            ->set('document', UploadedFile::fake()->image('scan.jpg', 1700, 2200))
            ->call('importerFichier');

        $this->assertDatabaseCount('courrier_brouillons', 0);
    }
}
