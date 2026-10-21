<?php

namespace Tests\Feature\Admin;

use App\Livewire\Backend\ProfilList;
use App\Models\Courrier;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Système de privilèges — assignation des privilèges PAR PROFIL. Page
// refaite le 2026-09-23 sur le modèle de "Utilisateurs & Accès" ("make
// profile page as userlist") : tableau + modale "Gestion des permissions"
// par module, à la place de l'ancien sélecteur + "deux boîtes".
// PrivilegeSeeder (semé automatiquement par Tests\TestCase) fournit les 5
// profils et le catalogue par défaut.
class ProfilListTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    public function test_le_tableau_liste_les_profils_avec_leurs_compteurs(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));
        $this->utilisateurAvecProfil('Agent');
        $nombreAgent = Profil::where('nom', 'Agent')->firstOrFail()->privileges()->count();

        Livewire::test(ProfilList::class)
            ->assertSee('Agent')
            ->assertSee('Responsable de service')
            ->assertSee('1 utilisateur')
            ->assertSee($nombreAgent.' / '.Privilege::count());
    }

    public function test_la_recherche_filtre_les_profils(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(ProfilList::class)
            ->set('recherche', 'Collab')
            ->assertSee('Collaborateur')
            ->assertDontSee('Responsable de service');
    }

    // Preuve bout en bout : décocher un privilège dans la modale retire
    // réellement le droit à tous les utilisateurs du profil.
    public function test_decocher_un_privilege_change_reellement_les_droits(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));
        $agent = $this->utilisateurAvecProfil('Agent');
        $privilege = Privilege::where('cle', 'courriers.rechercher')->firstOrFail();
        $this->assertTrue($agent->hasPrivilege('courriers.rechercher'));

        Livewire::test(ProfilList::class)
            ->call('ouvrirPermissions', $agent->profil_id)
            ->call('basculerPermission', $privilege->id);

        $agentApres = User::find($agent->id);
        $this->assertFalse($agentApres->hasPrivilege('courriers.rechercher'));
        $this->assertFalse($agentApres->can('rechercher', Courrier::class));
    }

    public function test_cocher_puis_decocher_un_privilege(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));
        $agent = $this->utilisateurAvecProfil('Agent');
        $privilege = Privilege::where('cle', 'courriers.voir_dga')->firstOrFail();

        $composant = Livewire::test(ProfilList::class)->call('ouvrirPermissions', $agent->profil_id);

        $composant->call('basculerPermission', $privilege->id);
        $this->assertTrue(User::find($agent->id)->hasPrivilege('courriers.voir_dga'));

        $composant->call('basculerPermission', $privilege->id);
        $this->assertFalse(User::find($agent->id)->hasPrivilege('courriers.voir_dga'));
    }

    public function test_la_modale_groupe_les_permissions_par_module(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));
        $agent = Profil::where('nom', 'Agent')->firstOrFail();

        Livewire::test(ProfilList::class)
            ->call('ouvrirPermissions', $agent->id)
            ->assertSee('Gestion des permissions')
            ->assertSee('Créer un courrier')
            ->set('modulePermissionSelectionne', 'dossiers_classement')
            ->assertSee('Créer un dossier de classement')
            ->assertDontSee('Créer un courrier');
    }

    // "Tout sélectionner" / "Tout désélectionner" ne touchent que le module
    // affiché, jamais le reste du catalogue.
    public function test_tout_selectionner_est_limite_au_module_affiche(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));
        $agent = Profil::where('nom', 'Agent')->firstOrFail();
        $horsModule = $agent->privileges()->where('cle', 'not like', 'dossiers_classement.%')->count();

        $composant = Livewire::test(ProfilList::class)
            ->call('ouvrirPermissions', $agent->id)
            ->set('modulePermissionSelectionne', 'dossiers_classement');

        $composant->call('toutSelectionnerModule');
        $this->assertSame(
            Privilege::where('cle', 'like', 'dossiers_classement.%')->count(),
            $agent->privileges()->where('cle', 'like', 'dossiers_classement.%')->count(),
        );

        $composant->call('toutDeselectionnerModule');
        $this->assertSame(0, $agent->privileges()->where('cle', 'like', 'dossiers_classement.%')->count());
        $this->assertSame($horsModule, $agent->privileges()->where('cle', 'not like', 'dossiers_classement.%')->count());
    }

    public function test_creer_un_profil_ouvre_directement_ses_permissions(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));
        $privilege = Privilege::where('cle', 'courriers.rechercher')->firstOrFail();

        $composant = Livewire::test(ProfilList::class)
            ->set('nouveauProfilNom', 'Auditeur')
            ->call('creerProfil')
            ->assertHasNoErrors();

        $profil = Profil::where('nom', 'Auditeur')->firstOrFail();
        $utilisateur = User::factory()->create(['profil_id' => $profil->id]);

        $composant->assertSet('profilEnEditionId', $profil->id)
            ->call('basculerPermission', $privilege->id);

        $this->assertTrue(User::find($utilisateur->id)->hasPrivilege('courriers.rechercher'));
    }

    public function test_un_nom_de_profil_deja_pris_est_refuse(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(ProfilList::class)
            ->set('nouveauProfilNom', 'Agent') // déjà semé par ProfilSeeder
            ->call('creerProfil')
            ->assertHasErrors(['nouveauProfilNom']);
    }

    public function test_un_agent_sans_privilege_ne_peut_pas_gerer_les_profils(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(ProfilList::class)->assertForbidden();
    }

    // Règle n°6 — l'id du profil en édition est une propriété publique :
    // chaque action revérifie le droit, même si la modale a été ouverte.
    public function test_basculer_une_permission_sans_le_droit_est_refuse(): void
    {
        $gestionnaire = User::factory()->create(['profil_id' => null]);
        $gestionnaire->privilegesDirectes()->attach(Privilege::where('cle', 'privileges.gerer')->value('id'));
        $this->actingAs(User::find($gestionnaire->id));
        $agent = Profil::where('nom', 'Agent')->firstOrFail();

        $composant = Livewire::test(ProfilList::class)->call('ouvrirPermissions', $agent->id);

        $gestionnaire->privilegesDirectes()->detach();
        $this->actingAs(User::find($gestionnaire->id));

        $composant->call('basculerPermission', Privilege::where('cle', 'courriers.voir_dga')->value('id'))->assertForbidden();
    }

    // Même garde-fou anti-verrouillage que PrivilegePolicy::gerer().
    public function test_un_administrateur_garde_lacces_meme_sans_le_privilege_explicite(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        Privilege::where('cle', 'privileges.gerer')->firstOrFail()->profils()->detach();

        $this->assertFalse($admin->fresh()->hasPrivilege('privileges.gerer'));

        $this->actingAs($admin);

        Livewire::test(ProfilList::class)->assertOk();
    }

    public function test_voir_les_utilisateurs_filtre_la_page_utilisateurs(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));
        $agent = Profil::where('nom', 'Agent')->firstOrFail();

        Livewire::test(ProfilList::class)
            ->assertSee(route('admin.utilisateurs', ['profilFiltreId' => $agent->id]), false);
    }
}
