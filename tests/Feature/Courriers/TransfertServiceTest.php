<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\MesCourriers;
use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Profil;
use App\Models\Service;
use App\Models\TransfertService;
use App\Models\User;
use App\Notifications\CourrierNotification;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service et
// acceptation") — plus de routage par la DGA : l'accueil transfère au
// service, qui accepte (décharge numérotée) ou refuse avec motif ; le
// contenu reste masqué au service tant qu'il n'a pas accepté.
class TransfertServiceTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil, ?Service $service = null): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id,
            'service_id' => $service?->id,
        ]);
    }

    /** @return array{0: Service, 1: User} */
    private function serviceAvecResponsable(string $code = 'TST'): array
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['code' => $code, 'responsable_id' => $responsable->id]);
        $responsable->update(['service_id' => $service->id]);

        return [$service, User::find($responsable->id)];
    }

    // Département ponté directement au service (spec §1 : un Département
    // peut être la destination finale), suffisant pour la cascade.
    private function departementPonte(Service $service): OrganizationUnit
    {
        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);

        return OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'parent_id' => $site->id,
            'service_id' => $service->id,
        ]);
    }

    private function courrierEnregistrePar(User $agent, array $attributs = []): Courrier
    {
        $courrier = Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Demande de résiliation',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'statut' => 'en_attente_de_transfert',
            'service_id' => null,
        ], $attributs));

        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        return $courrier;
    }

    public function test_lagent_transfere_un_courrier_directement_au_service(): void
    {
        Notification::fake();
        $agent = $this->utilisateur('Agent');
        [$service, $responsable] = $this->serviceAvecResponsable();
        $departement = $this->departementPonte($service);
        $courrier = $this->courrierEnregistrePar($agent);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->call('transferer')
            ->assertHasNoErrors();

        $courrier->refresh();
        $transfert = TransfertService::where('courrier_id', $courrier->id)->sole();

        $this->assertSame('en_cours_de_transfert', $courrier->statut);
        $this->assertSame($service->id, $courrier->service_id);
        $this->assertSame(TransfertService::EN_ATTENTE, $transfert->statut);
        $this->assertSame($agent->id, $transfert->transfere_par_id);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'transfert')->count());
        Notification::assertSentTo($responsable, CourrierNotification::class, fn (CourrierNotification $n) => $n->action === 'transfert');
    }

    public function test_un_service_sans_responsable_ne_peut_pas_recevoir_de_transfert(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'SAN', 'responsable_id' => null]);
        $departement = $this->departementPonte($service);
        $courrier = $this->courrierEnregistrePar($agent);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->call('transferer')
            ->assertHasErrors('uniteSelectionneeId');

        $this->assertSame('en_attente_de_transfert', $courrier->refresh()->statut);
        $this->assertSame(0, TransfertService::count());
    }

    // Bug réel (capture d'écran, 2026-10-23) : avec le sélecteur Service /
    // Unité affiché, Flux montre déjà l'erreur sous ce champ — la vue
    // partielle l'affichait une seconde fois en dessous.
    public function test_lerreur_dun_service_sans_responsable_ne_saffiche_quune_fois(): void
    {
        $agent = $this->utilisateur('Agent');
        $departement = $this->departementPonte(Service::factory()->create(['code' => 'DEP', 'responsable_id' => $this->utilisateur('Responsable de service')->id]));
        $serviceSansResponsable = Service::factory()->create(['code' => 'SPT', 'nom' => 'Service Production Technique', 'responsable_id' => null]);
        $unite = OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_SERVICE,
            'parent_id' => $departement->id,
            'service_id' => $serviceSansResponsable->id,
        ]);
        $courrier = $this->courrierEnregistrePar($agent);
        $this->actingAs($agent);

        $html = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->set('uniteSelectionneeId', $unite->id)
            ->call('transferer')
            ->assertHasErrors('uniteSelectionneeId')
            ->html();

        $this->assertSame(1, substr_count($html, e('n\'a pas de responsable désigné')));
    }

    public function test_le_responsable_accepte_le_courrier_et_lagent_est_prevenu(): void
    {
        Notification::fake();
        $agent = $this->utilisateur('Agent');
        [$service, $responsable] = $this->serviceAvecResponsable();
        $courrier = $this->courrierEnregistrePar($agent);
        app(WorkflowService::class)->transfererAuService($courrier, $service, $agent);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('accepterTransfert')
            ->assertHasNoErrors();

        $transfert = TransfertService::where('courrier_id', $courrier->id)->sole();

        $this->assertSame('enregistre', $courrier->refresh()->statut);
        $this->assertSame(TransfertService::ACCEPTE, $transfert->statut);
        $this->assertSame($responsable->id, $transfert->decide_par_id);
        $this->assertStringStartsWith('ACC-'.now()->year.'-', $transfert->numero_decharge);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'transfert_accepte')->count());
        Notification::assertSentTo($agent, CourrierNotification::class, fn (CourrierNotification $n) => $n->action === 'transfert_accepte');
    }

    // Retour utilisateur (capture d'écran, 2026-10-23) : la confirmation
    // passe par une vraie modale, plus par la boîte du navigateur.
    public function test_lacceptation_passe_par_une_modale_et_non_la_boite_du_navigateur(): void
    {
        $agent = $this->utilisateur('Agent');
        [$service, $responsable] = $this->serviceAvecResponsable();
        $courrier = $this->courrierEnregistrePar($agent);
        app(WorkflowService::class)->transfererAuService($courrier, $service, $agent);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Accepter ce courrier ?')
            ->assertSeeHtml('data-modal="accepter-transfert-modal"')
            ->assertDontSee('Accepter ce courrier pour votre service ?');
    }

    // Droits refusés (Règle n°7) : voir le courrier (voir_tout) ne suffit
    // pas, seul le responsable du service destinataire décide.
    public function test_seul_le_responsable_du_service_destinataire_peut_accepter(): void
    {
        $agent = $this->utilisateur('Agent');
        [$service] = $this->serviceAvecResponsable();
        [, $autreResponsable] = $this->serviceAvecResponsable('AUT');
        $administrateur = $this->utilisateur('Administrateur');
        $courrier = $this->courrierEnregistrePar($agent);
        app(WorkflowService::class)->transfererAuService($courrier, $service, $agent);

        $this->actingAs($autreResponsable);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertForbidden();

        $this->actingAs($administrateur);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('accepterTransfert')
            ->assertForbidden();

        $this->assertSame('en_cours_de_transfert', $courrier->refresh()->statut);
    }

    public function test_le_refus_exige_un_motif_et_renvoie_le_courrier_a_laccueil(): void
    {
        Notification::fake();
        $agent = $this->utilisateur('Agent');
        [$service, $responsable] = $this->serviceAvecResponsable();
        $courrier = $this->courrierEnregistrePar($agent);
        app(WorkflowService::class)->transfererAuService($courrier, $service, $agent);
        $this->actingAs($responsable);

        $test = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('refuserTransfert')
            ->assertHasErrors('motifRefusTransfert');

        $this->assertSame('en_cours_de_transfert', $courrier->refresh()->statut);

        $test->set('motifRefusTransfert', 'Ce courrier concerne la comptabilité.')
            ->call('refuserTransfert')
            ->assertHasNoErrors();

        $courrier->refresh();
        $transfert = TransfertService::where('courrier_id', $courrier->id)->sole();

        $this->assertSame('en_attente_de_transfert', $courrier->statut);
        $this->assertNull($courrier->service_id);
        $this->assertSame(TransfertService::REFUSE, $transfert->statut);
        $this->assertSame('Ce courrier concerne la comptabilité.', $transfert->motif_refus);
        $this->assertNull($transfert->numero_decharge);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'transfert_refuse')->count());
        Notification::assertSentTo($agent, CourrierNotification::class, fn (CourrierNotification $n) => $n->action === 'transfert_refuse');
        $this->assertFalse($responsable->fresh()->can('view', $courrier));
    }

    // Le contenu est masqué CÔTÉ SERVEUR au responsable avant acceptation
    // (aperçu, texte OCR, pièces jointes), visible ensuite ; l'agent qui a
    // enregistré le courrier le garde ; le document rejoint le dossier du
    // service à l'acceptation (Règle n°4).
    public function test_le_contenu_est_masque_au_service_tant_quil_na_pas_accepte(): void
    {
        Storage::fake('s3');
        $agent = $this->utilisateur('Agent');
        [$service, $responsable] = $this->serviceAvecResponsable();
        $chemin = 'courriers/'.now()->year.'/'.Courrier::SEGMENT_SERVICE_EN_ATTENTE.'/GEC-TEST.pdf';
        Storage::disk('s3')->put($chemin, '%PDF-1.4 contenu');
        $courrier = $this->courrierEnregistrePar($agent, ['fichier_path' => $chemin, 'texte_ocr' => 'Montant du sinistre', 'ocr_statut' => 'reussi']);
        app(WorkflowService::class)->transfererAuService($courrier, $service, $agent);
        $courrier->refresh();

        $this->assertTrue($responsable->can('view', $courrier));
        $this->assertFalse($responsable->can('voirContenu', $courrier));
        $this->assertFalse($responsable->can('voirTexteOcr', $courrier));
        $this->assertTrue($agent->can('voirContenu', $courrier));
        $this->actingAs($responsable)->get(route('apercus.manifeste', ['courrier', $courrier->id]))->assertForbidden();

        app(WorkflowService::class)->accepterTransfert($courrier, $responsable);
        $courrier->refresh();

        $this->assertTrue($responsable->fresh()->can('voirContenu', $courrier));
        $this->assertStringContainsString('/TST/', $courrier->fichier_path);
        Storage::disk('s3')->assertExists($courrier->fichier_path);
        Queue::fake();
        $this->actingAs($responsable->fresh())->get(route('apercus.manifeste', ['courrier', $courrier->id]))->assertOk();
    }

    public function test_lagent_marque_loriginal_comme_remis_apres_acceptation(): void
    {
        $agent = $this->utilisateur('Agent');
        $autreAgent = $this->utilisateur('Agent');
        [$service, $responsable] = $this->serviceAvecResponsable();
        $courrier = $this->courrierEnregistrePar($agent);
        app(WorkflowService::class)->transfererAuService($courrier, $service, $agent);

        $this->assertFalse($agent->can('marquerOriginalRemis', $courrier->fresh()));

        app(WorkflowService::class)->accepterTransfert($courrier->fresh(), $responsable);

        $this->assertFalse($autreAgent->can('marquerOriginalRemis', $courrier->fresh()));
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('marquerOriginalRemis')
            ->assertHasNoErrors();

        $transfert = TransfertService::where('courrier_id', $courrier->id)->sole();

        $this->assertNotNull($transfert->original_remis_le);
        $this->assertSame($agent->id, $transfert->original_remis_par_id);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'original_remis')->count());
        $this->assertFalse($agent->fresh()->can('marquerOriginalRemis', $courrier->fresh()));
    }

    public function test_un_courrier_refuse_peut_etre_transfere_a_un_autre_service_qui_laccepte(): void
    {
        $agent = $this->utilisateur('Agent');
        [$premierService, $premierResponsable] = $this->serviceAvecResponsable('PRE');
        [$bonService, $bonResponsable] = $this->serviceAvecResponsable('BON');
        $courrier = $this->courrierEnregistrePar($agent);
        $workflow = app(WorkflowService::class);

        $workflow->transfererAuService($courrier, $premierService, $agent);
        $workflow->refuserTransfert($courrier->fresh(), $premierResponsable, 'Pas pour notre service.');
        $workflow->transfererAuService($courrier->fresh(), $bonService, $agent);
        $workflow->accepterTransfert($courrier->fresh(), $bonResponsable);

        $courrier->refresh();

        $this->assertSame('enregistre', $courrier->statut);
        $this->assertSame($bonService->id, $courrier->service_id);
        $this->assertSame(2, TransfertService::where('courrier_id', $courrier->id)->count());
        $this->assertSame(TransfertService::ACCEPTE, $courrier->dernierTransfert->statut);
    }

    public function test_transfert_en_masse_depuis_mes_courriers_vers_un_service(): void
    {
        Notification::fake();
        $agent = $this->utilisateur('Agent');
        [$service] = $this->serviceAvecResponsable();
        $departement = $this->departementPonte($service);
        $premier = $this->courrierEnregistrePar($agent);
        $second = $this->courrierEnregistrePar($agent);
        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [(string) $premier->id, (string) $second->id])
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->call('transfererSelection')
            ->assertHasNoErrors();

        foreach ([$premier, $second] as $courrier) {
            $courrier->refresh();
            $this->assertSame('en_cours_de_transfert', $courrier->statut);
            $this->assertSame($service->id, $courrier->service_id);
        }

        $this->assertSame(2, TransfertService::where('service_id', $service->id)->count());
    }
}
