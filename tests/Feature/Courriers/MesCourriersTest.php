<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\MesCourriers;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Profil;
use App\Models\Service;
use App\Models\TransfertService;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Module 1/4 — page dédiée réceptionniste (2026-09-15, "new page for
// receptionist", suite de "where can i see all receptionist register doc
// enttand and with buttons for select bulk and transfere to a supervisor")
// — voir DECISIONS.md "Synchronisation avec le nouveau document SRS-GEC.pdf".
// 2026-10-23 (voir DECISIONS.md "Transfert direct au service et acceptation")
// — transfert vers un service (plus vers une personne), onglet "Acceptés" et
// remise des originaux papier.
class MesCourriersTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    private function courrier(Service $service, array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => $service->id,
        ], $attributs));
    }

    private function courrierDe(User $agent, Service $service, array $attributs = []): Courrier
    {
        $courrier = $this->courrier($service, $attributs);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        return $courrier;
    }

    public function test_un_agent_ne_voit_que_ses_propres_courriers(): void
    {
        $agent = $this->utilisateur('Agent');
        $autreAgent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);

        $lemien = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $pasLeMien = $this->courrierDe($autreAgent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->assertSee($lemien->numero_reference)
            ->assertDontSee($pasLeMien->numero_reference);
    }

    private function serviceAvecResponsable(string $code = 'DST'): Service
    {
        return Service::factory()->create(['code' => $code, 'responsable_id' => $this->utilisateur('Responsable de service')->id]);
    }

    private function departementPonte(Service $service): OrganizationUnit
    {
        return OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'parent_id' => null,
            'service_id' => $service->id,
        ]);
    }

    public function test_seuls_les_courriers_entrant_apparaissent(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);

        $entrant = $this->courrierDe($agent, $service, ['sens' => 'entrant', 'statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $sortant = $this->courrierDe($agent, $service, ['sens' => 'sortant', 'statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->assertSee($entrant->numero_reference)
            ->assertDontSee($sortant->numero_reference);
    }

    public function test_chaque_onglet_montre_la_bonne_etape_du_transfert(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = $this->serviceAvecResponsable();
        $workflow = app(WorkflowService::class);

        $aTransferer = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $enAttente = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $accepte = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $workflow->transfererAuService($enAttente, $service, $agent);
        $workflow->transfererAuService($accepte, $service, $agent);
        $workflow->accepterTransfert($accepte->fresh(), $service->responsable);

        $this->actingAs($agent);

        $component = Livewire::test(MesCourriers::class)
            ->assertSee($aTransferer->numero_reference)
            ->assertDontSee($enAttente->numero_reference)
            ->assertDontSee($accepte->numero_reference);

        $component->call('changerOnglet', 'en_cours_de_transfert')
            ->assertSee($enAttente->numero_reference)
            ->assertDontSee($aTransferer->numero_reference);

        $component->call('changerOnglet', 'acceptes')
            ->assertSee($accepte->numero_reference)
            ->assertSee(__('À remettre'))
            ->assertDontSee($enAttente->numero_reference);
    }

    // 2026-10-30 (« they have to also see the search and filter inside Mes
    // courriers ») — recherche (référence, objet, expéditeur) et filtres dans
    // l'onglet courant, réinitialisables ; jamais au-delà de ses courriers.
    public function test_la_recherche_et_les_filtres_trient_ses_courriers(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);
        $sinistre = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null, 'objet' => 'Déclaration de sinistre', 'type_document' => 'Réclamation', 'priorite' => 'urgente']);
        $facture = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null, 'objet' => 'Facture fournisseur', 'expediteur_organisation' => 'Creclink', 'type_document' => 'Facture', 'date_mouvement' => now()->subMonths(2)]);
        $autreAgent = $this->courrierDe($this->utilisateur('Agent'), $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null, 'objet' => 'Déclaration de sinistre bis']);
        $this->actingAs($agent);

        $test = Livewire::test(MesCourriers::class)
            ->set('recherche', 'sinistre')
            ->assertSee($sinistre->numero_reference)
            ->assertDontSee($facture->numero_reference)
            ->assertDontSee($autreAgent->numero_reference)
            ->set('recherche', 'Creclink')
            ->assertSee($facture->numero_reference)
            ->assertDontSee($sinistre->numero_reference)
            ->set('recherche', '')
            ->set('priorite', 'urgente')
            ->assertSee($sinistre->numero_reference)
            ->assertDontSee($facture->numero_reference)
            ->set('priorite', '')
            ->set('periode', 'ce_mois')
            ->assertSee($sinistre->numero_reference)
            ->assertDontSee($facture->numero_reference)
            ->set('typeDocument', 'Facture')
            ->assertSee(__('Aucun courrier ne correspond à votre recherche dans cet onglet.'));

        $test->call('reinitialiserFiltres')
            ->assertSet('periode', '')
            ->assertSee($sinistre->numero_reference)
            ->assertSee($facture->numero_reference);
    }

    // 2026-10-30 (« there is no select all button ») — case « Tout
    // sélectionner » dans l'en-tête des onglets à sélection en masse.
    public function test_la_case_tout_selectionner_est_dans_les_onglets_a_selection(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = $this->serviceAvecResponsable();
        $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $enAttente = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        app(WorkflowService::class)->transfererAuService($enAttente, $service, $agent);
        $this->actingAs($agent);
        $caseToutSelectionner = 'aria-label="'.e(__('Tout sélectionner')).'"';

        Livewire::test(MesCourriers::class)
            ->assertSeeHtml($caseToutSelectionner)
            ->call('changerOnglet', 'en_cours_de_transfert')
            ->assertDontSeeHtml($caseToutSelectionner);
    }

    // Le filtre Service ne propose que les services de SES courriers.
    public function test_le_filtre_service_ne_propose_que_les_services_de_ses_courriers(): void
    {
        $agent = $this->utilisateur('Agent');
        $sinistres = Service::factory()->create(['code' => 'SIN', 'nom' => 'Département Sinistres']);
        Service::factory()->create(['code' => 'AUT', 'nom' => 'Service jamais utilisé']);
        $this->courrierDe($agent, $sinistres, ['statut' => 'en_cours_de_transfert']);
        $this->actingAs($agent);

        $services = Livewire::test(MesCourriers::class)->get('servicesDeMesCourriers');

        $this->assertSame(['Département Sinistres'], $services->pluck('nom')->all());
    }

    public function test_un_agent_transfere_plusieurs_de_ses_courriers_a_un_service_en_un_geste(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = $this->serviceAvecResponsable();
        $departement = $this->departementPonte($service);

        $premier = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $second = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$premier->id, $second->id])
            ->set('departementSelectionneId', $departement->id)
            ->call('transfererSelection')
            ->assertHasNoErrors();

        foreach ([$premier, $second] as $courrier) {
            $courrier->refresh();
            $this->assertSame('en_cours_de_transfert', $courrier->statut);
            $this->assertSame($service->id, $courrier->service_id);
        }
        $this->assertSame(2, CourrierHistorique::whereIn('courrier_id', [$premier->id, $second->id])->where('action', 'transfert')->count());
    }

    // Règle n°6 — jamais fait confiance aux ids postés : un courrier qui
    // n'appartient pas à l'agent est silencieusement ignoré, pas transféré,
    // et ne fait pas échouer le reste de la sélection.
    public function test_le_transfert_en_masse_ignore_les_courriers_qui_ne_sont_pas_les_siens(): void
    {
        $agent = $this->utilisateur('Agent');
        $autreAgent = $this->utilisateur('Agent');
        $service = $this->serviceAvecResponsable();
        $departement = $this->departementPonte($service);

        $lemien = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $pasLeMien = $this->courrierDe($autreAgent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$lemien->id, $pasLeMien->id])
            ->set('departementSelectionneId', $departement->id)
            ->call('transfererSelection');

        $this->assertSame('en_cours_de_transfert', $lemien->refresh()->statut);
        $this->assertSame('en_attente_de_transfert', $pasLeMien->refresh()->statut, 'le courrier d\'un autre agent ne doit pas être transféré');
    }

    public function test_le_transfert_en_masse_ignore_un_courrier_deja_transfere(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = $this->serviceAvecResponsable();
        $departement = $this->departementPonte($service);

        $dejaTransfere = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        app(WorkflowService::class)->transfererAuService($dejaTransfere, $service, $agent);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$dejaTransfere->id])
            ->set('departementSelectionneId', $departement->id)
            ->call('transfererSelection');

        $this->assertSame('en_cours_de_transfert', $dejaTransfere->refresh()->statut);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $dejaTransfere->id)->where('action', 'transfert')->count());
    }

    // Un service sans responsable ne pourrait jamais accepter : refusé avant
    // tout transfert, aucun courrier de la sélection n'est envoyé.
    public function test_le_transfert_en_masse_refuse_un_service_sans_responsable(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'SAN', 'responsable_id' => null]);
        $departement = $this->departementPonte($service);

        $courrier = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$courrier->id])
            ->set('departementSelectionneId', $departement->id)
            ->call('transfererSelection')
            ->assertHasErrors('uniteSelectionneeId');

        $this->assertSame('en_attente_de_transfert', $courrier->refresh()->statut);
    }

    public function test_lagent_marque_plusieurs_originaux_comme_remis(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = $this->serviceAvecResponsable();
        $workflow = app(WorkflowService::class);
        $premier = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $second = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        foreach ([$premier, $second] as $courrier) {
            $workflow->transfererAuService($courrier, $service, $agent);
            $workflow->accepterTransfert($courrier->fresh(), $service->responsable);
        }

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->call('changerOnglet', 'acceptes')
            ->set('selection', [$premier->id, $second->id])
            ->call('marquerOriginauxRemis')
            ->assertSee(__('Remis'));

        $this->assertSame(2, TransfertService::whereIn('courrier_id', [$premier->id, $second->id])->whereNotNull('original_remis_le')->count());
    }

    public function test_changer_donglet_vide_la_selection(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$courrier->id])
            ->call('changerOnglet', 'en_cours_de_transfert')
            ->assertSet('selection', []);
    }

    public function test_un_collaborateur_na_pas_acces_a_la_page(): void
    {
        $this->actingAs($this->utilisateur('Collaborateur'));

        Livewire::test(MesCourriers::class)->assertForbidden();
    }
}
