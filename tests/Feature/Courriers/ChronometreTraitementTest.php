<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\ShowCourrier;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Module 5 — chronomètre de traitement (2026-09-24, demande explicite de
// l'utilisateur : le responsable fixe le délai avant/à l'affectation, et le
// SLA s'affiche en chronomètre dans les détails de la fiche). Voir
// DECISIONS.md "Chronomètre de traitement".
class ChronometreTraitementTest extends TestCase
{
    use RefreshDatabase;

    private User $responsable;

    private User $collaborateur;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responsable = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Responsable de service'])->id]);
        $this->service = Service::factory()->create(['code' => 'TST', 'responsable_id' => $this->responsable->id]);
        $this->collaborateur = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Collaborateur'])->id, 'service_id' => $this->service->id]);
    }

    private function courrier(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => $this->service->id,
            'statut' => 'enregistre',
        ], $attributs));
    }

    public function test_le_responsable_fixe_le_delai_en_affectant_et_le_chronometre_demarre(): void
    {
        $this->travelTo(now()->setTime(9, 30));
        $courrier = $this->courrier();
        $this->actingAs($this->responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('delaiValeur', \App\Services\SlaCalculatorService::delaiJours(null, 'Lettre'))
            ->set('collaborateurSelectionne', $this->collaborateur->id)
            ->set('delaiValeur', 36)
            ->set('delaiUnite', 'heures')
            ->call('affecter')
            ->assertHasNoErrors()
            ->assertSee('x-data="chronometre"', false)
            ->assertSee(__('délai fixé par le responsable'));

        $courrier->refresh();
        $this->assertSame('affecte', $courrier->statut);
        $this->assertEquals(now()->addHours(36)->toDateTimeString(), $courrier->chrono_fin_le->toDateTimeString());
        $this->assertEquals(now()->toDateTimeString(), $courrier->chrono_debut_le->toDateTimeString());
        // La date limite SLA (listes, alertes) suit la fin du chronomètre.
        $this->assertSame(now()->addHours(36)->toDateString(), $courrier->date_limite->toDateString());
        $this->assertTrue(CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'delai_fixe')->exists());
    }

    public function test_modifier_le_delai_exige_un_motif_et_le_trace(): void
    {
        $courrier = $this->courrier(['statut' => 'en_traitement', 'chrono_debut_le' => now()->subDay(), 'chrono_fin_le' => now()->addDay()]);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $this->collaborateur->id, 'affecte_par_id' => $this->responsable->id]);
        $this->actingAs($this->responsable);

        $page = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('delaiValeur', 3)
            ->set('delaiUnite', 'jours')
            ->call('modifierDelai')
            ->assertHasErrors('motifDelai');

        $page->set('motifDelai', 'Pièce complémentaire attendue du client.')
            ->call('modifierDelai')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertEquals(now()->addDays(3)->toDateTimeString(), $courrier->chrono_fin_le->toDateTimeString());
        $trace = CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'delai_modifie')->firstOrFail();
        $this->assertStringContainsString('Pièce complémentaire attendue du client.', $trace->commentaire);
    }

    public function test_le_collaborateur_ne_peut_pas_fixer_le_delai(): void
    {
        $courrier = $this->courrier(['statut' => 'en_traitement']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $this->collaborateur->id, 'affecte_par_id' => $this->responsable->id]);
        $this->actingAs($this->collaborateur);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('peutFixerDelai', false)
            ->set('delaiValeur', 1)
            ->set('motifDelai', 'Je veux plus de temps.')
            ->call('modifierDelai')
            ->assertForbidden();

        $this->assertNull($courrier->fresh()->chrono_fin_le);
    }

    public function test_le_chronometre_se_fige_a_la_validation(): void
    {
        $courrier = $this->courrier(['statut' => 'en_validation', 'chrono_debut_le' => now()->subHours(5), 'chrono_fin_le' => now()->addDay()]);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $this->collaborateur->id, 'affecte_par_id' => $this->responsable->id]);
        $this->actingAs($this->responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('valider')
            ->assertHasNoErrors()
            ->assertSee('data-arret="', false);

        $this->assertNotNull($courrier->fresh()->chrono_arrete_le);
    }

    // Courrier clôturé avant l'existence du chronomètre : aucun compte à
    // rebours qui tournerait indéfiniment.
    public function test_un_courrier_cloture_sans_heure_darret_naffiche_pas_de_chronometre(): void
    {
        $courrier = $this->courrier(['statut' => 'traite']);
        $this->actingAs($this->responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertDontSee('x-data="chronometre"', false);
    }
}
