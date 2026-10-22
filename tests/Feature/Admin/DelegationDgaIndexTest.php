<?php

namespace Tests\Feature\Admin;

use App\Livewire\Backend\DelegationDgaIndex;
use App\Models\DelegationDga;
use App\Models\Profil;
use App\Models\User;
use App\Notifications\DelegationDgaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Module 1/4 — "Délégation DGA" (2026-10-06, entretien terrain
// réceptionniste, voir DECISIONS.md "Délégation DGA/ADJ absents") : page
// d'administration à privilège unique, même gabarit que DossierSurveilleTest.
class DelegationDgaIndexTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    public function test_un_administrateur_ouvre_la_page_et_la_voit_dans_le_menu(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        $this->get(route('admin.delegation-dga'))
            ->assertOk()
            ->assertSee('Activer une délégation')
            ->assertSee(route('admin.delegation-dga'));
    }

    public function test_un_agent_sans_le_privilege_est_refuse_et_ne_voit_pas_le_menu(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        $this->get(route('admin.delegation-dga'))->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee(route('admin.delegation-dga'));
    }

    public function test_un_administrateur_active_une_delegation_dun_dga_vers_un_autre_utilisateur(): void
    {
        Notification::fake();
        $dga = $this->utilisateurAvecProfil('DGA');
        $rh = $this->utilisateurAvecProfil('Collaborateur');
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $this->actingAs($admin);

        Livewire::test(DelegationDgaIndex::class)
            ->set('delegantId', $dga->id)
            ->set('delegataireId', $rh->id)
            ->set('motif', 'Congés DGA et ADJ')
            ->call('activerDelegation')
            ->assertHasNoErrors()
            ->assertSee($dga->name)
            ->assertSee($rh->name);

        $this->assertDatabaseHas('delegations_dga', [
            'delegant_id' => $dga->id,
            'delegataire_id' => $rh->id,
            'motif' => 'Congés DGA et ADJ',
            'actif' => true,
        ]);
        // 2026-10-09 (audit "list me all") — le délégataire ne savait
        // jusqu'ici jamais qu'il avait gagné ce pouvoir temporaire.
        Notification::assertSentTo($rh, DelegationDgaNotification::class, fn ($n) => $n->action === 'activee' && $n->envoyePar?->id === $admin->id);
    }

    // Filet de sécurité serveur : ne pas se fier au select de l'UI (Règle
    // n°6) — un utilisateur sans le privilège DGA/ADJ n'a aucun effet en
    // delegant, inutile de créer une délégation inerte.
    public function test_refuse_un_delegant_sans_le_privilege_dga(): void
    {
        $sansPrivilege = $this->utilisateurAvecProfil('Agent');
        $rh = $this->utilisateurAvecProfil('Collaborateur');
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(DelegationDgaIndex::class)
            ->set('delegantId', $sansPrivilege->id)
            ->set('delegataireId', $rh->id)
            ->call('activerDelegation')
            ->assertHasErrors('delegantId');

        $this->assertDatabaseMissing('delegations_dga', ['delegant_id' => $sansPrivilege->id]);
    }

    public function test_desactiver_une_delegation_la_retire_des_actives_et_larchive_dans_lhistorique(): void
    {
        Notification::fake();
        $dga = $this->utilisateurAvecProfil('DGA');
        $rh = $this->utilisateurAvecProfil('Collaborateur');
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $this->actingAs($admin);

        $delegation = DelegationDga::create([
            'delegant_id' => $dga->id,
            'delegataire_id' => $rh->id,
            'debut_le' => now(),
            'actif' => true,
            'active_par_id' => $admin->id,
        ]);

        Livewire::test(DelegationDgaIndex::class)
            ->call('desactiverDelegation', $delegation->id)
            ->assertSee('Aucune délégation active pour l\'instant.');

        $delegation->refresh();
        $this->assertFalse($delegation->actif);
        $this->assertSame($admin->id, $delegation->desactive_par_id);
        $this->assertNotNull($delegation->desactive_le);
        Notification::assertSentTo($rh, DelegationDgaNotification::class, fn ($n) => $n->action === 'desactivee' && $n->envoyePar?->id === $admin->id);
    }
}
