<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Profile;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-11-11 — mot de passe de sauvegarde (Paramètres › Mon profil) : il
// ouvre la copie personnelle de chacun sur le PC (App\Services\SauvegardeLocale).
class MotDePasseSauvegardeTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Collaborateur'])->id,
            'password' => 'connexion-123',
        ]);
    }

    public function test_definir_le_mot_de_passe_demande_celui_de_connexion_et_le_garde_chiffre(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('motDePasseActuel', 'connexion-123')
            ->set('motDePasseSauvegarde', 'sauvegarde-456')
            ->set('motDePasseSauvegarde_confirmation', 'sauvegarde-456')
            ->call('definirMotDePasseSauvegarde')
            ->assertHasNoErrors()
            ->assertDispatched('modal-close', name: 'mot-de-passe-sauvegarde')
            ->assertSet('motDePasseSauvegarde', '');

        $user->refresh();
        $this->assertSame('sauvegarde-456', $user->mot_de_passe_sauvegarde);
        $this->assertNotNull($user->mot_de_passe_sauvegarde_le);
        // Jamais en clair en base, jamais exposé.
        $this->assertNotSame('sauvegarde-456', DB::table('users')->where('id', $user->id)->value('mot_de_passe_sauvegarde'));
        $this->assertArrayNotHasKey('mot_de_passe_sauvegarde', $user->toArray());
    }

    public function test_un_mauvais_mot_de_passe_de_connexion_est_refuse(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('motDePasseActuel', 'faux')
            ->set('motDePasseSauvegarde', 'sauvegarde-456')
            ->set('motDePasseSauvegarde_confirmation', 'sauvegarde-456')
            ->call('definirMotDePasseSauvegarde')
            ->assertHasErrors('motDePasseActuel');

        $this->assertNull($user->fresh()->mot_de_passe_sauvegarde);
    }

    // GEC ne doit jamais garder le mot de passe de connexion sous une forme
    // qu'il sait relire.
    public function test_le_mot_de_passe_de_connexion_ne_peut_pas_servir_de_mot_de_passe_de_sauvegarde(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('motDePasseActuel', 'connexion-123')
            ->set('motDePasseSauvegarde', 'connexion-123')
            ->set('motDePasseSauvegarde_confirmation', 'connexion-123')
            ->call('definirMotDePasseSauvegarde')
            ->assertHasErrors('motDePasseSauvegarde');

        $this->assertNull($user->fresh()->mot_de_passe_sauvegarde);
    }

    public function test_la_confirmation_et_la_longueur_sont_verifiees(): void
    {
        $this->actingAs($this->utilisateur());

        Livewire::test(Profile::class)
            ->set('motDePasseActuel', 'connexion-123')
            ->set('motDePasseSauvegarde', 'court')
            ->set('motDePasseSauvegarde_confirmation', 'autre')
            ->call('definirMotDePasseSauvegarde')
            ->assertHasErrors(['motDePasseSauvegarde']);
    }

    public function test_retirer_le_mot_de_passe_de_sauvegarde(): void
    {
        $user = $this->utilisateur();
        $user->forceFill(['mot_de_passe_sauvegarde' => 'sauvegarde-456', 'mot_de_passe_sauvegarde_le' => now()])->save();
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->assertSee('Mot de passe défini')
            ->call('retirerMotDePasseSauvegarde')
            ->assertSee('Pas de mot de passe');

        $this->assertNull($user->fresh()->mot_de_passe_sauvegarde);
    }
}
