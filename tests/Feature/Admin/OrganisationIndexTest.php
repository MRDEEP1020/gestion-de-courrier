<?php

namespace Tests\Feature\Admin;

use App\Livewire\Backend\OrganisationIndex;
use App\Models\OrganizationUnit;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Notifications\OrganisationRattachementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Module "Organisation" v2 (2026-09-22, spec technique complète fournie par
// l'utilisateur) — hiérarchie dynamique Company/Site/Department/Service/
// Sub-service (App\Models\OrganizationUnit). Remplace ServiceListTest/
// OrganisationListTest du tour précédent.
class OrganisationIndexTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(['profil_id' => $profil->id]);
    }

    public function test_un_utilisateur_sans_le_privilege_ne_peut_pas_ouvrir_la_page(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(OrganisationIndex::class)->assertForbidden();
    }

    public function test_administrateur_a_tous_les_privileges_organisation_par_defaut(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');

        foreach (['organisation.view', 'organisation.create', 'organisation.update', 'organisation.deactivate', 'organisation.manage_users', 'organisation.manage_structure'] as $cle) {
            $this->assertTrue($admin->hasPrivilege($cle), "manque le privilège {$cle}");
            $this->assertTrue(Privilege::where('cle', $cle)->exists());
        }
    }

    // ===== Créer =====

    public function test_creer_un_site_racine(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation')
            ->set('typeNoeud', OrganizationUnit::TYPE_SITE)
            ->set('nomNoeud', 'Agence Douala')
            ->set('codeNoeud', 'AG-DLA')
            ->call('enregistrerNoeud')
            ->assertHasNoErrors();

        $site = OrganizationUnit::where('code', 'AG-DLA')->firstOrFail();
        $this->assertSame('Agence Douala', $site->name);
        $this->assertSame(OrganizationUnit::TYPE_SITE, $site->type);
        $this->assertNull($site->parent_id);
    }

    public function test_creer_un_departement_sous_un_site(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);
        $this->actingAs($admin);

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation', $site->id)
            ->set('nomNoeud', 'Département Sinistre')
            ->set('typeNoeud', OrganizationUnit::TYPE_DEPARTMENT)
            ->call('enregistrerNoeud')
            ->assertHasNoErrors();

        $departement = OrganizationUnit::where('name', 'Département Sinistre')->firstOrFail();
        $this->assertSame($site->id, $departement->parent_id);
    }

    // Ne pas permettre des relations incohérentes (spec §7/§8) — vérifié
    // côté serveur, pas seulement l'UI.
    public function test_refuse_un_type_incoherent_avec_le_parent(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $service = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SERVICE]);
        $this->actingAs($admin);

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation', $service->id)
            ->set('nomNoeud', 'Site invalide')
            ->set('typeNoeud', OrganizationUnit::TYPE_SITE)
            ->call('enregistrerNoeud')
            ->assertHasErrors('typeNoeud');

        $this->assertDatabaseMissing('organization_units', ['name' => 'Site invalide']);
    }

    public function test_le_service_reel_lie_ponte_correctement(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $serviceReel = Service::factory()->create();
        $this->actingAs($admin);

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation')
            ->set('typeNoeud', OrganizationUnit::TYPE_SERVICE)
            ->set('nomNoeud', 'Sinistre Santé')
            ->set('servicePontNoeud', $serviceReel->id)
            ->call('enregistrerNoeud')
            ->assertHasNoErrors();

        $unite = OrganizationUnit::where('name', 'Sinistre Santé')->firstOrFail();
        $this->assertSame($serviceReel->id, $unite->service_id);
    }

    // 2026-10-06 ("seperate the site modal, department,service and sou
    // service modal") — remplace l'ancien test de rafraîchissement réactif
    // du sélecteur "Type" (retiré, l'UI n'a plus de sélecteur : chaque type
    // a sa propre modale, ouverte directement par OuvrirCreation/
    // ouvrirModification via modalPourType()). On vérifie désormais que
    // ouvrirCreation() pointe simplement vers le bon type selon le parent.
    public function test_ouvrir_creation_determine_le_type_depuis_le_parent(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $this->actingAs($admin);

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation')
            ->assertSet('typeNoeud', OrganizationUnit::TYPE_SITE);

        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);

        // 2026-10-09 — niveau Direction ajouté (voir le journal de développement) :
        // le type proposé par défaut pour l'enfant d'un Site est désormais
        // Direction, plus Département directement.
        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation', $site->id)
            ->assertSet('typeNoeud', OrganizationUnit::TYPE_DIRECTION);

        $direction = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DIRECTION, 'parent_id' => $site->id]);

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation', $direction->id)
            ->assertSet('typeNoeud', OrganizationUnit::TYPE_DEPARTMENT);
    }

    // 2026-10-09 — second bouton "Ajouter un département directement" sur
    // un Site (voir organisationIndex.blade.php) : un Département n'a pas
    // forcément de Direction au-dessus (ex. "Département Informatique").
    public function test_ouvrir_creation_accepte_un_type_force_pour_un_departement_direct_sous_site(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $this->actingAs($admin);

        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation', $site->id, OrganizationUnit::TYPE_DEPARTMENT)
            ->assertSet('typeNoeud', OrganizationUnit::TYPE_DEPARTMENT)
            ->set('nomNoeud', 'Département Informatique')
            ->call('enregistrerNoeud')
            ->assertHasNoErrors();

        $noeud = OrganizationUnit::where('name', 'Département Informatique')->firstOrFail();
        $this->assertSame($site->id, $noeud->parent_id);
        $this->assertSame(OrganizationUnit::TYPE_DEPARTMENT, $noeud->type);
    }

    // 2026-09-23, demande explicite de l'utilisateur ("on the form it shows
    // the same tabs") — Département et Service/Unité affichent le même
    // champ "Service réel lié" (les deux peuvent être pontés, spec §1), la
    // description sous le sélecteur doit donc être le seul signal visuel
    // qui prouve que le changement d'onglet a bien été pris en compte.
    public function test_la_description_du_type_change_selon_longlet_choisi(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $this->actingAs($admin);

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirCreation')
            ->set('typeNoeud', OrganizationUnit::TYPE_DEPARTMENT)
            ->assertSee('ou contenir des Services')
            ->set('typeNoeud', OrganizationUnit::TYPE_SERVICE)
            ->assertSee('peut lui-même contenir des Sous-services');
    }

    // ===== Déplacer (anti-cycle) =====

    public function test_deplacer_une_entite_vers_un_nouveau_parent(): void
    {
        $siteA = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);
        $siteB = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);
        $departement = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => $siteA->id]);

        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirDeplacement', $departement->id)
            ->set('nouveauParentId', $siteB->id)
            ->call('deplacerNoeud');

        $this->assertSame($siteB->id, $departement->fresh()->parent_id);
    }

    public function test_deplacement_impossible_dans_son_propre_descendant(): void
    {
        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);
        $departement = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => $site->id]);

        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(OrganisationIndex::class)
            ->call('ouvrirDeplacement', $site->id)
            ->set('nouveauParentId', $departement->id)
            ->call('deplacerNoeud');

        $this->assertNull($site->fresh()->parent_id);
    }

    // ===== Jamais de suppression, seulement activer/désactiver (spec §15) =====

    public function test_basculer_statut_desactive_puis_reactive(): void
    {
        $unite = OrganizationUnit::factory()->create(['status' => OrganizationUnit::STATUT_ACTIF]);
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(OrganisationIndex::class)->call('basculerStatut', $unite->id);
        $this->assertSame(OrganizationUnit::STATUT_INACTIF, $unite->fresh()->status);

        Livewire::test(OrganisationIndex::class)->call('basculerStatut', $unite->id);
        $this->assertSame(OrganizationUnit::STATUT_ACTIF, $unite->fresh()->status);
    }

    // ===== Rattachement d'utilisateurs (spec §5/§10) =====

    public function test_rattacher_puis_retirer_un_utilisateur(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $unite = OrganizationUnit::factory()->create();
        $membre = $this->utilisateurAvecProfil('Collaborateur');
        $this->actingAs($admin);

        Livewire::test(OrganisationIndex::class)
            ->call('selectionnerNoeud', $unite->id)
            ->set('utilisateurAAjouterId', $membre->id)
            ->set('roleUtilisateurAAjouter', 'Collaborateur')
            ->call('ajouterUtilisateur')
            ->assertHasNoErrors();

        $this->assertTrue($unite->utilisateurs()->where('users.id', $membre->id)->exists());
        // 2026-10-09 (audit "list me all").
        Notification::assertSentTo($membre, OrganisationRattachementNotification::class, fn ($n) => $n->action === 'rattache' && $n->envoyePar?->id === $admin->id);

        Livewire::test(OrganisationIndex::class)
            ->call('selectionnerNoeud', $unite->id)
            ->call('retirerUtilisateur', $membre->id);

        $this->assertFalse($unite->utilisateurs()->where('users.id', $membre->id)->exists());
    }

    // 2026-10-09 (audit "list me all").
    public function test_definir_comme_responsable_notifie_le_nouveau_responsable(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $unite = OrganizationUnit::factory()->create();
        $futurResponsable = $this->utilisateurAvecProfil('Responsable de service');
        $this->actingAs($admin);

        Livewire::test(OrganisationIndex::class)
            ->call('selectionnerNoeud', $unite->id)
            ->call('definirCommeResponsable', $futurResponsable->id);

        $this->assertSame($futurResponsable->id, $unite->fresh()->responsible_user_id);
        Notification::assertSentTo($futurResponsable, OrganisationRattachementNotification::class, fn ($n) => $n->action === 'responsable' && $n->envoyePar?->id === $admin->id);
    }

    // 2026-09-24 — la fonction d'un membre se corrige après coup.
    public function test_modifier_la_fonction_dun_membre(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $unite = OrganizationUnit::factory()->create();
        $membre = $this->utilisateurAvecProfil('Collaborateur');
        $unite->utilisateurs()->attach($membre->id, ['role_in_unit' => null]);
        $this->actingAs($admin);

        Livewire::test(OrganisationIndex::class)
            ->call('selectionnerNoeud', $unite->id)
            ->call('modifierFonction', $membre->id)
            ->assertSet('membreFonctionEditionId', $membre->id)
            ->set('fonctionEdition', 'Chargé de clientèle')
            ->call('enregistrerFonction')
            ->assertHasNoErrors()
            ->assertSet('membreFonctionEditionId', null)
            ->assertSee('Chargé de clientèle');

        $this->assertSame('Chargé de clientèle', $unite->utilisateurs()->where('users.id', $membre->id)->first()->pivot->role_in_unit);
    }

    public function test_modifier_la_fonction_refuse_sans_le_privilege(): void
    {
        $unite = OrganizationUnit::factory()->create();
        $membre = $this->utilisateurAvecProfil('Collaborateur');
        $unite->utilisateurs()->attach($membre->id, ['role_in_unit' => 'Collaborateur']);
        $lecteur = $this->utilisateurAvecProfil('Agent');
        $lecteur->privilegesDirectes()->attach(Privilege::where('cle', 'organisation.view')->firstOrFail());
        $this->actingAs($lecteur->fresh());

        Livewire::test(OrganisationIndex::class)
            ->call('selectionnerNoeud', $unite->id)
            ->call('modifierFonction', $membre->id)
            ->assertForbidden();

        $this->assertSame('Collaborateur', $unite->utilisateurs()->where('users.id', $membre->id)->first()->pivot->role_in_unit);
    }

    // ===== Statistiques réellement calculées (spec §11) =====

    public function test_le_panneau_de_details_affiche_les_vrais_compteurs(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $unite = OrganizationUnit::factory()->create();
        $unite->utilisateurs()->attach($this->utilisateurAvecProfil('Collaborateur')->id);
        $unite->utilisateurs()->attach($this->utilisateurAvecProfil('Collaborateur')->id);
        OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SUB_SERVICE, 'parent_id' => $unite->id]);
        $this->actingAs($admin);

        Livewire::test(OrganisationIndex::class)
            ->call('selectionnerNoeud', $unite->id)
            ->assertSee('2') // utilisateurs_count
            ->assertSee('1'); // sous-service
    }

    // ===== Recherche globale (spec §12) =====

    public function test_la_recherche_trouve_une_entite_par_nom(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE, 'name' => 'Agence Douala']);
        OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'name' => 'Département Sinistre', 'parent_id' => $site->id]);
        $this->actingAs($admin);

        $resultats = Livewire::test(OrganisationIndex::class)
            ->set('recherche', 'Sinistre')
            ->get('resultatsRecherche');

        $this->assertCount(1, $resultats);
        $this->assertStringContainsString('Agence Douala', $resultats->first()['chemin']);
    }

    public function test_la_recherche_trouve_une_entite_par_utilisateur_rattache(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $unite = OrganizationUnit::factory()->create(['name' => 'Sinistre Santé']);
        $membre = User::factory()->create(['name' => 'MANGA André', 'profil_id' => Profil::firstOrCreate(['nom' => 'Collaborateur'])->id]);
        $unite->utilisateurs()->attach($membre->id);
        $this->actingAs($admin);

        $resultats = Livewire::test(OrganisationIndex::class)
            ->set('recherche', 'MANGA')
            ->get('resultatsRecherche');

        $this->assertCount(1, $resultats);
        $this->assertSame('Sinistre Santé', $resultats->first()['noeud']->name);
    }
}
