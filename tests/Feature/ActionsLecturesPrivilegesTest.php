<?php

namespace Tests\Feature;

use App\Livewire\Backend\CourrierList;
use App\Livewire\Backend\DossierClassementList;
use App\Livewire\Backend\ProfilList;
use App\Livewire\Backend\RegleList;
use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\DossierClassement;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// "Chaque action / lecture = un privilège" (2026-09-23, voir DECISIONS.md) :
// chaque privilège ajouté s'accorde par défaut aux profils qui pouvaient
// déjà agir (comportement inchangé) ; le retirer du profil retire l'action
// ou la lecture correspondante, côté serveur comme dans la vue.
class ActionsLecturesPrivilegesTest extends TestCase
{
    use RefreshDatabase;

    private function profil(string $nom): Profil
    {
        return Profil::where('nom', $nom)->firstOrFail();
    }

    private function utilisateur(string $profil): User
    {
        return User::factory()->create(['profil_id' => $this->profil($profil)->id]);
    }

    private function retirer(string $cle, string $profil): void
    {
        Privilege::where('cle', $cle)->firstOrFail()->profils()->detach($this->profil($profil)->id);
    }

    private function courrier(array $attributs = [], ?User $createur = null): Courrier
    {
        $courrier = Courrier::create(array_merge([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id,
        ], $attributs));

        if ($createur) {
            CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $createur->id, 'action' => 'creation']);
        }

        return $courrier;
    }

    // ===== Circuit : rejeter (courriers.rejeter) =====

    private function courrierEnValidationPour(User $responsable): Courrier
    {
        $service = Service::factory()->create(['responsable_id' => $responsable->id]);

        return $this->courrier(['service_id' => $service->id, 'statut' => 'en_validation']);
    }

    public function test_rejeter_avec_puis_sans_le_privilege(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $courrier = $this->courrierEnValidationPour($responsable);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('motifRejet', 'Hors périmètre de la compagnie')
            ->call('rejeter');
        $this->assertSame('rejete', $courrier->fresh()->statut);

        $autre = $this->courrierEnValidationPour($responsable);
        $this->retirer('courriers.rejeter', 'Responsable de service');
        $this->actingAs(User::find($responsable->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $autre->id])
            ->assertDontSee('Rejeter ce courrier')
            ->set('motifRejet', 'Hors périmètre de la compagnie')
            ->call('rejeter')
            ->assertForbidden();
        $this->assertSame('en_validation', $autre->fresh()->statut);
    }

    // Le privilège d'action ne donne jamais la portée : un responsable ne
    // rejette pas un courrier d'un autre service.
    public function test_le_privilege_daction_ne_remplace_pas_la_portee(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $courrierAutreService = $this->courrier(['statut' => 'en_validation']);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrierAutreService->id])->assertForbidden();
    }

    // ===== Lectures de la fiche =====

    public function test_historique_et_texte_ocr_masques_sans_leur_privilege(): void
    {
        $agent = $this->utilisateur('Agent');
        $courrier = $this->courrier(['texte_ocr' => 'CONTENU-OCR-SECRET'], $agent);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee("onglet = 'historique'", false)
            ->assertSee('CONTENU-OCR-SECRET');

        $this->retirer('courriers.voir_historique', 'Agent');
        $this->retirer('courriers.voir_texte_ocr', 'Agent');
        $this->actingAs(User::find($agent->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertDontSee("onglet = 'historique'", false)
            ->assertDontSee('CONTENU-OCR-SECRET');
    }

    // 2026-10-12, demande explicite de l'utilisateur ("not everybody has to
    // see this ... if you don't have the permission the card stop at
    // detail complementaire") — tout le bloc OCR/Classement/mots-clés
    // disparaît sans ce privilège, "Détails complémentaires" reste visible.
    public function test_bloc_ocr_classement_masque_sans_voir_traitement_document(): void
    {
        $agent = $this->utilisateur('Agent');
        $courrier = $this->courrier([], $agent);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Document principal (numérisation)')
            ->assertSee('Détails complémentaires');

        $this->retirer('courriers.voir_traitement_document', 'Agent');
        $this->actingAs(User::find($agent->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertDontSee('Document principal (numérisation)')
            ->assertSee('Détails complémentaires');
    }

    // 2026-10-08 — "Répondre"/"Créer une note" (Actions rapides) étaient
    // les seuls boutons de ShowCourrier sans aucun privilège (retour
    // utilisateur direct, "should be previledges too").
    public function test_repondre_et_creer_note_masques_sans_leur_privilege(): void
    {
        $agent = $this->utilisateur('Agent');
        $courrier = $this->courrier([], $agent);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Répondre')
            ->assertSee('Créer une note');

        $this->retirer('courriers.repondre', 'Agent');
        $this->retirer('courriers.creer_note', 'Agent');
        $this->actingAs(User::find($agent->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertDontSee('Répondre')
            ->assertDontSee('Créer une note');
    }

    // 2026-10-08 — "Importer"/"Exporter" (en-tête de "Tous les courriers")
    // étaient aussi sans aucun privilège (même retour utilisateur direct,
    // capture d'écran annotée "priveliges").
    public function test_importer_et_exporter_masques_sans_leur_privilege(): void
    {
        $agent = $this->utilisateur('Agent');
        $this->actingAs($agent);

        Livewire::test(CourrierList::class)
            ->assertSee('Importer')
            ->assertSee('Exporter');

        $this->retirer('courriers.importer', 'Agent');
        $this->retirer('courriers.exporter', 'Agent');
        $this->actingAs(User::find($agent->id));

        Livewire::test(CourrierList::class)
            ->assertDontSee('Importer')
            ->assertDontSee('Exporter');
    }

    public function test_la_recherche_dans_le_contenu_est_ignoree_sans_voir_texte_ocr(): void
    {
        $agent = $this->utilisateur('Agent');
        $trouve = $this->courrier(['texte_ocr' => 'mot capital ici', 'objet' => 'AVEC-MOT'], $agent);
        $this->courrier(['texte_ocr' => 'rien', 'objet' => 'SANS-MOT'], $agent);

        $this->retirer('courriers.voir_texte_ocr', 'Agent');
        $this->actingAs(User::find($agent->id));

        // Le filtre posté est ignoré : les deux courriers restent listés,
        // sans colonne d'extrait qui révélerait le contenu.
        Livewire::test(CourrierList::class)
            ->set('contenu', 'capital')
            ->assertSee('AVEC-MOT')
            ->assertSee('SANS-MOT')
            ->assertDontSee('Extrait du document');
    }

    // ===== Classement =====

    public function test_classer_et_mots_cles_exigent_leur_privilege(): void
    {
        $agent = $this->utilisateur('Agent');
        $courrier = $this->courrier([], $agent);
        $dossier = DossierClassement::create(['nom' => 'Mon dossier', 'cree_par_id' => $agent->id]);

        $this->retirer('courriers.classer', 'Agent');
        $this->retirer('courriers.gerer_mots_cles', 'Agent');
        $this->actingAs(User::find($agent->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('dossierAClasserId', $dossier->id)
            ->call('classerDansDossier')
            ->assertForbidden();

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('nouveauMotCle', 'urgent')
            ->call('ajouterMotCle')
            ->assertForbidden();

        $this->assertNull($courrier->fresh()->dossier_classement_id);
        $this->assertSame(0, $courrier->motsCles()->count());
    }

    // ===== Pages de lecture =====

    public function test_pages_mes_courriers_et_courriers_enregistres(): void
    {
        $agent = $this->utilisateur('Agent');
        $this->actingAs($agent);
        $this->get(route('courriers.mes-courriers'))->assertOk();
        $this->get(route('courriers.enregistres'))->assertOk();

        $this->retirer('courriers.voir_mes_courriers', 'Agent');
        $this->retirer('courriers.voir_enregistres', 'Agent');
        $this->actingAs(User::find($agent->id));
        $this->get(route('courriers.mes-courriers'))->assertForbidden();
        $this->get(route('courriers.enregistres'))->assertForbidden();
    }

    public function test_blocs_du_tableau_de_bord(): void
    {
        $agent = $this->utilisateur('Agent');
        $this->actingAs($agent);
        $this->get(route('dashboard'))->assertSee('Derniers courriers enregistrés')->assertSee('Courrier entrant');

        // dashboard.statistiques a été scindée en une clé par carte le
        // 2026-09-23 (voir MenuPrivilegesTest pour un test dédié par carte).
        $this->retirer('dashboard.derniers_courriers', 'Agent');
        $this->retirer('dashboard.courrier_entrant', 'Agent');
        $this->actingAs(User::find($agent->id));
        $this->get(route('dashboard'))->assertOk()
            ->assertDontSee('Derniers courriers enregistrés')
            ->assertDontSee('Courrier entrant');
    }

    // ===== Règles de classement : voir / gérer / réanalyser =====

    public function test_regles_consultation_seule(): void
    {
        $lecteur = User::factory()->create(['profil_id' => null]);
        $lecteur->privilegesDirectes()->attach(Privilege::where('cle', 'regles_classement.voir')->value('id'));
        $this->actingAs(User::find($lecteur->id));

        $this->get(route('admin.regles'))->assertOk()->assertDontSee('Créer la règle');
        Livewire::test(RegleList::class)->call('nouvelle')->assertForbidden();
        Livewire::test(RegleList::class)->call('reanalyser')->assertForbidden();
    }

    // ===== Dossiers : partager / supprimer ses dossiers =====

    public function test_partager_et_supprimer_ses_dossiers_exigent_leur_privilege(): void
    {
        $collaborateur = $this->utilisateur('Collaborateur');
        $dossier = DossierClassement::create(['nom' => 'Perso', 'cree_par_id' => $collaborateur->id]);
        $this->assertTrue($collaborateur->can('partager', $dossier));

        $this->retirer('dossiers_classement.partager', 'Collaborateur');
        $this->retirer('dossiers_classement.supprimer', 'Collaborateur');
        $collaborateur = User::find($collaborateur->id);
        $this->actingAs($collaborateur);

        $this->assertFalse($collaborateur->can('partager', $dossier));
        $this->assertFalse($collaborateur->can('delete', $dossier));
        $this->assertTrue($collaborateur->can('update', $dossier), 'renommer reste possible (dossiers_classement.modifier)');
        Livewire::test(DossierClassementList::class)->call('ouvrirSuppression', $dossier->id)->assertForbidden();
        $this->assertModelExists($dossier);
    }

    // ===== Profils : créer =====

    public function test_creer_un_profil_exige_profils_creer(): void
    {
        $gestionnaire = User::factory()->create(['profil_id' => null]);
        $gestionnaire->privilegesDirectes()->attach(Privilege::where('cle', 'privileges.gerer')->value('id'));
        $this->actingAs(User::find($gestionnaire->id));

        Livewire::test(ProfilList::class)
            ->set('nouveauProfilNom', 'Archiviste')
            ->call('creerProfil')
            ->assertForbidden();
        $this->assertDatabaseMissing('profils', ['nom' => 'Archiviste']);
    }
}
