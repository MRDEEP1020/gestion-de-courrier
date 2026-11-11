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

// 2026-11-10 (« the notif for mail and app only the admin can see and modify
// it but everybody can do it Rappels de courrier en retard ») — les
// interrupteurs E-mail / Application demandent le privilège
// general.choisir_canaux_notification (Administrateur par défaut) : les tests
// d'interrupteurs tournent donc avec un Administrateur, ceux du rythme des
// rappels avec un Agent.
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

        $this->actingAs($this->utilisateur('Administrateur'))
            ->get(route('notifications.edit'))
            ->assertOk()
            ->assertSee('Événements de courrier')
            ->assertSee('Rappels de courrier en retard');
    }

    public function test_sans_le_privilege_seuls_les_rappels_de_retard_saffichent(): void
    {
        $this->actingAs($this->utilisateur('Agent'))
            ->get(route('notifications.edit'))
            ->assertOk()
            ->assertDontSee('Événements de courrier')
            ->assertSee('Rappels de courrier en retard')
            ->assertSee('Les canaux (e-mail, application) sont réglés par l&#039;administrateur.', false);
    }

    public function test_sans_le_privilege_un_interrupteur_envoye_par_le_navigateur_est_refuse(): void
    {
        $user = $this->utilisateur('Agent');
        $this->actingAs($user);

        Livewire::test(NotificationPreferences::class)
            ->set('preferences.courriers.email', false)
            ->assertForbidden();

        $this->assertNull($user->fresh()->preferences_notifications);
    }

    public function test_sans_le_privilege_lintervalle_senregistre_sans_aucun_canal(): void
    {
        $user = $this->utilisateur('Agent');
        $this->actingAs($user);

        Livewire::test(NotificationPreferences::class)
            ->call('definirRelance', 0, 6)
            ->assertHasNoErrors();

        $this->assertSame(['sla_relance_heures' => 6], $user->fresh()->preferences_notifications);
        $this->assertSame(6, $user->fresh()->frequenceRelanceSlaHeures());
    }

    // 2026-11-11 (« set it them self like in the clock phone », puis « inside
    // a modal ») — résumé sur la page, minuteur jours + heures dans une
    // modale, ouvert sur l'intervalle appliqué.
    public function test_le_minuteur_souvre_sur_lintervalle_applique(): void
    {
        $user = $this->utilisateur('Agent');
        $user->forceFill(['preferences_notifications' => ['sla_relance_heures' => 30]])->save();
        $this->actingAs($user);

        Livewire::test(NotificationPreferences::class)
            ->assertSet('relanceHeures', 30)
            ->assertSee('roueIntervalle(1, 6,', false)
            ->assertSee('1 jour 6 heures')
            ->assertSee('Personnalisé — administrateur : 2 jours')
            ->assertSee('Intervalle des rappels');
    }

    public function test_enregistrer_ferme_la_modale_et_renvoie_lintervalle_aux_roues(): void
    {
        $this->actingAs($this->utilisateur('Agent'));

        Livewire::test(NotificationPreferences::class)
            ->call('definirRelance', 0, 6)
            ->assertHasNoErrors()
            ->assertDispatched('modal-close', name: 'minuteur-relance')
            ->assertDispatched('relance-replacee', jours: 0, heures: 6)
            ->assertSee('6 heures');
    }

    // Égal au réglage de l'administrateur : rien de personnel n'est gardé,
    // l'intervalle suit ses changements futurs.
    public function test_un_intervalle_remis_comme_ladministrateur_suit_ses_changements(): void
    {
        $user = $this->utilisateur('Agent');
        $this->actingAs($user);

        // Réglage administrateur d'une base neuve : 2 jours.
        Livewire::test(NotificationPreferences::class)
            ->call('definirRelance', 0, 6)
            ->assertSet('relanceHeures', 6)
            ->call('definirRelance', 2, 0)
            ->assertSet('relanceHeures', null);

        $this->assertNull($user->fresh()->preferences_notifications);

        Parametre::actuel()->update(['sla_relance_jours' => 3]);
        Parametre::invaliderCache();

        $this->assertSame(72, $user->fresh()->frequenceRelanceSlaHeures());
    }

    public function test_revenir_au_reglage_de_ladministrateur_replace_les_roues(): void
    {
        $user = $this->utilisateur('Agent');
        $user->forceFill(['preferences_notifications' => ['sla_relance_heures' => 6]])->save();
        $this->actingAs($user);

        Livewire::test(NotificationPreferences::class)
            ->call('relanceParDefaut')
            ->assertSet('relanceHeures', null)
            ->assertDispatched('relance-replacee', jours: 2, heures: 0);

        $this->assertNull($user->fresh()->preferences_notifications);
    }

    // Un choix de canal enregistré avant le 2026-11-10 n'est plus appliqué
    // (ignoré, pas effacé) ; le rythme des rappels, lui, reste le sien.
    public function test_sans_le_privilege_un_choix_de_canal_enregistre_avant_est_ignore(): void
    {
        $user = $this->utilisateur('Agent');
        $user->forceFill(['preferences_notifications' => [
            'courriers' => ['email' => false, 'application' => false],
            'sla_relance_jours' => 3,
        ]])->save();

        $this->assertSame(['mail', 'database', 'broadcast'], $this->canauxCourrier($user->fresh()));
        // Rythme enregistré en jours avant le 2026-11-11 : toujours valable.
        $this->assertSame(72, $user->fresh()->frequenceRelanceSlaHeures());
    }

    public function test_chaque_interrupteur_senregistre_aussitot_et_lintervalle_va_de_un_a_dix_jours(): void
    {
        $user = $this->utilisateur('Administrateur');
        $this->actingAs($user);

        Livewire::test(NotificationPreferences::class)
            ->assertSet('preferences.courriers.email', true)
            ->assertSet('preferences.courriers.application', true)
            ->assertSet('relanceHeures', null)
            ->set('preferences.courriers.email', false)
            ->assertHasNoErrors()
            ->call('definirRelance', 10, 0)
            ->assertHasNoErrors()
            ->assertSet('personnalisees', ['courriers']);

        $user->refresh();
        $this->assertFalse($user->preferences_notifications['courriers']['email']);
        // Laissé tel quel : suit l'administrateur, rien n'est figé.
        $this->assertNull($user->preferences_notifications['courriers']['application']);
        $this->assertSame(240, $user->frequenceRelanceSlaHeures());
        $this->assertSame(['database', 'broadcast'], $this->canauxCourrier($user));
    }

    // Bornes du minuteur : au moins 1 heure, au plus 10 jours pile.
    public function test_un_intervalle_hors_bornes_est_refuse(): void
    {
        $user = $this->utilisateur();
        $this->actingAs($user);

        foreach ([[10, 1], [11, 0], [0, 0], [0, 24], [-1, 5]] as [$jours, $heures]) {
            Livewire::test(NotificationPreferences::class)
                ->call('definirRelance', $jours, $heures)
                ->assertHasErrors('relance');
        }

        $this->assertNull($user->fresh()->preferences_notifications);
    }

    public function test_lintervalle_ne_change_que_par_le_minuteur(): void
    {
        $this->actingAs($this->utilisateur());

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::test(NotificationPreferences::class)->set('relanceHeures', 1000);
    }

    public function test_une_categorie_inconnue_envoyee_par_le_navigateur_nest_jamais_enregistree(): void
    {
        $user = $this->utilisateur('Administrateur');
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
        $user = $this->utilisateur('Administrateur');
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
        $user = $this->utilisateur('Administrateur');
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
        $user = $this->utilisateur('Administrateur');
        $user->forceFill(['preferences_notifications' => [
            'courriers' => ['email' => false, 'application' => null],
            'sla_relance_jours' => 3,
        ]])->save();

        $this->actingAs($user);
        Livewire::test(NotificationPreferences::class)
            ->assertSee('Rétablir les réglages de l\'administrateur')
            ->call('reinitialiser')
            ->assertSet('preferences.courriers.email', true)
            ->assertSet('relanceHeures', null)
            ->assertSet('personnalisees', [])
            ->assertDontSee('Rétablir les réglages de l\'administrateur');

        $this->assertNull($user->fresh()->preferences_notifications);
        $this->assertSame(['mail', 'database', 'broadcast'], $this->canauxCourrier($user->fresh()));
    }

    public function test_un_avertissement_saffiche_quand_aucun_rappel_ne_peut_arriver(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        Livewire::test(NotificationPreferences::class)
            ->assertDontSee('vous ne recevrez aucun rappel')
            ->set('preferences.alertes_sla.email', false)
            ->assertDontSee('vous ne recevrez aucun rappel')
            ->set('preferences.alertes_sla.application', false)
            ->assertSee('vous ne recevrez aucun rappel');
    }

    // Sans le privilège, seul l'administrateur peut couper les alertes de
    // délai : l'avertissement le dit.
    public function test_sans_le_privilege_lavertissement_designe_ladministrateur(): void
    {
        Parametre::actuel()->update(['notifications_defaut' => [
            'alertes_sla' => ['email' => false, 'application' => false],
        ]]);
        Parametre::invaliderCache();

        $this->actingAs($this->utilisateur('Agent'));

        Livewire::test(NotificationPreferences::class)
            ->assertSee('a désactivé les alertes de délai sur les deux canaux');
    }

    public function test_la_frequence_par_defaut_reste_celle_configuree_par_ladministrateur(): void
    {
        $user = $this->utilisateur();
        Parametre::actuel()->update(['sla_relance_jours' => 4]);
        Parametre::invaliderCache();

        $this->assertSame(96, $user->frequenceRelanceSlaHeures());
        $this->assertSame(
            ['mail', 'database', 'broadcast'],
            app(NotificationPreferenceService::class)->canaux($user, 'courriers'),
        );
    }
}
