<?php

namespace Tests\Feature\Admin;

use App\Livewire\Backend\UserList;
use App\Models\Courrier;
use App\Models\OrganizationUnit;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Module "Organisation" v2 (2026-09-22, spec §18/§19) — QUATRIÈME gate
// cumulatif de CourrierPolicy/Courrier::scopeVisiblePar() : le périmètre
// d'accès organisationnel, opt-in comme le gate dossier (voir
// CourrierDossierAccesTest, même principe). Un utilisateur sans périmètre
// assigné n'est jamais restreint par ce gate ; un utilisateur avec un
// périmètre ne voit que les courriers dont le service (via le pont
// OrganizationUnit::service_id) est sous l'une des entités accordées.
class PerimetreOrganisationTest extends TestCase
{
    use RefreshDatabase;

    private function courrier(?Service $service, array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => $service?->id,
        ], $attributs));
    }

    private function utilisateur(string $profil, int $niveau = 5): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $profil])->id,
            'niveau_confidentialite' => $niveau,
        ]);
    }

    private function accorderVoirTout(User $user): void
    {
        Privilege::where('cle', 'courriers.voir_tout')->firstOrFail()->users()->attach($user->id);
    }

    // Agence Douala (site) > Sinistres (département) > Sinistre Santé
    // (service, ponté au service réel donné) — sous-arbre à trois niveaux
    // pour vérifier que idsServicesReelsSousArbre() descend bien jusqu'au
    // service ponté, pas seulement le nœud accordé lui-même.
    private function sousArbrePonteAuService(Service $serviceReel): array
    {
        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE, 'name' => 'Agence Douala']);
        $departement = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'name' => 'Sinistres', 'parent_id' => $site->id]);
        $serviceNoeud = OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_SERVICE,
            'name' => 'Sinistre Santé',
            'parent_id' => $departement->id,
            'service_id' => $serviceReel->id,
        ]);

        return [$site, $departement, $serviceNoeud];
    }

    public function test_un_utilisateur_sans_perimetre_nest_pas_restreint(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);

        $this->assertTrue($utilisateur->can('view', $courrier));
    }

    // Cas critique : voir_tout ne suffit plus si le courrier est hors du
    // périmètre accordé — même principe que le gate dossier (accorderVoirTout
    // seul ne suffit jamais, les gates se cumulent).
    public function test_voir_tout_ne_suffit_plus_hors_perimetre(): void
    {
        $serviceDansPerimetre = Service::factory()->create();
        $serviceHorsPerimetre = Service::factory()->create();
        [$site] = $this->sousArbrePonteAuService($serviceDansPerimetre);

        $courrierHorsPerimetre = $this->courrier($serviceHorsPerimetre);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);
        $utilisateur->organizationUnitsPerimetre()->attach($site->id);

        $utilisateurFrais = User::find($utilisateur->id);
        $this->assertFalse($utilisateurFrais->can('view', $courrierHorsPerimetre));
    }

    public function test_un_perimetre_sur_un_site_voit_les_services_pontes_sous_son_sous_arbre(): void
    {
        $serviceReel = Service::factory()->create();
        [$site] = $this->sousArbrePonteAuService($serviceReel);

        $courrierDansPerimetre = $this->courrier($serviceReel);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);
        $utilisateur->organizationUnitsPerimetre()->attach($site->id);

        $utilisateurFrais = User::find($utilisateur->id);
        $this->assertTrue($utilisateurFrais->can('view', $courrierDansPerimetre));
    }

    public function test_un_perimetre_sur_un_site_ne_voit_pas_les_services_dune_autre_agence(): void
    {
        $serviceAgenceA = Service::factory()->create();
        $serviceAgenceB = Service::factory()->create();
        [$siteA] = $this->sousArbrePonteAuService($serviceAgenceA);

        $siteB = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE, 'name' => 'Agence Yaoundé']);
        $departementB = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => $siteB->id]);
        OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SERVICE, 'parent_id' => $departementB->id, 'service_id' => $serviceAgenceB->id]);

        $courrierAgenceB = $this->courrier($serviceAgenceB);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);
        $utilisateur->organizationUnitsPerimetre()->attach($siteA->id);

        $utilisateurFrais = User::find($utilisateur->id);
        $this->assertFalse($utilisateurFrais->can('view', $courrierAgenceB));
    }

    // Un courrier entrant en attente de validation DGA (service_id encore
    // null) n'est jamais restreint par ce gate — sinon validerService()
    // serait cassée pour tout DGA ayant un périmètre assigné (voir
    // Courrier::estDansLePerimetreDe()).
    public function test_un_courrier_sans_service_encore_assigne_nest_pas_restreint(): void
    {
        [$site] = $this->sousArbrePonteAuService(Service::factory()->create());
        $courrierEnAttente = $this->courrier(null);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);
        $utilisateur->organizationUnitsPerimetre()->attach($site->id);

        $utilisateurFrais = User::find($utilisateur->id);
        $this->assertTrue($utilisateurFrais->can('view', $courrierEnAttente));
    }

    public function test_gerer_tout_ne_court_circuite_pas_le_gate_perimetre(): void
    {
        $serviceHorsPerimetre = Service::factory()->create();
        [$site] = $this->sousArbrePonteAuService(Service::factory()->create());
        $courrierHorsPerimetre = $this->courrier($serviceHorsPerimetre);

        $admin = $this->utilisateur('Administrateur');
        $admin->organizationUnitsPerimetre()->attach($site->id);

        $adminFrais = User::find($admin->id);
        $this->assertFalse($adminFrais->can('view', $courrierHorsPerimetre));
    }

    // Périmètre au niveau requête — même règle que la Policy, vérifiée via
    // Courrier::scopeVisiblePar().
    public function test_visible_par_exclut_les_courriers_hors_perimetre(): void
    {
        $serviceHorsPerimetre = Service::factory()->create();
        [$site] = $this->sousArbrePonteAuService(Service::factory()->create());
        $courrierHorsPerimetre = $this->courrier($serviceHorsPerimetre);

        $utilisateur = $this->utilisateur('Administrateur');
        $utilisateur->organizationUnitsPerimetre()->attach($site->id);

        $utilisateurFrais = User::find($utilisateur->id);
        $visibles = Courrier::query()->visiblePar($utilisateurFrais)->pluck('id');

        $this->assertFalse($visibles->contains($courrierHorsPerimetre->id));
    }

    public function test_visible_par_inclut_les_courriers_dans_le_perimetre(): void
    {
        $serviceReel = Service::factory()->create();
        [$site] = $this->sousArbrePonteAuService($serviceReel);
        $courrierDansPerimetre = $this->courrier($serviceReel);

        $utilisateur = $this->utilisateur('Administrateur');
        $utilisateur->organizationUnitsPerimetre()->attach($site->id);

        $utilisateurFrais = User::find($utilisateur->id);
        $visibles = Courrier::query()->visiblePar($utilisateurFrais)->pluck('id');

        $this->assertTrue($visibles->contains($courrierDansPerimetre->id));
    }

    // ===== UI "Utilisateurs & Accès" — onglet "Périmètre d'accès" =====

    public function test_longlet_perimetre_ecrit_bien_sur_organization_unit_perimetre_user(): void
    {
        $admin = $this->utilisateur('Administrateur');
        $cible = $this->utilisateur('Responsable de service');
        $unite = OrganizationUnit::factory()->create();
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $cible->id)
            ->call('ajouterPerimetre', $unite->id);

        $this->assertTrue($cible->organizationUnitsPerimetre()->where('organization_units.id', $unite->id)->exists());

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $cible->id)
            ->call('retirerPerimetre', $unite->id);

        $this->assertFalse($cible->organizationUnitsPerimetre()->where('organization_units.id', $unite->id)->exists());
    }

    public function test_longlet_perimetre_selection_multiple_ecrit_bien_sur_la_table_pivot(): void
    {
        $admin = $this->utilisateur('Administrateur');
        $cible = $this->utilisateur('Responsable de service');
        $uniteA = OrganizationUnit::factory()->create();
        $uniteB = OrganizationUnit::factory()->create();
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $cible->id)
            ->set('selectionPerimetreDisponibles', [$uniteA->id, $uniteB->id])
            ->call('ajouterSelectionPerimetre');

        $this->assertSame(2, $cible->organizationUnitsPerimetre()->count());

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $cible->id)
            ->set('selectionPerimetreAssignes', [$uniteA->id, $uniteB->id])
            ->call('retirerSelectionPerimetre');

        $this->assertSame(0, $cible->organizationUnitsPerimetre()->count());
    }
}
