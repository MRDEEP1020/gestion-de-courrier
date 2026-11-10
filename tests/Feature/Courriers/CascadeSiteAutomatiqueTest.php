<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-10-30 (« la direction doit être automatique selon où se trouve
// l'utilisateur dans le site… il ne va plus choisir que les autres niveaux »,
// précisé : le Site) — voir ChoixServiceCascade::siteAutomatique().
//
//   Site Douala (Site A)              Site Yaoundé (Site B)
//     └── Département Sinistres         └── Département Yaoundé
class CascadeSiteAutomatiqueTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private OrganizationUnit $siteA;

    private OrganizationUnit $siteB;

    private OrganizationUnit $sinistres;

    private OrganizationUnit $yaounde;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->utilisateur('Agent');
        $this->siteA = $this->unite(OrganizationUnit::TYPE_SITE, 'Site Douala', 'SDA', null);
        $this->siteB = $this->unite(OrganizationUnit::TYPE_SITE, 'Site Yaoundé', 'SYA', null);
        $this->sinistres = $this->unite(OrganizationUnit::TYPE_DEPARTMENT, 'Département Sinistres', 'SIN', $this->siteA);
        $this->yaounde = $this->unite(OrganizationUnit::TYPE_DEPARTMENT, 'Département Yaoundé', 'YAO', $this->siteB);
    }

    private function utilisateur(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    private function unite(string $type, string $nom, string $code, ?OrganizationUnit $parent): OrganizationUnit
    {
        $chef = $this->utilisateur('Responsable de service');
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
            'objet' => 'Déclaration de sinistre',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'statut' => 'en_attente_de_transfert',
            'service_id' => null,
        ]);

        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $this->agent->id, 'action' => 'creation']);

        return $courrier;
    }

    public function test_le_site_de_lagent_est_rempli_automatiquement_et_le_menu_disparait(): void
    {
        $this->agent->organizationUnits()->attach($this->siteA->id, ['is_primary' => true]);
        $courrier = $this->courrierATransferer();
        $this->actingAs($this->agent);

        // 2026-10-30 (« instead of displaying it to them, remove it ») — le
        // Site n'est plus affiché du tout : ni menu, ni ligne « Site : … ».
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('siteSelectionneId', $this->siteA->id)
            ->assertDontSee('('.__('automatique').')')
            ->assertDontSee('Site Yaoundé')
            ->set('departementSelectionneId', $this->sinistres->id)
            ->call('transferer')
            ->assertHasNoErrors();

        $this->assertSame($this->sinistres->service_id, $courrier->refresh()->service_id);
    }

    // Règle n°6 — une requête modifiée qui vise un autre Site est ramenée au
    // Site de la personne : le département de l'autre Site est refusé.
    public function test_un_autre_site_poste_a_la_main_est_refuse(): void
    {
        $this->agent->organizationUnits()->attach($this->siteA->id, ['is_primary' => true]);
        $courrier = $this->courrierATransferer();
        $this->actingAs($this->agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $this->siteB->id)
            ->set('departementSelectionneId', $this->yaounde->id)
            ->call('transferer')
            ->assertHasErrors('uniteSelectionneeId');

        $courrier->refresh();
        $this->assertSame('en_attente_de_transfert', $courrier->statut);
        $this->assertNull($courrier->service_id);
    }

    // Sans rattachement : plusieurs Sites → le menu « Site » reste proposé ;
    // un seul Site → il est utilisé d'office.
    public function test_sans_rattachement_le_menu_reste_si_plusieurs_sites(): void
    {
        $courrier = $this->courrierATransferer();
        $this->actingAs($this->agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('siteSelectionneId', null)
            ->assertSee('Site Douala')
            ->assertSee('Site Yaoundé');

        $this->siteB->update(['status' => OrganizationUnit::STATUT_INACTIF]);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('siteSelectionneId', $this->siteA->id)
            ->assertDontSee('Site Yaoundé');
    }
}
