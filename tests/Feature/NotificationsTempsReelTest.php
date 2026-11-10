<?php

namespace Tests\Feature;

use App\Livewire\Backend\CourrierList;
use App\Livewire\Backend\CourriersEnregistres;
use App\Livewire\Backend\Dashboard;
use App\Livewire\Backend\MesCourriers;
use App\Livewire\Backend\NotificationBell;
use App\Livewire\Backend\NotificationsIndex;
use App\Models\Profil;
use App\Models\User;
use App\Notifications\CompteUtilisateurNotification;
use App\Notifications\CourrierEnRetardNotification;
use App\Notifications\CourrierNotification;
use App\Notifications\DossierPartageNotification;
use App\Notifications\OrganisationRattachementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Livewire\Attributes\On;
use Livewire\Livewire;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

// 2026-10-27 (tâche « Real time alert without the need of refreshing the
// page ») — chaque notification est diffusée en direct sur le canal privé de
// son destinataire ; la cloche l'écoute et se met à jour sans recharger.
class NotificationsTempsReelTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $profil = 'Agent'): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $profil])->id]);
    }

    public function test_toutes_les_notifications_sont_aussi_diffusees_en_direct(): void
    {
        $user = $this->utilisateur();

        foreach ([CompteUtilisateurNotification::class, CourrierEnRetardNotification::class, CourrierNotification::class, DossierPartageNotification::class, OrganisationRattachementNotification::class] as $classe) {
            $notification = (new ReflectionClass($classe))->newInstanceWithoutConstructor();

            $this->assertContains('broadcast', $notification->via($user), "{$classe} n'est pas diffusée en direct.");
            $this->assertSame('notification.gec', $notification->broadcastAs());
        }
    }

    public function test_une_notification_part_sur_le_canal_prive_de_son_seul_destinataire(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);
        $user = $this->utilisateur();

        $user->notify(new CompteUtilisateurNotification('modifie', envoyePar: $this->utilisateur('Administrateur')));

        Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $evenement) use ($user) {
            return $evenement->broadcastAs() === 'notification.gec'
                && $evenement->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id
                && filled($evenement->broadcastWith()['libelle'] ?? null);
        });
    }

    // « Comme Outlook » : la page ouverte (listes, tableau de bord, page
    // Notifications) se recharge d'elle-même quand une notification arrive.
    public function test_les_listes_se_rechargent_quand_une_notification_arrive(): void
    {
        $listeners = [
            MesCourriers::class => 'actualiserEnDirect',
            CourrierList::class => 'actualiserEnDirect',
            CourriersEnregistres::class => 'actualiserEnDirect',
            Dashboard::class => 'rafraichirNotifications',
            NotificationsIndex::class => 'rafraichir',
        ];

        foreach ($listeners as $composant => $methode) {
            $attributs = (new ReflectionMethod($composant, $methode))->getAttributes(On::class);

            $this->assertNotEmpty($attributs, "{$composant}::{$methode} n'écoute aucun événement.");
            $this->assertSame('notifications-mises-a-jour', $attributs[0]->newInstance()->event, "{$composant} ne se recharge pas en direct.");
        }
    }

    // 2026-10-27 — un onglet resté ouvert après déconnexion redemande
    // l'autorisation de son canal privé : la page 403 rendue pour ce visiteur
    // non connecté plantait en 500 (sidebar sans utilisateur).
    public function test_la_page_403_d_un_visiteur_non_connecte_ne_plante_pas(): void
    {
        $this->view('errors.403', ['exception' => new AccessDeniedHttpException])
            ->assertSee(__('Se connecter'))
            ->assertDontSee(__('Retour au tableau de bord'));

        $this->actingAs($this->utilisateur());

        $this->view('errors.403', ['exception' => new AccessDeniedHttpException])
            ->assertSee(__('Retour au tableau de bord'));
    }

    public function test_la_cloche_ecoute_le_canal_prive_et_se_met_a_jour_sans_recharger(): void
    {
        $ecoute = (new ReflectionMethod(NotificationBell::class, 'notificationRecue'))->getAttributes(On::class)[0]->newInstance();
        $this->assertSame('echo-private:App.Models.User.{utilisateurId},.notification.gec', $ecoute->event);

        $user = $this->utilisateur();
        $this->actingAs($user);

        $cloche = Livewire::test(NotificationBell::class)
            ->assertSet('utilisateurId', $user->id);

        // La notification arrive en base pendant que la page est ouverte…
        $user->notify(new CompteUtilisateurNotification('modifie', envoyePar: $this->utilisateur('Administrateur')));

        // …puis l'événement Reverb déclenche la mise à jour.
        $cloche->call('notificationRecue', ['libelle' => 'Votre compte a été modifié', 'sous_libelle' => 'par Admin', 'couleur' => 'blue', 'lien' => '/settings/profile'])
            ->assertDispatched('notifications-mises-a-jour')
            ->assertDispatched('toast-show')
            ->assertSee('Votre compte a été modifié')
            ->assertSeeHtml('data-notifications-non-lues="1"');
    }
}
