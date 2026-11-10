<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\OrganisationIndex;
use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\ServiceReelSynchroniseur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-10-26 (voir DECISIONS.md "Titres des chefs et Direction Générale"),
// notes de l'utilisateur : « Directeur → Direction, Chef de département →
// Département, Responsable → Service ».
//
//   Direction Générale (Site, Directeur : $dg)
//     ├── Service Juridique et Contentieux (Service rattaché directement, Responsable : $chefJuridique)
//     ├── Département Informatique (Chef de département : $chefInformatique)
//     └── Direction du Développement Commercial (Directeur : $chefDdc)
//           └── Service Santé (Service rattaché directement, Responsable : $chefSante)
class DirectionGeneraleEtTitresTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $dg;

    private User $chefInformatique;

    private OrganizationUnit $site;

    private OrganizationUnit $juridique;

    private OrganizationUnit $informatique;

    private OrganizationUnit $ddc;

    private OrganizationUnit $sante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->utilisateur('Agent');
        $this->dg = $this->utilisateur('DG');
        $this->chefInformatique = $this->utilisateur('Responsable de service');

        $this->site = $this->unite(OrganizationUnit::TYPE_SITE, 'Direction Générale', 'DG', null, $this->dg);
        $this->juridique = $this->unite(OrganizationUnit::TYPE_SERVICE, 'Service Juridique et Contentieux', 'JUR', $this->site, $this->utilisateur('Responsable de service'));
        $this->informatique = $this->unite(OrganizationUnit::TYPE_DEPARTMENT, 'Département Informatique', 'DI', $this->site, $this->chefInformatique);
        $this->ddc = $this->unite(OrganizationUnit::TYPE_DIRECTION, 'Direction du Développement Commercial', 'DDC', $this->site, $this->utilisateur('Responsable de service'));
        $this->sante = $this->unite(OrganizationUnit::TYPE_SERVICE, 'Service Santé', 'SAN', $this->ddc, $this->utilisateur('Responsable de service'));
    }

    private function utilisateur(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    private function unite(string $type, string $nom, string $code, ?OrganizationUnit $parent, User $chef): OrganizationUnit
    {
        $service = Service::factory()->create(['nom' => $nom, 'code' => $code, 'responsable_id' => $chef->id]);

        return OrganizationUnit::factory()->create([
            'type' => $type,
            'name' => $nom,
            'parent_id' => $parent?->id,
            'service_id' => $service->id,
            'responsible_user_id' => $chef->id,
        ]);
    }

    private function courrierATransferer(): Courrier
    {
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier adressé au Directeur Général',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'statut' => 'en_attente_de_transfert',
            'service_id' => null,
        ]);

        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $this->agent->id, 'action' => 'creation']);

        return $courrier;
    }

    public function test_le_titre_du_chef_suit_le_niveau_de_lentite(): void
    {
        $this->assertSame('Directeur', OrganizationUnit::titreChef(OrganizationUnit::TYPE_SITE));
        $this->assertSame('Directeur', $this->ddc->titreDuChef());
        $this->assertSame('Chef de département', $this->informatique->titreDuChef());
        $this->assertSame('Responsable', $this->sante->titreDuChef());
        $this->assertSame('Responsable', OrganizationUnit::titreChef(OrganizationUnit::TYPE_SUB_SERVICE));
        $this->assertSame('Chef de département', $this->informatique->service->titreChef());
    }

    public function test_la_page_organisation_affiche_le_titre_du_chef_selon_le_niveau(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(OrganisationIndex::class)
            ->call('selectionnerNoeud', $this->informatique->id)
            ->assertSee('Chef de département')
            ->call('selectionnerNoeud', $this->ddc->id)
            ->assertSee('Ajouter un service directement');
    }

    // Un Service rattaché directement à une Direction (sans Département)
    // est proposé au niveau « Département / Service » et reçoit le courrier.
    public function test_un_service_rattache_directement_a_une_direction_peut_recevoir_un_transfert(): void
    {
        $courrier = $this->courrierATransferer();
        $this->actingAs($this->agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $this->site->id)
            ->set('directionSelectionneeId', $this->ddc->id)
            ->assertSee('Service Santé')
            ->set('departementSelectionneId', $this->sante->id)
            ->call('transferer')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('en_cours_de_transfert', $courrier->statut);
        $this->assertSame($this->sante->service_id, $courrier->service_id);
    }

    public function test_un_service_rattache_directement_au_site_peut_recevoir_un_transfert(): void
    {
        $courrier = $this->courrierATransferer();
        $this->actingAs($this->agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $this->site->id)
            ->set('departementSelectionneId', $this->juridique->id)
            ->call('transferer')
            ->assertHasNoErrors();

        $this->assertSame($this->juridique->service_id, $courrier->refresh()->service_id);
    }

    // La Direction Générale (le Site) reçoit le courrier ; l'écran rappelle
    // à qui il part avant confirmation ; le DG l'accepte puis le transmet à
    // l'une des entités placées sous lui.
    public function test_la_direction_generale_recoit_un_transfert_puis_le_transmet(): void
    {
        $courrier = $this->courrierATransferer();
        $this->actingAs($this->agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $this->site->id)
            ->assertSee('Destinataire')
            ->assertSee($this->dg->name)
            ->call('transferer')
            ->assertHasNoErrors();

        $this->assertSame($this->site->service_id, $courrier->refresh()->service_id);

        $dg = User::find($this->dg->id);
        $this->actingAs($dg);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('accepterTransfert')
            ->assertHasNoErrors();

        $this->assertSame('enregistre', $courrier->refresh()->statut);

        // 2026-10-29 — ordre remise → signature → transmission : l'accueil
        // remet l'original, la DG signe sa réception.
        $this->actingAs($this->agent);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('marquerOriginalRemis')
            ->assertHasNoErrors();
        $this->actingAs($dg);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('signerReceptionOriginal')
            ->assertHasNoErrors();

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Chef de département')
            ->set('uniteTransmissionId', $this->informatique->id)
            ->call('transmettre')
            ->assertHasNoErrors();

        $this->assertSame($this->informatique->service_id, $courrier->refresh()->service_id);
        $this->assertSame('enregistre', $courrier->statut);
    }

    // Le message d'erreur nomme le chef manquant selon le niveau.
    public function test_une_entite_sans_chef_est_refusee_avec_le_titre_du_niveau(): void
    {
        $this->informatique->service->update(['responsable_id' => null]);
        $courrier = $this->courrierATransferer();
        $this->actingAs($this->agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $this->site->id)
            ->set('departementSelectionneId', $this->informatique->id)
            ->call('transferer')
            ->assertHasErrors('uniteSelectionneeId')
            ->assertSee('n\'a pas de chef de département désigné');

        $this->assertSame('en_attente_de_transfert', $courrier->refresh()->statut);
    }

    // Un Site créé depuis la page Organisation reçoit son service réel,
    // comme une Direction (voir ServiceReelSynchroniseur::TYPES_SYNCHRONISES).
    public function test_un_site_est_relie_a_un_service_reel(): void
    {
        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE, 'name' => 'Agence de Yaoundé', 'code' => 'YDE']);

        app(ServiceReelSynchroniseur::class)->synchroniser($site);

        $this->assertNotNull($site->refresh()->service_id);
        $this->assertSame('Agence de Yaoundé', $site->service->nom);
    }
}
