<?php

namespace Tests\Feature\Courriers;

use App\Jobs\IndexCourrierJob;
use App\Livewire\Backend\EditForm;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\PieceJointe;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EditFormTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(['profil_id' => $profil->id]);
    }

    private function courrierEnregistrePar(User $auteur, array $attributs = []): Courrier
    {
        $courrier = Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Objet initial',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'priorite' => 'normale',
            'confidentialite' => 1,
            'service_id' => Service::factory()->create(['code' => 'TST'])->id,
        ], $attributs));

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => $auteur->id,
            'action' => 'creation',
            'commentaire' => null,
        ]);

        return $courrier;
    }

    // 2026-09-24 — retour réel de l'utilisateur (capture d'écran) :
    // "Aperçu du courrier" affichait le CODE JS DU PANNEAU comme texte
    // visible sur la page. Cause trouvée : un commentaire à l'intérieur de
    // l'attribut x-data="{ ... }" (délimité par des guillemets DROITS)
    // contenait lui-même un guillemet droit — pour le navigateur, ce
    // guillemet referme prématurément l'attribut HTML, et tout le reste du
    // JS (jusqu'au prochain guillemet droit trouvé par accident) fuit comme
    // texte de page. Règle : AUCUN guillemet droit " dans un commentaire ou
    // une chaîne à l'intérieur d'un x-data="..." — guillemets français «»
    // uniquement (voir notes internes). Ce test extrait le
    // bloc x-data du panneau d'aperçu et vérifie qu'il n'en contient aucun.
    public function test_lattribut_xdata_du_panneau_apercu_ne_contient_aucun_guillemet_droit(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $courrier = $this->courrierEnregistrePar($admin, ['fichier_path' => 'courriers/2026/TST/GEC-TEST.pdf']);
        $this->actingAs($admin);

        $html = Livewire::test(EditForm::class, ['courrierId' => $courrier->id])->html();

        $ancrage = strpos($html, 'activerDeplacement');
        $this->assertNotFalse($ancrage, 'Le panneau "Document principal" ne s\'est pas affiché.');

        $debut = strrpos(substr($html, 0, $ancrage), 'x-data="{');
        $finAttribut = strpos($html, '}"', $ancrage);
        $bloc = substr($html, $debut + strlen('x-data="'), $finAttribut - ($debut + strlen('x-data="')) + 1);

        $this->assertStringNotContainsString('"', $bloc, 'Un guillemet droit dans le bloc x-data casse l\'attribut HTML.');
        $this->assertSame(1, substr_count($html, 'Voir en plein écran'), 'Le bouton de la modale agrandie ne s\'est pas rendu correctement.');
    }

    // Module 1 — "Type de document" en liste déroulante (demande explicite
    // de l'utilisateur, 2026-09-08). Un courrier existant peut avoir une
    // valeur hors de cette liste (enregistré avant ce changement, ou classé
    // automatiquement via une règle texte libre — Module 3) : elle doit
    // rester visible et modifiable, jamais perdue silencieusement.

    public function test_un_type_de_document_hors_liste_reste_visible_en_champ_libre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['type_document' => 'Attestation de non-gage']);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->assertSet('typeDocumentPersonnalise', true)
            ->assertSet('form.type_document', 'Attestation de non-gage');
    }

    public function test_les_coordonnees_de_lexpediteur_sont_chargees_a_lecran_de_modification(): void
    {
        // Bug réel trouvé le 2026-09-17 en séparant expediteur_coordonnees en
        // téléphone/email/adresse (voir le journal de développement) : mount() ne
        // chargeait déjà pas expediteur_rc/expediteur_niu dans le formulaire
        // (valeurs enregistrées mais jamais réaffichées à l'écran de
        // modification) — corrigé au passage pour les 5 champs de ce même
        // bloc, plutôt que de répéter l'oubli pour les 3 nouveaux champs.
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, [
            'expediteur_telephone' => '(00237)658239075',
            'expediteur_email' => 'contact@itsc-sarl.cm',
            'expediteur_adresse' => 'BP 2138 Yaoundé Cameroun',
            'expediteur_rc' => 'RC/YAO/2019/B/433',
            'expediteur_niu' => 'M051912784615T',
        ]);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->assertSet('form.expediteur_telephone', '(00237)658239075')
            ->assertSet('form.expediteur_email', 'contact@itsc-sarl.cm')
            ->assertSet('form.expediteur_adresse', 'BP 2138 Yaoundé Cameroun')
            ->assertSet('form.expediteur_rc', 'RC/YAO/2019/B/433')
            ->assertSet('form.expediteur_niu', 'M051912784615T');
    }

    public function test_un_type_de_document_de_la_liste_naffiche_pas_le_champ_libre(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['type_document' => 'Lettre']);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->assertSet('typeDocumentPersonnalise', false);
    }

    public function test_lagent_createur_peut_modifier_son_courrier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->set('form.objet', 'Objet corrigé')
            ->set('form.priorite', 'urgente')
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        $courrier->refresh();

        $this->assertSame('Objet corrigé', $courrier->objet);
        $this->assertSame('urgente', $courrier->priorite);
        $this->assertSame('GEC-'.now()->year.'-TST-000001', $courrier->numero_reference);

        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)
            ->where('action', 'modification')
            ->where('auteur_id', $agent->id)
            ->count());
    }

    public function test_modifier_lobjet_relance_le_classement_sur_la_queue_indexation(): void
    {
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->set('form.objet', 'Objet corrigé')
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        Queue::assertPushedOn('indexation', IndexCourrierJob::class, fn (IndexCourrierJob $job) => $job->courrier->id === $courrier->id);
    }

    public function test_modifier_un_champ_hors_objet_expediteur_ne_relance_pas_le_classement(): void
    {
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->set('form.priorite', 'urgente')
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        Queue::assertNotPushed(IndexCourrierJob::class);
    }

    public function test_un_administrateur_peut_modifier_nimporte_quel_courrier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $admin = $this->utilisateurAvecProfil('Administrateur');
        $this->actingAs($admin);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->set('form.objet', 'Objet corrigé par admin')
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        $this->assertSame('Objet corrigé par admin', $courrier->refresh()->objet);
    }

    public function test_un_agent_ne_peut_pas_modifier_le_courrier_dun_autre(): void
    {
        $createur = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($createur);

        $autreAgent = User::factory()->create(['profil_id' => $createur->profil_id]);
        $this->actingAs($autreAgent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->assertForbidden();
    }

    // PENTEST (2026-09-24, "have go throught the view/edit too") —
    // courrier() (#[Computed], alimente le panneau "Aperçu du courrier" :
    // statut, expéditeur, niveau de confidentialité, pièces jointes,
    // numéro — voir editForm.blade.php) rechargeait le courrier depuis
    // $this->courrierId SANS jamais revérifier l'autorisation —
    // contrairement à ShowCourrier::courrier(), qui revérifie 'view' à
    // CHAQUE accès. $courrierId est une propriété publique SANS #[Locked] :
    // Livewire permet à n'importe quelle requête cliente de la modifier
    // directement, même sans wire:model dans le DOM (voir
    // Livewire\Features\SupportLockedProperties\BaseLocked — cet attribut
    // n'existe QUE pour bloquer précisément ce cas, et n'était pas posé
    // ici). mount() n'autorise qu'UNE FOIS, au chargement initial : un
    // utilisateur ayant légitimement accès à la page de modification d'UN
    // courrier pouvait ensuite reprogrammer courrierId vers un AUTRE
    // courrier hors de son périmètre et voir son détail dans le panneau
    // "Aperçu" (note : PAS l'objet — affiché depuis $this->form->objet,
    // rempli une seule fois au mount() pour le courrier d'ORIGINE, donc
    // insensible à ce changement d'id ; en revanche expéditeur, statut,
    // confidentialité et pièces jointes viennent bien de $this->courrier et
    // fuitaient) — même faille que extraitTexteOcr() dans CourrierList
    // (même jour), par un mécanisme différent (propriété publique mutable,
    // pas un paramètre d'action typé).
    public function test_le_panneau_apercu_refuse_un_courrier_hors_du_perimetre_apres_changement_did(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $lemien = $this->courrierEnregistrePar($agent);

        // Ne passe pas par courrierEnregistrePar() ci-dessus pour ce second
        // courrier : son service_id par défaut (Service::factory()->create(
        // ['code' => 'TST'])) est évalué AVANT le array_merge qui le
        // remplacerait, donc s'exécute quand même et entre en collision avec
        // le service déjà créé pour $lemien.
        $autreAgent = $this->utilisateurAvecProfil('Agent');
        $horsPerimetre = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-AUT-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Objet strictement hors périmètre',
            'expediteur_nom' => 'Expéditeur Strictement Hors Périmètre',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'priorite' => 'normale',
            'confidentialite' => 1,
            'service_id' => Service::factory()->create(['code' => 'AUT'])->id,
        ]);
        CourrierHistorique::create([
            'courrier_id' => $horsPerimetre->id,
            'auteur_id' => $autreAgent->id,
            'action' => 'creation',
            'commentaire' => null,
        ]);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $lemien->id])
            ->set('courrierId', $horsPerimetre->id)
            ->assertDontSee('Expéditeur Strictement Hors Périmètre')
            ->assertDontSee($horsPerimetre->numero_reference);
    }

    public function test_aucune_entree_dhistorique_si_rien_nest_modifie(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        $this->assertSame(0, CourrierHistorique::where('courrier_id', $courrier->id)
            ->where('action', 'modification')
            ->count());
    }

    public function test_on_peut_ajouter_une_piece_jointe_lors_dune_modification(): void
    {
        Storage::fake('s3');

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->set('nouvellesPiecesJointes', [UploadedFile::fake()->create('copie.pdf', 100, 'application/pdf')])
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        $pieceJointe = $courrier->piecesJointes()->firstOrFail();

        $this->assertSame('copie.pdf', $pieceJointe->nom_original);
        Storage::disk('s3')->assertExists($pieceJointe->fichier_path);

        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)
            ->where('action', 'ajout_piece_jointe')
            ->count());
    }

    // 2026-10-23 — plusieurs pièces jointes ajoutables d'un coup en
    // modification aussi (même mécanisme qu'à l'enregistrement).
    public function test_on_peut_ajouter_plusieurs_pieces_jointes_lors_dune_modification(): void
    {
        Storage::fake('s3');

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->set('nouvellesPiecesJointes', [
                UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('bon-de-livraison.pdf', 100, 'application/pdf'),
            ])
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        $this->assertSame(2, $courrier->piecesJointes()->count());
        $this->assertSame(2, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'ajout_piece_jointe')->count());
    }

    public function test_les_nouveaux_champs_de_la_maquette_sont_charges_a_lecran_de_modification(): void
    {
        // Maquette "Modifier le courrier" (2026-09-18) — direction_origine_id/
        // echeance/type_traitement/expediteur_fonction/note_interne sont de
        // vrais nouveaux champs (voir migration 2026_09_18_070000), pas de la
        // vue seulement : doivent être rechargés au montage comme le reste.
        $agent = $this->utilisateurAvecProfil('Agent');
        $directionOrigine = Service::factory()->create(['code' => 'DRH']);
        $courrier = $this->courrierEnregistrePar($agent, [
            'direction_origine_id' => $directionOrigine->id,
            'echeance' => '2026-09-25',
            'type_traitement' => 'Traitement classique',
            'expediteur_fonction' => 'Chef de service',
            'note_interne' => 'À traiter en priorité.',
        ]);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->assertSet('directionOrigineId', $directionOrigine->id)
            ->assertSet('echeance', '2026-09-25')
            ->assertSet('typeTraitement', 'Traitement classique')
            ->assertSet('expediteurFonction', 'Chef de service')
            ->assertSet('noteInterne', 'À traiter en priorité.');
    }

    public function test_les_nouveaux_champs_de_la_maquette_sont_enregistres_et_traces_dans_lhistorique(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $directionOrigine = Service::factory()->create(['code' => 'DRH']);
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->set('directionOrigineId', $directionOrigine->id)
            ->set('echeance', '2026-09-25')
            ->set('typeTraitement', 'Traitement urgent')
            ->set('expediteurFonction', 'Chef de service')
            ->set('noteInterne', 'Note interne de test.')
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        $courrier->refresh();

        $this->assertSame($directionOrigine->id, $courrier->direction_origine_id);
        $this->assertSame('2026-09-25', $courrier->echeance->format('Y-m-d'));
        $this->assertSame('Traitement urgent', $courrier->type_traitement);
        $this->assertSame('Chef de service', $courrier->expediteur_fonction);
        $this->assertSame('Note interne de test.', $courrier->note_interne);

        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)
            ->where('action', 'modification')
            ->count());
    }

    public function test_les_nouveaux_champs_de_la_maquette_ne_sont_pas_obligatoires(): void
    {
        // Aucune règle métier n'impose ces champs à ce jour (voir
        // EditForm::enregistrerModification()) — un courrier existant sans
        // aucune de ces valeurs doit rester modifiable normalement.
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->set('form.objet', 'Objet corrigé')
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        $this->assertSame('Objet corrigé', $courrier->refresh()->objet);
    }

    public function test_on_peut_ajouter_une_deuxieme_piece_jointe(): void
    {
        Storage::fake('s3');

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        PieceJointe::create([
            'courrier_id' => $courrier->id,
            'fichier_path' => 'courriers/'.now()->year.'/TST/'.$courrier->numero_reference.'/premiere.pdf',
            'nom_original' => 'premiere.pdf',
            'type_mime' => 'application/pdf',
            'taille' => 100,
        ]);

        $this->actingAs($agent);

        Livewire::test(EditForm::class, ['courrierId' => $courrier->id])
            ->set('nouvellesPiecesJointes', [UploadedFile::fake()->create('deuxieme.pdf', 100, 'application/pdf')])
            ->call('enregistrerModification')
            ->assertHasNoErrors();

        $this->assertSame(2, $courrier->piecesJointes()->count());
    }
}
