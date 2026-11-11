<?php

namespace Tests\Feature\Auth;

use App\Livewire\Backend\ParametreSysteme;
use App\Models\Parametre;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-11-11 (« je veux que les mots de passe d'utilisateur expirent et ils
// devront le renouveler ») — App\Http\Middleware\ExigerMotDePasseAJour,
// App\Http\Controllers\RenouvelerMotDePasseController (formulaire classique).
class ExpirationMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(?int $changeIlYaJours): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Collaborateur'])->id,
            'password' => 'ancien-mot-de-passe',
            'mot_de_passe_change_le' => $changeIlYaJours === null ? null : now()->subDays($changeIlYaJours),
        ]);
    }

    private function expirationJours(int $jours): void
    {
        Parametre::actuel()->update(['mot_de_passe_expiration_jours' => $jours]);
        Parametre::invaliderCache();
    }

    public function test_un_mot_de_passe_jamais_choisi_doit_etre_remplace_avant_toute_page(): void
    {
        $this->actingAs($this->utilisateur(null));

        $this->get(route('dashboard'))->assertRedirect(route('mot-de-passe.renouveler'));
        $this->get(route('profile.edit'))->assertRedirect(route('mot-de-passe.renouveler'));
        $this->get(route('mot-de-passe.renouveler'))->assertOk()->assertSee('Choisissez votre mot de passe');
        $this->post(route('logout'))->assertRedirect();
    }

    // Bug réel du 2026-11-11 : le bouton « Enregistrer » de la page de
    // renouvellement est une requête Livewire (POST …/update) ; elle était
    // elle-même redirigée vers la page de renouvellement, en boucle. Les tests
    // précédents appelaient le composant directement, sans passer par le middleware.
    public function test_lenvoi_du_formulaire_de_renouvellement_nest_pas_redirige(): void
    {
        $this->actingAs($this->utilisateur(null));

        $reponse = $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson(route('default-livewire.update'), ['components' => []]);

        $this->assertNotSame(route('mot-de-passe.renouveler'), $reponse->headers->get('Location'));
        $this->assertFalse($reponse->isRedirection());
    }

    public function test_un_mot_de_passe_expire_doit_etre_renouvele(): void
    {
        $this->expirationJours(90);
        $this->actingAs($this->utilisateur(91));

        $this->get(route('dashboard'))->assertRedirect(route('mot-de-passe.renouveler'));
        $this->get(route('mot-de-passe.renouveler'))->assertSee('Votre mot de passe a expiré');
    }

    public function test_un_mot_de_passe_valide_laisse_passer_et_zero_desactive_lexpiration(): void
    {
        $this->expirationJours(90);
        $this->actingAs($this->utilisateur(30));
        $this->get(route('dashboard'))->assertOk();
        // Rien à renouveler : la page renvoie à l'application.
        $this->get(route('mot-de-passe.renouveler'))->assertRedirect(route('dashboard'));

        $this->expirationJours(0);
        $this->actingAs($this->utilisateur(5000));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_le_preavis_saffiche_et_le_renouvellement_reste_facultatif(): void
    {
        $this->expirationJours(90);
        $this->actingAs($this->utilisateur(86));

        $this->get(route('dashboard'))->assertOk()->assertSee('Votre mot de passe expire dans 4 jours.');
        $this->get(route('mot-de-passe.renouveler'))->assertOk()->assertSee('Plus tard');
    }

    // Parcours réel par le circuit complet de la requête (middleware compris) :
    // le formulaire est un POST classique, plus un composant Livewire.
    public function test_renouveler_change_le_mot_de_passe_et_mene_au_tableau_de_bord(): void
    {
        $user = $this->utilisateur(null);
        $this->actingAs($user);

        $this->post(route('mot-de-passe.renouveler.enregistrer'), [
            'motDePasseActuel' => 'ancien-mot-de-passe',
            'password' => 'nouveau-mot-de-passe',
            'password_confirmation' => 'nouveau-mot-de-passe',
        ])->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('nouveau-mot-de-passe', $user->password));
        $this->assertTrue($user->mot_de_passe_change_le->isToday());
        $this->assertFalse($user->doitRenouvelerMotDePasse());
        $this->get(route('dashboard'))->assertOk()->assertSee('Mot de passe renouvelé.');
    }

    public function test_le_mot_de_passe_actuel_est_exige_et_le_nouveau_doit_etre_different(): void
    {
        $user = $this->utilisateur(null);
        $this->actingAs($user);

        $this->from(route('mot-de-passe.renouveler'))->post(route('mot-de-passe.renouveler.enregistrer'), [
            'motDePasseActuel' => 'faux',
            'password' => 'nouveau-mot-de-passe',
            'password_confirmation' => 'nouveau-mot-de-passe',
        ])->assertRedirect(route('mot-de-passe.renouveler'))->assertSessionHasErrors('motDePasseActuel');

        $this->from(route('mot-de-passe.renouveler'))->post(route('mot-de-passe.renouveler.enregistrer'), [
            'motDePasseActuel' => 'ancien-mot-de-passe',
            'password' => 'ancien-mot-de-passe',
            'password_confirmation' => 'ancien-mot-de-passe',
        ])->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->mot_de_passe_change_le);
    }

    public function test_le_preavis_laisse_choisir_plus_tard_et_un_mot_de_passe_a_jour_est_renvoye_au_tableau_de_bord(): void
    {
        $this->expirationJours(90);
        $this->actingAs($this->utilisateur(86));
        $this->get(route('mot-de-passe.renouveler'))->assertOk()->assertSee('Plus tard');

        $this->actingAs($this->utilisateur(1));
        $this->get(route('mot-de-passe.renouveler'))->assertRedirect(route('dashboard'));
    }

    // Le lien de réinitialisation (création de compte, « Mot de passe
    // oublié ? ») : un mot de passe choisi par la personne, le délai repart.
    public function test_le_lien_de_reinitialisation_compte_comme_un_renouvellement(): void
    {
        $user = $this->utilisateur(null);
        $jeton = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $jeton,
            'email' => $user->email,
            'password' => 'choisi-par-lien',
            'password_confirmation' => 'choisi-par-lien',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->mot_de_passe_change_le->isToday());
    }

    public function test_ladministrateur_regle_la_validite(): void
    {
        $this->actingAs(User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Administrateur'])->id]));

        Livewire::test(ParametreSysteme::class)
            ->assertSet('motDePasseExpirationJours', '90')
            ->set('motDePasseExpirationJours', '60')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame(60, Parametre::actuel()->mot_de_passe_expiration_jours);
    }
}
