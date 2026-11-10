<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Appearance;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-10-27 (liste « Phase 7 » : « Dark theme: it should only appear on the
// user account, not on all accounts even if logout and connect with another »)
// — l'apparence est enregistrée sur le compte et réappliquée à chaque page.
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(array $attributs = []): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id, ...$attributs]);
    }

    public function test_le_choix_est_enregistre_sur_le_compte(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        Livewire::test(Appearance::class)
            ->assertSet('apparence', 'light')
            ->set('apparence', 'dark')
            ->assertHasNoErrors()
            ->assertDispatched('apparence-changee', apparence: 'dark');

        $this->assertSame('dark', $user->refresh()->apparence);
    }

    public function test_une_valeur_inconnue_est_refusee(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        Livewire::test(Appearance::class)
            ->set('apparence', 'rose')
            ->assertHasErrors('apparence');

        $this->assertSame('light', $user->refresh()->apparence);
    }

    // Chaque page réécrit la préférence du compte connecté avant le script
    // d'apparence de Flux : le thème sombre d'un utilisateur ne passe jamais
    // au compte suivant ouvert dans le même navigateur.
    public function test_chaque_compte_retrouve_son_propre_theme(): void
    {
        $sombre = $this->utilisateur(['apparence' => 'dark']);
        $clair = $this->utilisateur();

        $this->actingAs($sombre)->get(route('dashboard'))
            ->assertOk()
            ->assertSee("const apparence = 'dark'", false);

        $this->actingAs($clair)->get(route('dashboard'))
            ->assertOk()
            ->assertSee("const apparence = 'light'", false);
    }

    public function test_la_page_de_connexion_est_toujours_claire(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee("const apparence = 'light'", false);
    }
}
