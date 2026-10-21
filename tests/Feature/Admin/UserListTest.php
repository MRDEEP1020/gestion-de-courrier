<?php

namespace Tests\Feature\Admin;

use App\Livewire\Backend\UserList;
use App\Models\Courrier;
use App\Models\OrganizationUnit;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CompteUtilisateurNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Page "Utilisateurs & Accès" reconstruite le 2026-09-21 depuis la maquette
// fournie par l'utilisateur (remplace l'ancienne page "deux boîtes") — voir
// le journal de développement pour le détail des décisions (catalogue de privilèges
// fixe, /admin/profils inchangée, confidentialité numérique hiérarchique).
class UserListTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil, array $attributs = []): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(array_merge(['profil_id' => $profil->id], $attributs));
    }

    // 2026-09-23, demande explicite de l'utilisateur ("we assign them in a
    // department first before service") — un Département RACINE ponté au
    // service réel donné, même patron que CircuitCourrierTest::
    // departementPonte() (Module "Organisation" v2).
    private function departementPonte(Service $service): OrganizationUnit
    {
        return OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'parent_id' => null,
            'service_id' => $service->id,
        ]);
    }

    public function test_un_agent_sans_privilege_ne_peut_pas_gerer_les_utilisateurs(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(UserList::class)->assertForbidden();
    }

    // Même garde-fou anti-verrouillage que l'ancienne page (voir
    // PrivilegePolicy::gerer()).
    public function test_un_administrateur_garde_lacces_meme_sans_le_privilege_explicite(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        Privilege::where('cle', 'privileges.gerer')->firstOrFail()->profils()->detach();

        $this->assertFalse($admin->fresh()->hasPrivilege('privileges.gerer'));

        $this->actingAs($admin);

        Livewire::test(UserList::class)->assertOk();
    }

    // ===== Table =====

    public function test_la_table_liste_les_utilisateurs_et_filtre_par_recherche(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent', ['name' => 'Amina Test']);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->assertSee('Amina Test')
            ->set('recherche', 'Amina')
            ->assertSee('Amina Test')
            ->set('recherche', 'Zzzz-introuvable')
            ->assertDontSee('Amina Test');
    }

    public function test_la_table_filtre_par_departement_profil_statut_et_confidentialite(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $service = Service::factory()->create();
        $departement = $this->departementPonte($service);
        $agent = $this->utilisateurAvecProfil('Agent', ['name' => 'Filtrable', 'service_id' => $service->id, 'actif' => false, 'niveau_confidentialite' => 2]);
        $this->actingAs($admin);

        $component = Livewire::test(UserList::class);

        $component->set('departementFiltreId', $departement->id)->assertSee('Filtrable');
        $component->set('profilFiltreId', Profil::where('nom', 'Agent')->value('id'))->assertSee('Filtrable');
        $component->set('statutFiltre', 'desactive')->assertSee('Filtrable');
        $component->set('confidentialiteFiltreId', 2)->assertSee('Filtrable');

        $component->set('statutFiltre', 'actif')->assertDontSee('Filtrable');
    }

    // 2026-09-23, demande explicite de l'utilisateur ("on the table...
    // change the service to department... since we assign them in a
    // department first before service") — la colonne affiche le Département
    // de l'organigramme, pas le nom brut du service, même quand le pont est
    // sur un nœud PLUS profond que le Département lui-même (ex. un
    // utilisateur rattaché à "Sinistre Santé" doit afficher "Direction des
    // Sinistres", pas "Santé" ni "Sinistre Santé").
    public function test_la_colonne_departement_remonte_jusquau_departement_ancetre(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $serviceSante = Service::factory()->create(['nom' => 'Santé']);
        $direction = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'name' => 'Direction des Sinistres']);
        OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_SERVICE,
            'parent_id' => $direction->id,
            'name' => 'Sinistre Santé',
            'service_id' => $serviceSante->id,
        ]);
        $this->utilisateurAvecProfil('Agent', ['name' => 'Marie Sinistre', 'service_id' => $serviceSante->id]);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->assertSee('Marie Sinistre')
            ->assertSee('Direction des Sinistres')
            ->assertDontSee('Sinistre Santé');
    }

    // 2026-09-23, demande explicite de l'utilisateur ("i added one
    // collaborateur under a department but it doesn't show it on user
    // list") — un rattachement de TRAVAIL direct (organization_unit_user,
    // ajouté depuis l'onglet "Utilisateurs" de la page Organisation) doit
    // apparaître ici même quand users.service_id n'a jamais été renseigné
    // depuis CETTE page — les deux mécanismes comptent, le rattachement
    // direct étant prioritaire.
    public function test_la_colonne_departement_reflete_un_rattachement_fait_depuis_la_page_organisation(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $departementInformatique = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'name' => 'Departement Informatique']);
        $collaborateur = $this->utilisateurAvecProfil('Collaborateur', ['name' => 'Collaborateur DI']);
        $departementInformatique->utilisateurs()->attach($collaborateur->id, ['is_primary' => true]);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->assertSee('Collaborateur DI')
            ->assertSee('Departement Informatique');
    }

    // Le filtre "Département" doit aussi matcher ce rattachement direct, pas
    // seulement le pont service_id.
    public function test_le_filtre_departement_matche_un_rattachement_direct(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $departementInformatique = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'name' => 'Departement Informatique']);
        $collaborateur = $this->utilisateurAvecProfil('Collaborateur', ['name' => 'Collaborateur DI']);
        $departementInformatique->utilisateurs()->attach($collaborateur->id, ['is_primary' => true]);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->set('departementFiltreId', $departementInformatique->id)
            ->assertSee('Collaborateur DI');
    }

    // 2026-09-23, demande explicite de l'utilisateur ("what about those
    // responsable too") — être le RESPONSABLE désigné d'une entité
    // (responsible_user_id, "Définir comme responsable" sur la page
    // Organisation) doit aussi apparaître ici, même sans rattachement de
    // travail ni service_id renseigné.
    public function test_la_colonne_departement_reflete_le_responsable_designe(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $responsable = $this->utilisateurAvecProfil('Responsable de service', ['name' => 'Responsable DI']);
        OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'name' => 'Departement Informatique',
            'responsible_user_id' => $responsable->id,
        ]);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->assertSee('Responsable DI')
            ->assertSee('Departement Informatique');

        Livewire::test(UserList::class)
            ->set('departementFiltreId', OrganizationUnit::where('name', 'Departement Informatique')->value('id'))
            ->assertSee('Responsable DI');
    }

    // Un service réel pas encore représenté dans l'organigramme (la plupart,
    // à ce jour) ne doit jamais apparaître dans la carte service_id →
    // département — la vue affiche alors "—", jamais un nom fabriqué.
    public function test_la_carte_departement_par_service_ignore_un_service_pas_encore_organise(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $serviceOrganise = Service::factory()->create();
        $this->departementPonte($serviceOrganise);
        $serviceNonOrganise = Service::factory()->create();
        $this->actingAs($admin);

        $carte = Livewire::test(UserList::class)->get('departementLabelParServiceId');

        $this->assertArrayHasKey($serviceOrganise->id, $carte);
        $this->assertArrayNotHasKey($serviceNonOrganise->id, $carte);
    }

    public function test_reinitialiser_les_filtres_les_vide_tous(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(UserList::class)
            ->set('recherche', 'x')
            ->set('statutFiltre', 'actif')
            ->call('reinitialiserFiltres')
            ->assertSet('recherche', '')
            ->assertSet('statutFiltre', '');
    }

    // ===== Ajouter un utilisateur =====

    public function test_ajouter_un_utilisateur_le_cree_reellement_et_envoie_un_lien_de_reinitialisation(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $service = Service::factory()->create();
        $departement = $this->departementPonte($service);
        $profil = Profil::firstOrCreate(['nom' => 'Agent']);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirAjout')
            ->set('ajoutNom', 'Nouvel')
            ->set('ajoutPrenom', 'Utilisateur')
            ->set('ajoutEmail', 'nouvel.utilisateur@example.com')
            ->set('ajoutDepartementSelectionneId', $departement->id)
            ->set('ajoutProfilId', $profil->id)
            ->set('ajoutNiveauConfidentialite', 2)
            ->call('ajouter')
            ->assertHasNoErrors();

        $cree = User::where('email', 'nouvel.utilisateur@example.com')->first();
        $this->assertNotNull($cree);
        $this->assertSame('Nouvel Utilisateur', $cree->name);
        $this->assertSame($service->id, $cree->service_id);
        $this->assertSame(2, $cree->niveau_confidentialite);
        $this->assertTrue($cree->actif);

        Notification::assertSentTo($cree, ResetPassword::class);
    }

    // 2026-10-09 — retour utilisateur direct ("WHY IF I CHOSE DEPARTMENT
    // AND SITE AND ORGANISATION WHEN CREATING OR MODIFYING ET USER IT
    // DOESN'T AUTOMATICALLY ADD THEM ON THE ORGANISATIONS HIERACHARY") :
    // la cascade ne résolvait jusqu'ici QUE users.service_id (le pont),
    // jamais le vrai rattachement organization_unit_user lu par l'onglet
    // "Utilisateurs" de la page Organisation.
    public function test_ajouter_un_utilisateur_le_rattache_reellement_au_noeud_organisation(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $service = Service::factory()->create();
        $departement = $this->departementPonte($service);
        $profil = Profil::firstOrCreate(['nom' => 'Agent']);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirAjout')
            ->set('ajoutNom', 'Nouvel')
            ->set('ajoutPrenom', 'Rattache')
            ->set('ajoutEmail', 'nouvel.rattache@example.com')
            ->set('ajoutDepartementSelectionneId', $departement->id)
            ->set('ajoutProfilId', $profil->id)
            ->set('ajoutNiveauConfidentialite', 1)
            ->call('ajouter')
            ->assertHasNoErrors();

        $cree = User::where('email', 'nouvel.rattache@example.com')->firstOrFail();
        $this->assertTrue($cree->organizationUnits()->where('organization_units.id', $departement->id)->wherePivot('is_primary', true)->exists());
    }

    public function test_ajouter_un_utilisateur_valide_les_champs_requis(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        // Département non obligatoire (2026-09-22/23, demande explicite de
        // l'utilisateur) : une réceptionniste à l'accueil n'appartient à
        // aucun département listé — aucune règle de validation sur la
        // cascade, voir test dédié ci-dessous.
        Livewire::test(UserList::class)
            ->call('ouvrirAjout')
            ->call('ajouter')
            ->assertHasErrors(['ajoutNom', 'ajoutPrenom', 'ajoutEmail', 'ajoutProfilId']);
    }

    public function test_ajouter_un_utilisateur_sans_service_fonctionne_pour_une_receptionniste(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $profil = Profil::firstOrCreate(['nom' => 'Agent']);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirAjout')
            ->set('ajoutNom', 'Réceptionniste')
            ->set('ajoutPrenom', 'Accueil')
            ->set('ajoutEmail', 'accueil@example.com')
            ->set('ajoutProfilId', $profil->id)
            ->set('ajoutNiveauConfidentialite', 1)
            ->call('ajouter')
            ->assertHasNoErrors();

        $cree = User::where('email', 'accueil@example.com')->first();
        $this->assertNotNull($cree);
        $this->assertNull($cree->service_id);
    }

    public function test_ajouter_un_utilisateur_avec_une_photo_la_stocke_sur_s3(): void
    {
        Notification::fake();
        Storage::fake('s3');
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $service = Service::factory()->create();
        $departement = $this->departementPonte($service);
        $profil = Profil::firstOrCreate(['nom' => 'Agent']);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirAjout')
            ->set('ajoutNom', 'Avec')
            ->set('ajoutPrenom', 'Photo')
            ->set('ajoutEmail', 'avec.photo@example.com')
            ->set('ajoutDepartementSelectionneId', $departement->id)
            ->set('ajoutProfilId', $profil->id)
            ->set('ajoutPhoto', UploadedFile::fake()->image('avatar.jpg'))
            ->call('ajouter')
            ->assertHasNoErrors();

        $cree = User::where('email', 'avec.photo@example.com')->firstOrFail();
        $this->assertNotNull($cree->photo_path);
        Storage::disk('s3')->assertExists($cree->photo_path);
    }

    // ===== Modifier un utilisateur =====

    public function test_modifier_un_utilisateur_enregistre_reellement_les_changements(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $autreService = Service::factory()->create();
        $autreDepartement = $this->departementPonte($autreService);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $agent->id)
            ->set('editionNom', 'Nom')
            ->set('editionPrenom', 'Modifié')
            ->set('editionTelephone', '+237600000000')
            ->set('editionDepartementSelectionneId', $autreDepartement->id)
            ->set('editionNiveauConfidentialite', 3)
            ->set('editionActif', false)
            ->call('enregistrerEdition')
            ->assertHasNoErrors();

        $apres = $agent->fresh();
        $this->assertSame('Nom Modifié', $apres->name);
        $this->assertSame('+237600000000', $apres->telephone);
        $this->assertSame($autreService->id, $apres->service_id);
        $this->assertSame(3, $apres->niveau_confidentialite);
        $this->assertFalse($apres->actif);
        $this->assertTrue($apres->organizationUnits()->where('organization_units.id', $autreDepartement->id)->wherePivot('is_primary', true)->exists());
        // 2026-10-09 (audit "list me all") — actif a changé ICI, priorité
        // sur "modifie" (voir UserList::enregistrerEdition()).
        Notification::assertSentTo($agent, CompteUtilisateurNotification::class, fn ($n) => $n->action === 'desactive' && $n->envoyePar?->id === $admin->id);
    }

    // 2026-10-09 (audit "list me all") — niveau de confidentialité changé,
    // actif INCHANGÉ : branche "modifie", pas "desactive".
    public function test_modifier_le_niveau_de_confidentialite_notifie_modifie(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent', ['niveau_confidentialite' => 1]);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $agent->id)
            ->set('editionNiveauConfidentialite', 2)
            ->call('enregistrerEdition')
            ->assertHasNoErrors();

        Notification::assertSentTo($agent, CompteUtilisateurNotification::class, fn ($n) => $n->action === 'modifie' && $n->envoyePar?->id === $admin->id);
    }

    // Une correction qui ne touche ni actif/profil/niveau/cascade ne doit
    // jamais notifier "votre compte a été modifié" pour rien.
    public function test_modifier_seulement_le_telephone_ne_notifie_personne(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $agent->id)
            ->set('editionTelephone', '+237699999999')
            ->call('enregistrerEdition')
            ->assertHasNoErrors();

        Notification::assertNothingSentTo($agent);
    }

    public function test_modifier_le_departement_remplace_lancien_rattachement_principal(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $serviceInitial = Service::factory()->create();
        $departementInitial = $this->departementPonte($serviceInitial);
        $agent = $this->utilisateurAvecProfil('Agent', ['service_id' => $serviceInitial->id]);
        $agent->organizationUnits()->attach($departementInitial->id, ['is_primary' => true]);

        $serviceSuivant = Service::factory()->create();
        $departementSuivant = $this->departementPonte($serviceSuivant);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $agent->id)
            ->set('editionDepartementSelectionneId', $departementSuivant->id)
            ->call('enregistrerEdition')
            ->assertHasNoErrors();

        $apres = $agent->fresh();
        $this->assertFalse($apres->organizationUnits()->where('organization_units.id', $departementInitial->id)->exists(), 'ancien rattachement retiré');
        $this->assertTrue($apres->organizationUnits()->where('organization_units.id', $departementSuivant->id)->wherePivot('is_primary', true)->exists());
    }

    // Même garde que service_id (voir test_modifier_un_utilisateur_pour_retirer_son_service_fonctionne
    // et le commentaire de enregistrerEdition()) : une cascade jamais
    // touchée ne doit RIEN changer au rattachement organisationnel
    // existant, même si un autre champ (ici le téléphone) est modifié.
    public function test_modifier_un_champ_sans_toucher_la_cascade_preserve_le_rattachement(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $serviceInitial = Service::factory()->create();
        $departementInitial = $this->departementPonte($serviceInitial);
        $agent = $this->utilisateurAvecProfil('Agent', ['service_id' => $serviceInitial->id]);
        $agent->organizationUnits()->attach($departementInitial->id, ['is_primary' => true]);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $agent->id)
            ->set('editionTelephone', '+237611111111')
            ->call('enregistrerEdition')
            ->assertHasNoErrors();

        $apres = $agent->fresh();
        $this->assertSame('+237611111111', $apres->telephone);
        $this->assertTrue($apres->organizationUnits()->where('organization_units.id', $departementInitial->id)->wherePivot('is_primary', true)->exists());
    }

    public function test_modifier_un_utilisateur_pour_retirer_son_service_fonctionne(): void
    {
        // Même raison que la création (2026-09-22, demande explicite de
        // l'utilisateur) : une réceptionniste existante peut être repassée
        // sans service (accueil), pas seulement à la création.
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $serviceInitial = Service::factory()->create();
        $departementInitial = $this->departementPonte($serviceInitial);
        $agent = $this->utilisateurAvecProfil('Agent', ['service_id' => $serviceInitial->id]);
        $agent->organizationUnits()->attach($departementInitial->id, ['is_primary' => true]);
        $this->actingAs($admin);

        $composant = Livewire::test(UserList::class)->call('ouvrirEdition', $agent->id);

        // La cascade doit être préremplie depuis le service déjà enregistré
        // (même patron que ShowCourrier::preselectionnerCascadeDepuisService()).
        $composant->assertSet('editionDepartementSelectionneId', $departementInitial->id);

        $composant
            ->set('editionDepartementSelectionneId', null)
            ->call('enregistrerEdition')
            ->assertHasNoErrors();

        $apres = $agent->fresh();
        $this->assertNull($apres->service_id);
        // 2026-10-09 — cascade explicitement vidée : le rattachement
        // organization_unit_user périmé est retiré, pas laissé derrière.
        $this->assertFalse($apres->organizationUnits()->where('organization_units.id', $departementInitial->id)->exists());
    }

    // "Nom"/"Prénom" de la maquette restent deux CHAMPS distincts à
    // l'écran mais une seule colonne `name` en base (décision explicite,
    // "Cosmetic only — two boxes, one column") — split naïf sur le
    // premier espace à l'ouverture, recombiné à l'enregistrement.
    public function test_nom_et_prenom_sont_scindes_a_laffichage_et_recombines_sans_perte(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent', ['name' => 'Jean Paul Kamga', 'service_id' => Service::factory()->create()->id]);
        $this->actingAs($admin);

        $component = Livewire::test(UserList::class)->call('ouvrirEdition', $agent->id);

        $component->assertSet('editionNom', 'Jean')->assertSet('editionPrenom', 'Paul Kamga');

        $component->call('enregistrerEdition')->assertHasNoErrors();

        $this->assertSame('Jean Paul Kamga', $agent->fresh()->name);
    }

    public function test_basculer_actif_active_et_desactive_un_compte(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($admin);

        $component = Livewire::test(UserList::class);

        $component->call('basculerActif', $agent->id);
        $this->assertFalse($agent->fresh()->actif);
        // 2026-10-09 (audit "list me all") — un compte désactivé ne
        // comprendrait sinon jamais pourquoi il ne peut plus se connecter.
        Notification::assertSentTo($agent, CompteUtilisateurNotification::class, fn ($n) => $n->action === 'desactive' && $n->envoyePar?->id === $admin->id);

        $component->call('basculerActif', $agent->id);
        $this->assertTrue($agent->fresh()->actif);
        Notification::assertSentTo($agent, CompteUtilisateurNotification::class, fn ($n) => $n->action === 'active' && $n->envoyePar?->id === $admin->id);
    }

    // Un compte désactivé ne peut réellement plus se connecter (Règle n°6 —
    // "Activer le compte" doit avoir un effet réel, voir
    // FortifyServiceProvider::authenticateUsing()).
    public function test_un_compte_desactive_ne_peut_plus_se_connecter(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent', ['actif' => false]);

        $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'password'])
            ->assertSessionHasErrorsIn('email');

        $this->assertGuest();
    }

    public function test_reinitialiser_le_mot_de_passe_envoie_un_lien(): void
    {
        Notification::fake();
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($admin);

        Livewire::test(UserList::class)->call('reinitialiserMotDePasse', $agent->id);

        Notification::assertSentTo($agent, ResetPassword::class);
    }

    // ===== Gestion des permissions =====

    // La preuve la plus importante du système : un utilisateur SANS le bon
    // profil, mais avec la permission cochée individuellement dans la
    // modale, passe quand même un vrai Policy check.
    public function test_basculer_une_permission_donne_reellement_le_droit(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $collaborateur = $this->utilisateurAvecProfil('Collaborateur');
        $this->actingAs($admin);

        $this->assertFalse($collaborateur->hasPrivilege('courriers.creer'));

        $privilege = Privilege::where('cle', 'courriers.creer')->firstOrFail();

        Livewire::test(UserList::class)
            ->call('ouvrirPermissions', $collaborateur->id)
            ->call('basculerPermission', $privilege->id);

        $collaborateurApres = User::find($collaborateur->id);
        $this->assertTrue($collaborateurApres->hasPrivilege('courriers.creer'));
        $this->assertTrue($collaborateurApres->can('create', Courrier::class));
    }

    public function test_basculer_une_permission_deja_assignee_la_retire(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($admin);

        $privilege = Privilege::where('cle', 'courriers.voir_dga')->firstOrFail();
        $privilege->users()->attach($agent->id);

        Livewire::test(UserList::class)
            ->call('ouvrirPermissions', $agent->id)
            ->call('basculerPermission', $privilege->id);

        $this->assertFalse(User::find($agent->id)->hasPrivilege('courriers.voir_dga'));
    }

    // Modules réels dérivés de `cle` (voir Privilege::MODULES) — pas les
    // modules/compteurs fictifs de la maquette GPT.
    public function test_les_modules_de_la_modale_permissions_sont_les_modules_reels(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        $component = Livewire::test(UserList::class)->call('ouvrirPermissions', $this->utilisateurAvecProfil('Agent')->id);

        $cles = $component->get('modules')->pluck('cle');

        $this->assertTrue($cles->contains('courriers'));
        $this->assertTrue($cles->contains('privileges'));
        $this->assertSame(Privilege::where('cle', 'like', 'courriers.%')->count(), $component->get('modules')->firstWhere('cle', 'courriers')['total']);
    }

    public function test_la_recherche_de_permission_filtre_le_module_selectionne(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        $component = Livewire::test(UserList::class)
            ->call('ouvrirPermissions', $this->utilisateurAvecProfil('Agent')->id)
            ->set('modulePermissionSelectionne', 'courriers')
            ->set('recherchePermission', 'DGA');

        $noms = $component->get('permissionsDuModule')->pluck('nom');

        $this->assertTrue($noms->contains('Voir les courriers en attente de validation DGA'));
        $this->assertFalse($noms->contains('Créer un courrier'));
    }

    public function test_tout_selectionner_et_deselectionner_le_module_affecte_uniquement_ce_module(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirPermissions', $agent->id)
            ->set('modulePermissionSelectionne', 'privileges')
            ->call('toutSelectionnerModule');

        $agentApres = User::find($agent->id);
        $this->assertTrue($agentApres->privilegesDirectes->pluck('cle')->contains('privileges.gerer'));

        Livewire::test(UserList::class)
            ->call('ouvrirPermissions', $agent->id)
            ->set('modulePermissionSelectionne', 'privileges')
            ->call('toutDeselectionnerModule');

        $this->assertFalse(User::find($agent->id)->privilegesDirectes->pluck('cle')->contains('privileges.gerer'));
    }

    // Résumé — comptes RÉELS (profil ∪ individuel), pas les chiffres
    // inventés de la maquette.
    public function test_le_resume_des_permissions_reflete_leffectif_reel(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $this->actingAs($admin);

        // Administrateur a TOUS les privilèges (voir PrivilegeSeeder).
        $component = Livewire::test(UserList::class)->call('ouvrirPermissions', $admin->id);

        $resume = $component->get('resumePermissions');

        $this->assertSame(Privilege::count(), $resume['lecture'] + $resume['ecriture'] + $resume['administratif']);
        $this->assertSame($component->get('modules')->count(), $resume['modulesActifs']);
    }

    // ===== Destinataires de transfert (relocalisé depuis l'ancienne page,
    // voir DECISIONS.md "Destinataires de transfert") =====

    public function test_ajouter_puis_retirer_un_destinataire_autorise(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $dga = $this->utilisateurAvecProfil('DGA');
        $this->actingAs($admin);

        $component = Livewire::test(UserList::class)->call('ouvrirEdition', $agent->id);

        $component->call('ajouterDestinataire', $dga->id);
        $this->assertTrue(User::find($agent->id)->destinatairesTransfert->contains('id', $dga->id));

        $component->call('retirerDestinataire', $dga->id);
        $this->assertFalse(User::find($agent->id)->destinatairesTransfert->contains('id', $dga->id));
    }

    public function test_la_fleche_centrale_ajoute_et_retire_toute_la_selection_de_destinataires(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $dga = $this->utilisateurAvecProfil('DGA');
        $responsable = $this->utilisateurAvecProfil('Responsable de service');
        $this->actingAs($admin);

        $component = Livewire::test(UserList::class)
            ->call('ouvrirEdition', $agent->id)
            ->set('selectionDestinatairesDisponibles', [$dga->id, $responsable->id])
            ->call('ajouterSelectionDestinataires')
            ->assertSet('selectionDestinatairesDisponibles', []);

        $agentApres = User::find($agent->id);
        $this->assertTrue($agentApres->destinatairesTransfert->contains('id', $dga->id));
        $this->assertTrue($agentApres->destinatairesTransfert->contains('id', $responsable->id));

        $component->set('selectionDestinatairesAssignes', [$dga->id, $responsable->id])
            ->call('retirerSelectionDestinataires')
            ->assertSet('selectionDestinatairesAssignes', []);

        $agentApres = User::find($agent->id);
        $this->assertFalse($agentApres->destinatairesTransfert->contains('id', $dga->id));
        $this->assertFalse($agentApres->destinatairesTransfert->contains('id', $responsable->id));
    }

    public function test_lutilisateur_en_edition_napparait_pas_dans_ses_propres_destinataires_disponibles(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($admin);

        $component = Livewire::test(UserList::class)->call('ouvrirEdition', $agent->id);

        $disponibles = $component->get('destinatairesDisponibles')->pluck('id');

        $this->assertNotContains($agent->id, $disponibles);

        // Règle n°6 — même si l'id posté est le sien, l'action reste un no-op.
        $component->call('ajouterDestinataire', $agent->id);
        $this->assertFalse(User::find($agent->id)->destinatairesTransfert->contains('id', $agent->id));
    }

    // 2026-10-07 — même patron "deux boîtes" que les destinataires
    // personne, sur Service (voir DECISIONS.md "Destinataires de
    // transfert — services").
    public function test_ajouter_puis_retirer_un_service_destinataire(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['nom' => 'Ressources Humaines']);
        $this->actingAs($admin);

        $component = Livewire::test(UserList::class)->call('ouvrirEdition', $agent->id);

        $component->call('ajouterServiceDestinataire', $service->id);
        $this->assertTrue(User::find($agent->id)->destinatairesTransfertServices->contains('id', $service->id));

        $component->call('retirerServiceDestinataire', $service->id);
        $this->assertFalse(User::find($agent->id)->destinatairesTransfertServices->contains('id', $service->id));
    }

    public function test_la_fleche_centrale_ajoute_et_retire_toute_la_selection_de_services(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $agent = $this->utilisateurAvecProfil('Agent');
        $rh = Service::factory()->create(['nom' => 'Ressources Humaines']);
        $dg = Service::factory()->create(['nom' => 'Direction Générale']);
        $this->actingAs($admin);

        $component = Livewire::test(UserList::class)
            ->call('ouvrirEdition', $agent->id)
            ->set('selectionServicesDisponibles', [$rh->id, $dg->id])
            ->call('ajouterSelectionServices')
            ->assertSet('selectionServicesDisponibles', []);

        $agentApres = User::find($agent->id);
        $this->assertTrue($agentApres->destinatairesTransfertServices->contains('id', $rh->id));
        $this->assertTrue($agentApres->destinatairesTransfertServices->contains('id', $dg->id));

        $component->set('selectionServicesAssignes', [$rh->id, $dg->id])
            ->call('retirerSelectionServices')
            ->assertSet('selectionServicesAssignes', []);

        $agentApres = User::find($agent->id);
        $this->assertFalse($agentApres->destinatairesTransfertServices->contains('id', $rh->id));
        $this->assertFalse($agentApres->destinatairesTransfertServices->contains('id', $dg->id));
    }

    // 2026-09-24 — lien "Gérer cet utilisateur" de la page Organisation :
    // ouvre directement la modale "Modifier" de ce compte, cascade
    // pré-remplie depuis son service réel.
    public function test_le_parametre_modifier_ouvre_directement_la_fiche_du_compte(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $service = Service::factory()->create(['code' => 'DIS']);
        $departement = $this->departementPonte($service);
        $collaborateur = $this->utilisateurAvecProfil('Collaborateur', ['service_id' => $service->id]);
        $this->actingAs($admin);

        Livewire::withQueryParams(['modifier' => $collaborateur->id])
            ->test(UserList::class)
            ->assertSet('utilisateurEditionId', $collaborateur->id)
            ->assertSet('editionDepartementSelectionneId', $departement->id);
    }

    // 2026-09-24 ("make the all modify modal to show the correct
    // informations") — la modale affiche la même position que la colonne
    // "Département" de la table, pas seulement le pont service_id.
    public function test_la_modale_preremplit_lentite_dont_lutilisateur_est_responsable(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $responsable = $this->utilisateurAvecProfil('Responsable de service');
        $serviceSante = Service::factory()->create();
        $direction = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => null]);
        $sante = OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_SERVICE,
            'parent_id' => $direction->id,
            'service_id' => $serviceSante->id,
            'responsible_user_id' => $responsable->id,
        ]);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $responsable->id)
            ->assertSet('editionDepartementSelectionneId', $direction->id)
            ->assertSet('editionUniteSelectionneeId', $sante->id)
            ->call('enregistrerEdition')
            ->assertHasNoErrors();

        // Ce qui est affiché est ce qui est enregistré.
        $this->assertSame($serviceSante->id, $responsable->fresh()->service_id);
    }

    public function test_la_modale_preremplit_le_rattachement_fait_depuis_lorganisation(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $service = Service::factory()->create();
        $departement = $this->departementPonte($service);
        $collaborateur = $this->utilisateurAvecProfil('Collaborateur');
        $departement->utilisateurs()->attach($collaborateur->id, ['is_primary' => true]);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $collaborateur->id)
            ->assertSet('editionDepartementSelectionneId', $departement->id)
            ->call('enregistrerEdition')
            ->assertHasNoErrors();

        $this->assertSame($service->id, $collaborateur->fresh()->service_id);
    }

    public function test_modifier_le_telephone_conserve_un_service_hors_organigramme(): void
    {
        // Bug réel (2026-09-24) : un service pas encore dans l'organigramme
        // (agent "AC", DGA "SDG") était effacé au premier enregistrement.
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $serviceHorsOrganigramme = Service::factory()->create(['nom' => 'Accueil', 'code' => 'AC']);
        $agent = $this->utilisateurAvecProfil('Agent', ['service_id' => $serviceHorsOrganigramme->id]);
        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->call('ouvrirEdition', $agent->id)
            ->assertSee('Service actuel : Accueil (AC)')
            ->set('editionTelephone', '+225 01 02 03 04')
            ->call('enregistrerEdition')
            ->assertHasNoErrors();

        $this->assertSame($serviceHorsOrganigramme->id, $agent->fresh()->service_id);
    }

    public function test_le_parametre_modifier_ne_contourne_pas_les_droits_sur_le_compte(): void
    {
        // Sans privileges.gerer, un compte Administrateur reste intouchable
        // (voir UserList::peutGererCompte()), même via l'URL.
        $gestionnaire = $this->utilisateurAvecProfil('Agent');
        $gestionnaire->privilegesDirectes()->attach(Privilege::where('cle', 'utilisateurs.gerer')->firstOrFail());
        $cible = $this->utilisateurAvecProfil('Administrateur');
        $this->actingAs($gestionnaire->fresh());

        Livewire::withQueryParams(['modifier' => $cible->id])
            ->test(UserList::class)
            ->assertForbidden();
    }
}
