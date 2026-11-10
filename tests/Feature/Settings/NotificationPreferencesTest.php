<?php

namespace Tests\Feature\Settings;

use App\Livewire\Backend\ParametreSysteme;
use App\Livewire\Settings\NotificationPreferences;
use App\Models\Parametre;
use App\Models\Profil;
use App\Models\User;
use App\Notifications\CourrierNotification;
use App\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $profil = 'Agent'): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $profil])->id,
        ]);
    }

    private function canauxCourrier(User $user): array
    {
        return (new CourrierNotification(new \App\Models\Courrier, 'affectation'))->via($user);
    }

    public function test_la_page_saffiche_et_reste_fermee_aux_visiteurs_non_connectes(): void
    {
        $this->get(route('notifications.edit'))->assertRedirect(route('login'));

        $this->actingAs($this->utilisateur())
            ->get(route('notifications.edit'))
            ->assertOk()
            ->assertSee('Événements de courrier')
            ->assertSee('Rappels de courrier en retard');
    }

    public function test_chaque_interrupteur_senregistre_aussitot_et_lintervalle_va_de_un_a_dix_jours(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        Livewire::test(NotificationPreferences::class)
            ->assertSet('preferences.courriers.email', true)
            ->assertSet('preferences.courriers.application', true)
            ->assertSet('frequenceRelanceSla', 'default')
            ->set('preferences.courriers.email', false)
            ->assertHasNoErrors()
            ->set('frequenceRelanceSla', '10')
            ->assertHasNoErrors()
            ->assertSet('personnalisees', ['courriers']);

        $user->refresh();
        $this->assertFalse($user->preferences_notifications['courriers']['email']);
        // Laissé tel quel : suit l'administrateur, rien n'est figé.
        $this->assertNull($user->preferences_notifications['courriers']['application']);
        $this->assertSame(10, $user->frequenceRelanceSlaJours());
        $this->assertSame(['database', 'broadcast'], $this->canauxCourrier($user));
    }

    public function test_un_intervalle_superieur_a_dix_jours_est_refuse(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        Livewire::test(NotificationPreferences::class)
            ->set('frequenceRelanceSla', '11')
            ->assertHasErrors('frequenceRelanceSla');

        $this->assertNull($user->fresh()->preferences_notifications);
    }

    public function test_une_categorie_inconnue_envoyee_par_le_navigateur_nest_jamais_enregistree(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        Livewire::test(NotificationPreferences::class)
            ->set('preferences.inconnue.email', false)
            ->set('preferences.comptes.email', false);

        $enregistrees = $user->fresh()->preferences_notifications;
        $this->assertArrayNotHasKey('inconnue', $enregistrees);
        $this->assertFalse($enregistrees['comptes']['email']);
    }

    public function test_les_preferences_administrateur_sappliquent_aux_utilisateurs_sans_surcharge(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(ParametreSysteme::class)
            ->set('notificationsDefaut.courriers.email', false)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame(['database', 'broadcast'], $this->canauxCourrier($this->utilisateur()));
    }

    public function test_un_reglage_remis_comme_ladministrateur_suit_ses_changements_futurs(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        Livewire::test(NotificationPreferences::class)
            ->set('preferences.courriers.email', false)
            ->set('preferences.courriers.email', true)
            ->assertSet('personnalisees', []);

        $this->assertNull($user->fresh()->preferences_notifications);

        Parametre::actuel()->update(['notifications_defaut' => [
            'courriers' => ['email' => false, 'application' => true],
        ]]);
        Parametre::invaliderCache();

        $this->assertSame(['database', 'broadcast'], $this->canauxCourrier($user->fresh()));
    }

    public function test_la_page_montre_le_reglage_reellement_applique(): void
    {
        $user = $this->utilisateur();
        $user->forceFill(['preferences_notifications' => [
            'courriers' => ['email' => false, 'application' => null],
        ]])->save();
        Parametre::actuel()->update(['notifications_defaut' => [
            'courriers' => ['email' => true, 'application' => false],
        ]]);
        Parametre::invaliderCache();

        $this->actingAs($user);
        Livewire::test(NotificationPreferences::class)
            ->assertSet('preferences.courriers.email', false)
            ->assertSet('preferences.courriers.application', false)
            ->assertSet('preferences.alertes_sla.email', true)
            ->assertSet('personnalisees', ['courriers'])
            ->assertSee('Personnalisé');
    }

    public function test_retablir_les_reglages_de_ladministrateur_efface_les_choix_personnels(): void
    {
        $user = $this->utilisateur();
        $user->forceFill(['preferences_notifications' => [
            'courriers' => ['email' => false, 'application' => null],
            'sla_relance_jours' => 3,
        ]])->save();

        $this->actingAs($user);
        Livewire::test(NotificationPreferences::class)
            ->assertSee('Rétablir les réglages de l\'administrateur')
            ->call('reinitialiser')
            ->assertSet('preferences.courriers.email', true)
            ->assertSet('frequenceRelanceSla', 'default')
            ->assertSet('personnalisees', [])
            ->assertDontSee('Rétablir les réglages de l\'administrateur');

        $this->assertNull($user->fresh()->preferences_notifications);
        $this->assertSame(['mail', 'database', 'broadcast'], $this->canauxCourrier($user->fresh()));
    }

    public function test_un_avertissement_saffiche_quand_aucun_rappel_ne_peut_arriver(): void
    {
        $this->actingAs($this->utilisateur());

        Livewire::test(NotificationPreferences::class)
            ->assertDontSee('vous ne recevrez aucun rappel')
            ->set('preferences.alertes_sla.email', false)
            ->assertDontSee('vous ne recevrez aucun rappel')
            ->set('preferences.alertes_sla.application', false)
            ->assertSee('vous ne recevrez aucun rappel');
    }

    public function test_la_frequence_par_defaut_reste_celle_configuree_par_ladministrateur(): void
    {
        $user = $this->utilisateur();
        Parametre::actuel()->update(['sla_relance_jours' => 4]);
        Parametre::invaliderCache();

        $this->assertSame(4, $user->frequenceRelanceSlaJours());
        $this->assertSame(
            ['mail', 'database', 'broadcast'],
            app(NotificationPreferenceService::class)->canaux($user, 'courriers'),
        );
    }
}
