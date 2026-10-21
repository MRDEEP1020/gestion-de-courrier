<?php

namespace Tests\Feature;

use App\Livewire\Backend\NotificationBell;
use App\Models\Courrier;
use App\Models\Privilege;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CourrierEnRetardNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Module 7 — centre de notifications in-app (2026-10-05).
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecPrivilege(string $cle): User
    {
        $user = User::factory()->create(['profil_id' => null]);
        $user->privilegesDirectes()->attach(Privilege::where('cle', $cle)->firstOrFail()->id);

        return User::find($user->id);
    }

    private function courrier(): Courrier
    {
        return Courrier::create([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Réclamation client',
            'type_document' => 'Réclamation',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id,
            'statut' => 'affecte',
        ]);
    }

    public function test_le_compteur_reflete_les_notifications_non_lues(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $courrier = $this->courrier();
        $utilisateur->notify(new CourrierEnRetardNotification($courrier, enRetard: true));
        $utilisateur->notify(new CourrierEnRetardNotification($courrier, enRetard: false));

        Livewire::actingAs($utilisateur)
            ->test(NotificationBell::class)
            ->assertSee('2')
            ->assertSee($courrier->numero_reference);
    }

    public function test_marquer_comme_lue_ne_compte_plus_dans_le_badge(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $courrier = $this->courrier();
        $utilisateur->notify(new CourrierEnRetardNotification($courrier, enRetard: true));
        $id = $utilisateur->notifications()->first()->id;

        // 2026-10-12 — 'bg-brand-danger' n'est plus un proxy fiable pour "le
        // badge de compteur a disparu" depuis que chaque notification porte
        // SA PROPRE pastille de couleur (voir notification-bell.blade.php,
        // retour utilisateur "i can't disting between them") : une
        // notification "en retard" (danger) GARDE cette classe sur sa
        // pastille même une fois lue, seul le compteur doit disparaître.
        Livewire::actingAs($utilisateur)
            ->test(NotificationBell::class)
            ->call('marquerCommeLue', $id)
            ->assertSee('data-notifications-non-lues="0"', false);

        $this->assertNotNull($utilisateur->notifications()->find($id)->read_at);
    }

    public function test_tout_marquer_comme_lu_vide_le_badge(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $courrier = $this->courrier();
        $utilisateur->notify(new CourrierEnRetardNotification($courrier, enRetard: true));
        $utilisateur->notify(new CourrierEnRetardNotification($courrier, enRetard: false));

        Livewire::actingAs($utilisateur)
            ->test(NotificationBell::class)
            ->call('marquerToutesCommeLues');

        $this->assertSame(0, $utilisateur->fresh()->unreadNotifications()->count());
    }

    // Règle n°6 — jamais les notifications d'un autre utilisateur, même par
    // un ID de notification deviné/passé côté client.
    public function test_marquer_comme_lue_ne_touche_jamais_la_notification_dun_autre_utilisateur(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $autre = User::factory()->create(['profil_id' => null]);
        $courrier = $this->courrier();
        $autre->notify(new CourrierEnRetardNotification($courrier, enRetard: true));
        $idAutre = $autre->notifications()->first()->id;

        Livewire::actingAs($utilisateur)
            ->test(NotificationBell::class)
            ->call('marquerCommeLue', $idAutre);

        $this->assertNull($autre->notifications()->find($idAutre)->read_at);
    }

    public function test_sans_notification_affiche_le_message_par_defaut(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');

        Livewire::actingAs($utilisateur)
            ->test(NotificationBell::class)
            ->assertSee(__('Aucune notification pour l\'instant.'));
    }
}
