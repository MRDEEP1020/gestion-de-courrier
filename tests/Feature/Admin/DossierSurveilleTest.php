<?php

namespace Tests\Feature\Admin;

use App\Livewire\Backend\DossierSurveille;
use App\Livewire\Backend\ScanPremier;
use App\Models\CourrierBrouillon;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Module 1/2 — "Dossier surveillé" déplacé dans l'administration
// (2026-09-24, voir DECISIONS.md "Dossier surveillé : configuration dans
// l'administration"). La configuration elle-même vit dans le navigateur
// (IndexedDB) — seuls l'accès à la page, le menu et le rafraîchissement
// automatique de Numérisation sont testables côté serveur (pas d'outil de
// test JS dans ce projet, voir notes internes).
class DossierSurveilleTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    public function test_ladministrateur_ouvre_la_page_et_la_voit_dans_le_menu(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        $this->get(route('admin.dossier-surveille'))
            ->assertOk()
            ->assertSee('Réglage propre à ce poste')
            ->assertSee(route('admin.dossier-surveille'));
    }

    public function test_un_agent_sans_le_privilege_est_refuse_et_ne_voit_pas_le_menu(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        $this->get(route('admin.dossier-surveille'))->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee(route('admin.dossier-surveille'));
    }

    public function test_numerisation_ne_propose_plus_de_configurer_le_dossier(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        $this->get(route('courriers.numeriser-nouveau'))
            ->assertOk()
            ->assertDontSee('Choisir un dossier')
            ->assertSee('data-mode="execution"', false)
            ->assertSee('Demandez à un administrateur de le configurer.');
    }

    // Retour de l'utilisateur (2026-09-24) : le tableau des imports ne se
    // mettait à jour qu'en rechargeant la page.
    public function test_un_import_automatique_apparait_dans_le_tableau_sans_recharger(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        $page = Livewire::test(ScanPremier::class)
            ->assertSee('Aucun document importé depuis un dossier surveillé');

        $page->set('document', UploadedFile::fake()->create('4941_001.pdf', 80, 'application/pdf'))
            ->call('numeriserAutomatique')
            ->assertHasNoErrors()
            ->assertSee('4941_001.pdf');
    }

    public function test_la_fin_docr_met_a_jour_le_statut_sans_recharger(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        $page = Livewire::test(ScanPremier::class)
            ->assertSet('agentId', $agent->id)
            ->set('document', UploadedFile::fake()->create('4942_001.pdf', 80, 'application/pdf'))
            ->call('numeriserAutomatique')
            ->assertSee('non traite');

        CourrierBrouillon::latest('id')->firstOrFail()->update(['ocr_statut' => 'reussi']);

        $page->call('brouillonOcrTermine')->assertSee('reussi')->assertDontSee('non traite');
    }
}
