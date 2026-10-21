<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\Decharge;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Module 9 — "Décharge" (specifications-modules-GEC.md point 5, 2026-10-05) :
// reçu traçable à l'emprunt de l'original physique d'un courrier archivé.
class DechargeTest extends TestCase
{
    use RefreshDatabase;

    // Un vrai service par défaut (pas service_id=null) : CourrierPolicy::view()
    // (courriers.voir_service) exige que le responsable soit bien celui DU
    // SERVICE réel du courrier — sans ça, mount() de ShowCourrier échoue à
    // l'authorize('view', ...) avant même d'atteindre les actions testées.
    private function responsableDuService(?Service $service = null): User
    {
        $service ??= Service::factory()->create();

        $responsable = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Responsable de service'])->id,
            'service_id' => $service->id,
        ]);

        $service->update(['responsable_id' => $responsable->id]);

        return $responsable;
    }

    private function courrierArchive(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now()->subDays(10),
            'objet' => 'Réclamation client',
            'type_document' => 'Réclamation',
            'mode_reception' => 'email',
            'statut' => 'archive',
        ], $attributs));
    }

    public function test_le_numero_de_reference_est_genere_au_format_dech(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);

        $decharge = app(WorkflowService::class)->emettreDecharge($courrier, $responsable, $responsable);

        $this->assertMatchesRegularExpression('/^DECH-\d{4}-\d{6}$/', $decharge->numero_reference);
    }

    public function test_emettre_une_decharge_trace_lhistorique_du_courrier(): void
    {
        $responsable = $this->responsableDuService();
        $emprunteur = User::factory()->create();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);

        $decharge = app(WorkflowService::class)->emettreDecharge($courrier, $emprunteur, $responsable, 'Consultation dossier sinistre');

        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'auteur_id' => $responsable->id,
            'action' => 'decharge_emise',
        ]);
        $this->assertSame($emprunteur->id, $decharge->emprunteur_id);
        $this->assertSame($responsable->id, $decharge->emis_par_id);
        $this->assertNull($decharge->rendu_le);
    }

    public function test_impossible_demettre_une_decharge_sur_un_courrier_non_archive(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id, 'statut' => 'traite']);

        $this->expectException(RuntimeException::class);

        app(WorkflowService::class)->emettreDecharge($courrier, $responsable, $responsable);
    }

    // Un original ne se prête pas à deux personnes en même temps.
    public function test_impossible_demettre_une_2e_decharge_tant_que_la_1ere_nest_pas_rendue(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);
        app(WorkflowService::class)->emettreDecharge($courrier, $responsable, $responsable);

        $this->expectException(RuntimeException::class);

        app(WorkflowService::class)->emettreDecharge($courrier, $responsable, $responsable);
    }

    public function test_marquer_rendue_trace_lhistorique_et_permet_une_nouvelle_decharge(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);
        $workflow = app(WorkflowService::class);
        $decharge = $workflow->emettreDecharge($courrier, $responsable, $responsable);

        $workflow->marquerDechargeRendue($decharge, $responsable);

        $this->assertNotNull($decharge->fresh()->rendu_le);
        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'action' => 'decharge_rendue',
        ]);
        $this->assertNull($courrier->fresh()->dechargeActive);

        // La décharge précédente étant rendue, une nouvelle est possible.
        $nouvelle = $workflow->emettreDecharge($courrier, $responsable, $responsable);
        $this->assertNotSame($decharge->id, $nouvelle->id);
    }

    public function test_impossible_de_marquer_rendue_une_decharge_deja_rendue(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);
        $workflow = app(WorkflowService::class);
        $decharge = $workflow->emettreDecharge($courrier, $responsable, $responsable);
        $workflow->marquerDechargeRendue($decharge, $responsable);

        $this->expectException(RuntimeException::class);

        $workflow->marquerDechargeRendue($decharge, $responsable);
    }

    // Règle n°5 — jamais de suppression, même via le modèle directement
    // accessible : aucune méthode delete() n'est exposée par ce test lui-même
    // (documente l'intention plutôt qu'une contrainte technique en base).
    public function test_une_decharge_rendue_reste_dans_lhistorique(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);
        $workflow = app(WorkflowService::class);
        $decharge = $workflow->emettreDecharge($courrier, $responsable, $responsable);
        $workflow->marquerDechargeRendue($decharge, $responsable);

        $this->assertSame(1, Decharge::where('courrier_id', $courrier->id)->count());
    }

    // ===== Policy =====

    public function test_un_responsable_de_service_peut_emettre_une_decharge_pour_son_service(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);

        $this->assertTrue($responsable->can('emettreDecharge', $courrier));
    }

    public function test_impossible_sur_un_courrier_non_archive_meme_avec_le_privilege(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id, 'statut' => 'traite']);

        $this->assertFalse($responsable->can('emettreDecharge', $courrier));
    }

    public function test_un_agent_sans_le_privilege_ne_peut_pas_emettre_de_decharge(): void
    {
        $agent = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id]);
        $service = Service::factory()->create();
        $courrier = $this->courrierArchive(['service_id' => $service->id]);

        $this->assertFalse($agent->can('emettreDecharge', $courrier));
    }

    // ===== Intégration Livewire (ShowCourrier) =====

    public function test_emettre_une_decharge_depuis_la_fiche_courrier(): void
    {
        $responsable = $this->responsableDuService();
        $emprunteur = User::factory()->create();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('ouvrirDecharge')
            ->set('dechargeEmprunteurId', $emprunteur->id)
            ->set('dechargeMotif', 'Consultation')
            ->call('emettreDecharge')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('decharges', ['courrier_id' => $courrier->id, 'emprunteur_id' => $emprunteur->id]);
    }

    public function test_le_formulaire_refuse_un_emprunteur_sans_choix(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('emettreDecharge')
            ->assertHasErrors('dechargeEmprunteurId');

        $this->assertDatabaseCount('decharges', 0);
    }

    public function test_marquer_rendue_depuis_la_fiche_courrier(): void
    {
        $responsable = $this->responsableDuService();
        $courrier = $this->courrierArchive(['service_id' => $responsable->service_id]);
        app(WorkflowService::class)->emettreDecharge($courrier, $responsable, $responsable);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('marquerDechargeRendue');

        $this->assertNotNull($courrier->fresh()->decharges()->first()->rendu_le);
    }

    // Règle n°6 — l'action est bloquée côté serveur pour qui n'a pas le
    // privilège, même en appelant directement la méthode Livewire.
    public function test_impossible_demettre_une_decharge_sans_le_privilege_meme_via_livewire(): void
    {
        // voir_tout pour passer le garde-fou view() de mount() sans lui
        // donner emettre_decharge — isole précisément le check attendu
        // (CourrierPolicy::emettreDecharge(), pas celui de view()).
        $agent = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id]);
        $agent->privilegesDirectes()->attach(Privilege::where('cle', 'courriers.voir_tout')->firstOrFail()->id);
        $courrier = $this->courrierArchive(['service_id' => Service::factory()->create()->id]);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('ouvrirDecharge')
            ->assertForbidden();
    }
}
