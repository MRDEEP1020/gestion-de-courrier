<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CourrierNotification;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-10-23 (voir DECISIONS.md "Transmission descendante par la Direction")
// — un courrier peut être transféré à une Direction ; son responsable
// l'accepte puis le transmet à l'un de ses départements, dont le chef
// l'affecte à ses collaborateurs. Le chef de Direction garde la consultation
// (lecture seule) des courriers de ses départements.
//
//   Direction Financière et Comptable (chef : $chefDirection)
//     ├── Service Trésorerie (chef : $chefTresorerie, collaborateur : $collaborateurTresorerie)
//     └── Département Comptabilité (chef : $chefComptabilite)
class TransmissionDirectionTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $chefDirection;

    private User $chefTresorerie;

    private User $chefComptabilite;

    private User $collaborateurTresorerie;

    private OrganizationUnit $site;

    private OrganizationUnit $direction;

    private OrganizationUnit $tresorerie;

    private OrganizationUnit $comptabilite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->utilisateur('Agent');
        $this->chefDirection = $this->utilisateur('Responsable de service');
        $this->chefTresorerie = $this->utilisateur('Responsable de service');
        $this->chefComptabilite = $this->utilisateur('Responsable de service');

        $this->site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);
        $this->direction = $this->unite(OrganizationUnit::TYPE_DIRECTION, 'Direction Financière et Comptable', 'DFC', $this->site, $this->chefDirection);
        $this->tresorerie = $this->unite(OrganizationUnit::TYPE_DEPARTMENT, 'Service Trésorerie', 'TRE', $this->direction, $this->chefTresorerie);
        $this->comptabilite = $this->unite(OrganizationUnit::TYPE_DEPARTMENT, 'Département Comptabilité', 'CPT', $this->direction, $this->chefComptabilite);

        $this->collaborateurTresorerie = $this->utilisateur('Collaborateur', $this->tresorerie->service);
    }

    private function utilisateur(string $nomProfil, ?Service $service = null): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id,
            'service_id' => $service?->id,
        ]);
    }

    private function unite(string $type, string $nom, string $code, OrganizationUnit $parent, User $chef): OrganizationUnit
    {
        $service = Service::factory()->create(['nom' => $nom, 'code' => $code, 'responsable_id' => $chef->id]);

        return OrganizationUnit::factory()->create([
            'type' => $type,
            'name' => $nom,
            'parent_id' => $parent->id,
            'service_id' => $service->id,
            'responsible_user_id' => $chef->id,
        ]);
    }

    private function courrierEnregistreParLAgent(): Courrier
    {
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Facture fournisseur',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'statut' => 'en_attente_de_transfert',
            'service_id' => null,
        ]);

        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $this->agent->id, 'action' => 'creation']);

        return $courrier;
    }

    // Courrier transféré à la Direction et accepté par son chef.
    private function courrierAccepteParLaDirection(): Courrier
    {
        $courrier = $this->courrierEnregistreParLAgent();
        $workflow = app(WorkflowService::class);
        $workflow->transfererAuService($courrier, $this->direction->service, $this->agent);
        $workflow->accepterTransfert($courrier->fresh(), $this->chefDirection);
        // 2026-10-29 — ordre remise → signature → affectation/transmission :
        // décharge remise et signée avant de transmettre.
        $workflow->marquerOriginalRemis($courrier->fresh(), $this->agent);
        $workflow->signerReceptionOriginal($courrier->fresh(), $this->chefDirection);

        return $courrier->fresh();
    }

    public function test_lagent_peut_transferer_directement_a_une_direction(): void
    {
        $courrier = $this->courrierEnregistreParLAgent();
        $this->actingAs($this->agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $this->site->id)
            ->set('directionSelectionneeId', $this->direction->id)
            ->call('transferer')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('en_cours_de_transfert', $courrier->statut);
        $this->assertSame($this->direction->service_id, $courrier->service_id);
    }

    public function test_lagent_peut_toujours_transferer_directement_a_un_departement(): void
    {
        $courrier = $this->courrierEnregistreParLAgent();
        $this->actingAs($this->agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $this->site->id)
            ->set('directionSelectionneeId', $this->direction->id)
            ->set('departementSelectionneId', $this->tresorerie->id)
            ->call('transferer')
            ->assertHasNoErrors();

        $this->assertSame($this->tresorerie->service_id, $courrier->refresh()->service_id);
    }

    public function test_le_chef_de_direction_transmet_a_un_departement_qui_le_recoit_a_affecter(): void
    {
        Notification::fake();
        $courrier = $this->courrierAccepteParLaDirection();
        $this->actingAs($this->chefDirection);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Service Trésorerie')
            ->assertSee('Département Comptabilité')
            ->set('uniteTransmissionId', $this->tresorerie->id)
            ->call('transmettre')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('enregistre', $courrier->statut);
        $this->assertSame($this->tresorerie->service_id, $courrier->service_id);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'transmission')->count());
        Notification::assertSentTo($this->chefTresorerie, CourrierNotification::class, fn ($n) => $n->action === 'transmission' && $n->envoyePar?->id === $this->chefDirection->id);

        // Le chef du département affecte ensuite à son propre collaborateur.
        $this->actingAs($this->chefTresorerie);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('collaborateurSelectionne', $this->collaborateurTresorerie->id)
            ->call('affecter')
            ->assertHasNoErrors();

        $this->assertSame('affecte', $courrier->fresh()->statut);
        $this->assertSame($this->collaborateurTresorerie->id, $courrier->fresh()->affectationCourante->user_id);
    }

    // Décision 4 : le chef de Direction suit (consultation) mais n'agit plus
    // sur un courrier transmis à l'un de ses départements.
    public function test_le_chef_de_direction_garde_la_consultation_sans_pouvoir_agir(): void
    {
        $courrier = $this->courrierAccepteParLaDirection();
        app(WorkflowService::class)->transmettreAUnite($courrier, $this->tresorerie->service, $this->chefDirection);
        $courrier->refresh();
        $chefDirection = User::find($this->chefDirection->id);

        $this->assertTrue($chefDirection->can('view', $courrier));
        $this->assertFalse($chefDirection->can('affecter', $courrier));
        $this->assertFalse($chefDirection->can('valider', $courrier));
        $this->assertFalse($chefDirection->can('transmettre', $courrier));
        $this->assertTrue(Courrier::query()->visiblePar($chefDirection)->whereKey($courrier->id)->exists());

        // Un chef de département ne voit pas les courriers de son voisin.
        $this->assertFalse(User::find($this->chefComptabilite->id)->can('view', $courrier));
    }

    // Règle n°6 — une unité qui n'est pas rattachée à la Direction (postée à
    // la main) est refusée, le courrier ne bouge pas.
    public function test_une_unite_hors_de_la_direction_est_refusee(): void
    {
        $courrier = $this->courrierAccepteParLaDirection();
        $ailleurs = $this->unite(OrganizationUnit::TYPE_DEPARTMENT, 'Département Ailleurs', 'AIL', $this->site, $this->utilisateur('Responsable de service'));
        $this->actingAs($this->chefDirection);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('uniteTransmissionId', $ailleurs->id)
            ->call('transmettre')
            ->assertHasErrors('uniteTransmissionId');

        $this->assertSame($this->direction->service_id, $courrier->refresh()->service_id);
    }

    // Droits refusés (Règle n°7) : un chef de département ne peut pas
    // transmettre un courrier encore au niveau de la Direction — il ne le
    // voit même pas.
    public function test_un_chef_de_departement_ne_peut_pas_transmettre_le_courrier_de_la_direction(): void
    {
        $courrier = $this->courrierAccepteParLaDirection();
        $this->actingAs($this->chefTresorerie);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertForbidden();

        $this->assertSame($this->direction->service_id, $courrier->refresh()->service_id);
    }

    // Seul un courrier ACCEPTÉ se transmet : en attente d'acceptation, le
    // chef de Direction doit d'abord l'accepter (ou le refuser).
    public function test_un_courrier_pas_encore_accepte_ne_peut_pas_etre_transmis(): void
    {
        $courrier = $this->courrierEnregistreParLAgent();
        app(WorkflowService::class)->transfererAuService($courrier, $this->direction->service, $this->agent);

        $this->assertFalse(User::find($this->chefDirection->id)->can('transmettre', $courrier->fresh()));
    }
}
