<?php

namespace Tests\Feature\Admin;

use App\Livewire\Backend\OrganisationIndex;
use App\Models\OrganizationUnit;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Services créés depuis l'Organisation (2026-09-23, voir DECISIONS.md) : un
// Service / Sous-service de l'organigramme crée ou relie automatiquement son
// service réel (table `services`, utilisée par le routage des courriers).
class ServiceReelSynchroniseurTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OrganizationUnit $departement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['profil_id' => Profil::where('nom', 'Administrateur')->value('id')]);
        $this->departement = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'name' => 'Direction Technique']);
        $this->actingAs($this->admin);
    }

    private function creerService(string $nom, array $champs = [])
    {
        $test = Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation', $this->departement->id)
            ->set('typeNoeud', OrganizationUnit::TYPE_SERVICE)
            ->set('nomNoeud', $nom);

        foreach ($champs as $champ => $valeur) {
            $test->set($champ, $valeur);
        }

        return $test->call('enregistrerNoeud')->assertHasNoErrors();
    }

    public function test_creer_un_service_cree_son_service_reel(): void
    {
        $chef = User::factory()->create();

        $this->creerService('Contentieux Auto', ['codeNoeud' => 'CTX-AUTO', 'responsableIdNoeud' => $chef->id]);

        $unite = OrganizationUnit::where('name', 'Contentieux Auto')->firstOrFail();
        $service = Service::where('nom', 'Contentieux Auto')->firstOrFail();

        $this->assertSame($service->id, $unite->service_id);
        $this->assertSame('CTX-AUTO', $service->code);
        $this->assertSame($chef->id, $service->responsable_id);
        $this->assertTrue($service->actif);
    }

    // 2026-10-05 (multi-site, voir DECISIONS.md) — comportement INVERSÉ par
    // rapport à l'ancienne version de ce test : un `Service` existant mais
    // JAMAIS relié à aucun nœud de l'organigramme (créé hors Organisation,
    // ex. un reliquat d'avant le multi-site) n'est PLUS réutilisé par
    // simple égalité de nom. `services.nom` n'est plus unique en base
    // (migration 2026_10_05_040000) précisément pour permettre à deux
    // agences différentes d'avoir chacune leur propre service du même nom —
    // la réutilisation ne vaut donc plus que pour un AUTRE nœud du MÊME
    // Site, jamais pour un Service orphelin trouvé par son seul nom.
    public function test_un_service_existant_mais_jamais_relie_a_un_noeud_nest_plus_reutilise(): void
    {
        $existant = Service::factory()->create(['nom' => 'Informatique', 'code' => 'INF']);

        $this->creerService('Informatique');

        $this->assertSame(2, Service::where('nom', 'Informatique')->count());
        $this->assertNotSame($existant->id, OrganizationUnit::where('name', 'Informatique')->value('service_id'));
    }

    // Deux nœuds de même nom, chacun sous un Site différent, doivent chacun
    // avoir leur PROPRE service réel — jamais le même (demande explicite de
    // l'utilisateur, 2026-10-05 : "EACH CITY HAS HIS OWN DEPARTMENTS...
    // USERS ON IT SHOULD SEE THE DATAS OF THAT SITE NOT THE OTHERS").
    public function test_meme_nom_sous_deux_sites_differents_cree_deux_services_distincts(): void
    {
        $siteA = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE, 'name' => 'Douala']);
        $siteB = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE, 'name' => 'Yaoundé']);
        $departementA = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => $siteA->id]);
        $departementB = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => $siteB->id]);

        $creerSous = function (OrganizationUnit $departement, string $nom) {
            return Livewire::test(OrganisationIndex::class)
                ->call('ouvrirCreation', $departement->id)
                ->set('typeNoeud', OrganizationUnit::TYPE_SERVICE)
                ->set('nomNoeud', $nom)
                ->call('enregistrerNoeud')
                ->assertHasNoErrors();
        };

        $creerSous($departementA, 'DSIN');
        $creerSous($departementB, 'DSIN');

        $this->assertSame(2, Service::where('nom', 'DSIN')->count());
        $idA = OrganizationUnit::where('parent_id', $departementA->id)->where('name', 'DSIN')->value('service_id');
        $idB = OrganizationUnit::where('parent_id', $departementB->id)->where('name', 'DSIN')->value('service_id');
        $this->assertNotSame($idA, $idB);
    }

    // Deux nœuds de même nom sous le MÊME Site, eux, partagent bien le même
    // service réel — comportement inchangé au sein d'une seule agence.
    public function test_meme_nom_sous_le_meme_site_partage_le_meme_service(): void
    {
        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE, 'name' => 'Douala']);
        $departementParent = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => $site->id]);
        $autreDepartementMemeSite = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => $site->id]);

        $creerSous = function (OrganizationUnit $departement, string $nom) {
            return Livewire::test(OrganisationIndex::class)
                ->call('ouvrirCreation', $departement->id)
                ->set('typeNoeud', OrganizationUnit::TYPE_SERVICE)
                ->set('nomNoeud', $nom)
                ->call('enregistrerNoeud')
                ->assertHasNoErrors();
        };

        $creerSous($departementParent, 'Courrier Général');
        $creerSous($autreDepartementMemeSite, 'Courrier Général');

        $this->assertSame(1, Service::where('nom', 'Courrier Général')->count());
    }

    public function test_code_derive_du_nom_et_unique(): void
    {
        Service::factory()->create(['nom' => 'Autre', 'code' => 'RC']);

        $this->creerService('Recouvrement Contentieux');

        $this->assertSame('RC-2', Service::where('nom', 'Recouvrement Contentieux')->value('code'));
    }

    // 2026-10-09 — niveau Direction ajouté (voir le journal de développement) :
    // "department" est désormais synchronisé (comme Grandes Entreprises/
    // Para Étatiques, enfants réels d'une Direction, qui reçoivent du
    // courrier) — seuls Company/Site/Direction restent purement structurels,
    // jamais pontés. Ce test couvrait auparavant "department", remplacé ici
    // par "direction" pour tester la même règle sur le type qui en hérite.
    public function test_une_direction_ne_cree_pas_de_service(): void
    {
        $avant = Service::count();

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation', $this->departement->parent_id)
            ->set('typeNoeud', OrganizationUnit::TYPE_DIRECTION)
            ->set('nomNoeud', 'Direction Commerciale')
            ->call('enregistrerNoeud')
            ->assertHasNoErrors();

        $this->assertSame($avant, Service::count());
    }

    public function test_un_departement_cree_bien_un_service(): void
    {
        $avant = Service::count();

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation', $this->departement->parent_id)
            ->set('typeNoeud', OrganizationUnit::TYPE_DEPARTMENT)
            ->set('nomNoeud', 'Direction Commerciale')
            ->call('enregistrerNoeud')
            ->assertHasNoErrors();

        $this->assertSame($avant + 1, Service::count());
        $this->assertNotNull(OrganizationUnit::where('name', 'Direction Commerciale')->value('service_id'));
    }

    public function test_renommer_un_service_cree_automatiquement_renomme_le_service_reel(): void
    {
        $this->creerService('Sinistres Auto');
        $unite = OrganizationUnit::where('name', 'Sinistres Auto')->firstOrFail();

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirModification', $unite->id)
            ->set('nomNoeud', 'Sinistres Automobile')
            ->call('enregistrerNoeud')
            ->assertHasNoErrors();

        $this->assertSame('Sinistres Automobile', $unite->fresh()->service->nom);
    }

    // Relié à la main à un service de nom différent : ne jamais le renommer.
    public function test_un_service_relie_a_la_main_nest_jamais_renomme(): void
    {
        $sinistres = Service::factory()->create(['nom' => 'Sinistres', 'code' => 'SIN']);
        $this->creerService('Sinistre Santé', ['servicePontNoeud' => $sinistres->id]);
        $unite = OrganizationUnit::where('name', 'Sinistre Santé')->firstOrFail();

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirModification', $unite->id)
            ->set('nomNoeud', 'Sinistres Santé & Prévoyance')
            ->call('enregistrerNoeud');

        $this->assertSame('Sinistres', $sinistres->fresh()->nom);
    }

    public function test_desactiver_le_noeud_desactive_le_service_sauf_sil_est_partage(): void
    {
        $this->creerService('Courrier Interne');
        $unite = OrganizationUnit::where('name', 'Courrier Interne')->firstOrFail();

        Livewire::test(OrganisationIndex::class)->call('basculerStatut', $unite->id);
        $this->assertFalse($unite->fresh()->service->actif);

        Livewire::test(OrganisationIndex::class)->call('basculerStatut', $unite->id);
        $this->assertTrue($unite->fresh()->service->actif);

        // Un second nœud actif partage le même service : il reste actif.
        OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SERVICE, 'service_id' => $unite->service_id]);
        Livewire::test(OrganisationIndex::class)->call('basculerStatut', $unite->id);
        $this->assertTrue($unite->fresh()->service->actif);
    }

    public function test_definir_un_responsable_le_reporte_sur_le_service(): void
    {
        $this->creerService('Réclamations');
        $unite = OrganizationUnit::where('name', 'Réclamations')->firstOrFail();
        $chef = User::factory()->create();

        Livewire::test(OrganisationIndex::class)
            ->set('noeudId', $unite->id)
            ->call('definirCommeResponsable', $chef->id);

        $this->assertSame($chef->id, $unite->fresh()->service->responsable_id);
    }
}
