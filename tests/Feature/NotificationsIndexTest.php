<?php

namespace Tests\Feature;

use App\Livewire\Backend\NotificationsIndex;
use App\Models\Courrier;
use App\Models\Privilege;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CourrierEnRetardNotification;
use App\Notifications\CourrierNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Module 7 — centre de notifications in-app, page complète (2026-10-05).
class NotificationsIndexTest extends TestCase
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

    public function test_sans_le_privilege_redirige_vers_le_tableau_de_bord(): void
    {
        $utilisateur = User::factory()->create(['profil_id' => null]);

        Livewire::actingAs($utilisateur)
            ->test(NotificationsIndex::class)
            ->assertRedirect(route('dashboard'));
    }

    public function test_liste_les_notifications_de_lutilisateur_avec_pagination(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $courrier = $this->courrier();

        foreach (range(1, 3) as $i) {
            $utilisateur->notify(new CourrierEnRetardNotification($courrier, enRetard: true));
        }

        Livewire::actingAs($utilisateur)
            ->test(NotificationsIndex::class)
            ->assertSee($courrier->numero_reference)
            ->assertSee(__('Tout marquer comme lu'));
    }

    // Règle n°3 — toujours paginé, jamais ->get() brut.
    public function test_est_paginee_a_20_par_page(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $courrier = $this->courrier();

        foreach (range(1, 25) as $i) {
            $utilisateur->notify(new CourrierEnRetardNotification($courrier, enRetard: true));
        }

        $this->assertSame(25, $utilisateur->notifications()->count());

        Livewire::actingAs($utilisateur)->test(NotificationsIndex::class)
            ->assertViewHas('notifications', fn ($notifications) => $notifications->count() === 20 && $notifications->total() === 25);
    }

    public function test_marquer_comme_lue_fonctionne_depuis_la_page(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $courrier = $this->courrier();
        $utilisateur->notify(new CourrierEnRetardNotification($courrier, enRetard: true));
        $id = $utilisateur->notifications()->first()->id;

        Livewire::actingAs($utilisateur)
            ->test(NotificationsIndex::class)
            ->call('marquerCommeLue', $id);

        $this->assertNotNull($utilisateur->notifications()->find($id)->read_at);
    }

    // 2026-10-12, demande explicite de l'utilisateur ("make each
    // notification to show who send it").
    public function test_affiche_lauteur_de_la_notification_quand_disponible(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $courrier = $this->courrier();
        $auteur = User::factory()->create(['profil_id' => null, 'name' => 'Jean Dupont']);
        $utilisateur->notify(new CourrierNotification($courrier, 'affectation', envoyePar: $auteur));

        Livewire::actingAs($utilisateur)
            ->test(NotificationsIndex::class)
            ->assertSee('Jean Dupont');
    }

    // Alerte SLA automatique (SendMailAlertJob) : aucun auteur humain réel,
    // la ligne "Envoyé par" ne doit pas apparaître du tout.
    public function test_naffiche_pas_denvoye_par_pour_une_alerte_automatique(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $courrier = $this->courrier();
        $utilisateur->notify(new CourrierEnRetardNotification($courrier, enRetard: true));

        Livewire::actingAs($utilisateur)
            ->test(NotificationsIndex::class)
            ->assertDontSee('Envoyé par');
    }

    public function test_narrive_jamais_a_voir_les_notifications_dun_autre_utilisateur(): void
    {
        $utilisateur = $this->utilisateurAvecPrivilege('general.notifications');
        $autre = User::factory()->create(['profil_id' => null]);
        $courrier = $this->courrier();
        $autre->notify(new CourrierEnRetardNotification($courrier, enRetard: true));

        Livewire::actingAs($utilisateur)
            ->test(NotificationsIndex::class)
            ->assertSee(__('Aucune notification pour l\'instant.'));
    }
}
