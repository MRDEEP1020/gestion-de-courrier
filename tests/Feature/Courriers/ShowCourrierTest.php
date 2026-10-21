<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\DossierClassement;
use App\Models\PieceJointe;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShowCourrierTest extends TestCase
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
            'objet' => 'Courrier de test',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
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

        $html = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->html();

        $ancrage = strpos($html, 'activerDeplacement');
        $this->assertNotFalse($ancrage, 'Le panneau "Aperçu du courrier" ne s\'est pas affiché.');

        $debut = strrpos(substr($html, 0, $ancrage), 'x-data="{');
        $finAttribut = strpos($html, '}"', $ancrage);
        $bloc = substr($html, $debut + strlen('x-data="'), $finAttribut - ($debut + strlen('x-data="')) + 1);

        $this->assertStringNotContainsString('"', $bloc, 'Un guillemet droit dans le bloc x-data casse l\'attribut HTML.');
        $this->assertSame(1, substr_count($html, 'Voir en plein écran'), 'Le bouton de la modale agrandie ne s\'est pas rendu correctement.');
    }

    public function test_un_administrateur_peut_voir_nimporte_quel_courrier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $admin = $this->utilisateurAvecProfil('Administrateur');
        $this->actingAs($admin);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertOk()
            ->assertSee($courrier->numero_reference);
    }

    public function test_lagent_createur_peut_voir_son_propre_courrier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertOk()
            ->assertSee($courrier->numero_reference);
    }

    public function test_un_agent_ne_peut_pas_voir_le_courrier_dun_autre_agent(): void
    {
        $createur = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($createur);

        $autreAgent = User::factory()->create(['profil_id' => $createur->profil_id]);
        $this->actingAs($autreAgent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertForbidden();
    }

    public function test_un_collaborateur_non_affecte_ne_peut_pas_voir_le_courrier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $collaborateur = $this->utilisateurAvecProfil('Collaborateur');
        $this->actingAs($collaborateur);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertForbidden();
    }

    public function test_le_texte_ocr_est_affiche_dans_un_bloc_preformate(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $courrier->update([
            'fichier_path' => 'courriers/'.now()->year.'/TST/'.$courrier->numero_reference.'.jpeg',
            'texte_ocr' => "Heure/Time\nAller/Retour\nBagage/Luggage",
            'ocr_statut' => 'reussi',
            'ocr_traite_le' => now(),
        ]);

        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertOk()
            ->assertSeeHtml('<pre')
            ->assertSee('Bagage/Luggage')
            ->assertSee('Télécharger');
    }

    public function test_un_document_pdf_est_affiche_dans_le_panneau_apercu_en_canevas(): void
    {
        // Le lecteur PDF.js vendu en <iframe> a été remplacé par un panneau
        // aperçu unique en canevas (pdfjs-dist côté JS, document-preview.js),
        // commun aux PDF et aux images scannées.
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $courrier->update(['fichier_path' => 'courriers/'.now()->year.'/TST/'.$courrier->numero_reference.'.pdf']);

        $this->actingAs($agent);

        $html = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->html();

        $this->assertStringNotContainsString('/vendor/pdfjs/web/viewer.html', $html);
        $this->assertStringContainsString(
            str_replace('/', '\/', route('courriers.document.apercu', $courrier->id)),
            $html
        );
    }

    public function test_une_image_scannee_est_affichee_dans_le_meme_panneau_apercu(): void
    {
        // Même panneau aperçu que pour un PDF : plus de distinction
        // pdfjs-iframe vs affichage natif, tout passe par document-preview.js.
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $courrier->update(['fichier_path' => 'courriers/'.now()->year.'/TST/'.$courrier->numero_reference.'.jpeg']);

        $this->actingAs($agent);

        $html = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->html();

        $this->assertStringNotContainsString('/vendor/pdfjs/web/viewer.html', $html);
        $this->assertStringContainsString(
            str_replace('/', '\/', route('courriers.document.apercu', $courrier->id)),
            $html
        );
    }

    public function test_les_coordonnees_rc_et_niu_de_lexpediteur_sont_affiches(): void
    {
        // Demande explicite de l'utilisateur (2026-09-07). Au passage :
        // les coordonnées, bien que déjà capturées depuis le 2026-09-04,
        // n'avaient jamais été affichées sur cette fiche — corrigé ici pour
        // rester cohérent avec les nouveaux champs RC/NIU du même bloc.
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $courrier->update([
            'expediteur_telephone' => '(00237)658239075',
            'expediteur_email' => 'contact@itsc-sarl.cm',
            'expediteur_adresse' => 'BP 2138 Yaoundé Cameroun',
            'expediteur_rc' => 'RC/YAO/2019/B/433',
            'expediteur_niu' => 'M051912784615T',
        ]);

        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('(00237)658239075')
            ->assertSee('contact@itsc-sarl.cm')
            ->assertSee('BP 2138 Yaoundé Cameroun')
            ->assertSee('RC/YAO/2019/B/433')
            ->assertSee('M051912784615T');
    }

    public function test_la_fiche_se_rafraichit_a_la_fin_de_locr(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $courrier->update(['fichier_path' => 'courriers/x.jpeg', 'ocr_statut' => 'en_cours']);

        $this->actingAs($agent);

        $composant = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('traitement en cours');

        // Le job termine pendant que la fiche est ouverte…
        $courrier->update(['ocr_statut' => 'reussi', 'ocr_confiance' => 87, 'texte_ocr' => 'Texte reconnu par l\'OCR']);

        // …et diffuse l'événement : la donnée en cache est invalidée, la fiche se re-rend.
        $composant
            ->call('ocrTermine', ['statut' => 'reussi', 'confiance' => 87])
            ->assertOk()
            ->assertSee('confiance 87 %')
            ->assertDontSee('traitement en cours');
    }

    public function test_la_fiche_se_rafraichit_en_temps_reel_quand_un_autre_utilisateur_change_le_statut(): void
    {
        // Module 4 — demande explicite de l'utilisateur : "make the
        // parcours du courier work live in real time at each step". Même
        // motif que test_la_fiche_se_rafraichit_a_la_fin_de_locr ci-dessus,
        // mais pour CourrierStatutChange (voir WorkflowService) plutôt que
        // OcrTermine — un collègue affecte le courrier pendant que CETTE
        // fiche reste ouverte : le parcours doit refléter le nouveau
        // statut sans rechargement manuel.
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        $composant = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Création')
            ->assertSeeInOrder(['Affectation', 'En attente']);

        // Un collègue affecte le courrier pendant que la fiche est ouverte…
        $courrier->update(['statut' => 'affecte']);

        // …WorkflowService diffuse l'événement : la donnée en cache
        // (etapesParcours notamment) est invalidée, la fiche se re-rend.
        $composant
            ->call('statutChange')
            ->assertOk()
            ->assertSeeInOrder(['Affectation', 'En cours']);
    }

    public function test_creation_reste_franchie_meme_sur_une_piste_annexe_du_parcours(): void
    {
        // Bug réel constaté par l'utilisateur avec capture d'écran : un
        // courrier "en_attente_de_transfert" affichait TOUTES les étapes
        // (y compris "Création") comme non franchies, alors que le
        // courrier existe forcément déjà — "en_attente_de_transfert" n'est
        // littéralement pas dans l'ordre des 5 étapes standard, donc
        // array_search() échouait pour TOUT, pas seulement les étapes
        // réellement futures.
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['statut' => 'en_attente_de_transfert']);

        $this->actingAs($agent);

        $composant = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertOk();

        $etapes = collect($composant->get('etapesParcours'));

        $this->assertTrue($etapes->firstWhere('statut', 'creation')['atteinte'], 'Création doit rester franchie même sur une piste annexe.');
        $this->assertTrue($etapes->firstWhere('statut', 'transfert')['courante'], 'Transfert doit être l\'étape courante pendant "en_attente_de_transfert".');
        $this->assertFalse($etapes->firstWhere('statut', 'affecte')['atteinte'], 'Affectation ne doit pas être franchie avant la validation du transfert.');
        // Libellé du badge (Courrier::libelleStatut()), plus la valeur brute.
        $composant->assertSee('En attente de transfert');
    }

    // Demande de l'utilisateur (2026-09-24) : après la validation DGA, le
    // statut 'enregistre' s'affichait "Enregistre", comme un retour en
    // arrière — il s'affiche désormais comme le 3e sous-statut du SRS.
    public function test_le_statut_enregistre_saffiche_transfere_a_affecter(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['statut' => 'enregistre']);

        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Transféré — à affecter');

        $this->assertSame('Transféré — à affecter', Courrier::libelleStatut('enregistre'));
        $this->assertSame('statut inconnu', Courrier::libelleStatut('statut_inconnu'));
    }

    public function test_les_pieces_jointes_sont_affichees(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        PieceJointe::create([
            'courrier_id' => $courrier->id,
            'fichier_path' => 'courriers/'.now()->year.'/TST/'.$courrier->numero_reference.'/copie.pdf',
            'nom_original' => 'copie-recue.pdf',
            'type_mime' => 'application/pdf',
            'taille' => 100,
        ]);

        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertOk()
            ->assertSee('copie-recue.pdf');
    }

    public function test_les_nouveaux_champs_de_la_maquette_detail_sont_affiches(): void
    {
        // Maquette "Détail du courrier" (2026-09-18, "use this design
        // exactly") — dossier_reference/echeance/sla_jours/
        // service_responsable_id sont de vrais nouveaux champs (voir
        // migration 2026_09_18_100000), pas seulement de la vue.
        $agent = $this->utilisateurAvecProfil('Agent');
        $serviceResponsable = Service::factory()->create(['code' => 'DAF']);
        $courrier = $this->courrierEnregistrePar($agent, [
            'dossier_reference' => 'DOS-2026-0042',
            'echeance' => '2026-09-25',
            'sla_jours' => 2,
            'service_responsable_id' => $serviceResponsable->id,
            'note_interne' => 'À traiter en priorité.',
        ]);

        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertOk()
            ->assertSee('DOS-2026-0042')
            ->assertSee('25/09/2026')
            ->assertSee('2 jours')
            ->assertSee($serviceResponsable->nom)
            ->assertSee('À traiter en priorité.');
    }

    // Module 3/9 — "Classer dans un dossier" (2026-09-22, "les trois" points
    // d'entrée demandés par l'utilisateur). Le bloc "Dossier de classement"
    // est gated par peutClasser (= view()), pas par le nombre de dossiers
    // accessibles — un courrier non classé affiche "Non classé" même sans
    // aucun dossier disponible (le formulaire indique alors qu'il faut en
    // créer un depuis "Dossiers & Archives").
    public function test_le_bloc_dossier_de_classement_nest_visible_que_pour_qui_peut_voir_le_courrier(): void
    {
        $createur = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($createur);

        $autreAgent = User::factory()->create(['profil_id' => $createur->profil_id]);
        $this->actingAs($autreAgent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertForbidden();

        $this->actingAs($createur);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Dossier de classement'))
            ->assertSee(__('Non classé'));
    }

    // Signalé explicitement par l'utilisateur ("the dossier lie not
    // functioning or showing on the ui") : "Dossier lié" (carte "Détails
    // complémentaires") montrait `dossier_reference`, un champ texte libre
    // SANS RAPPORT avec le vrai classement en dossier — confusion réelle une
    // fois le classement construit. Doit maintenant afficher le VRAI nom du
    // dossier de classement, la référence externe restant visible en second.
    public function test_le_dossier_lie_affiche_le_vrai_dossier_de_classement(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $dossier = DossierClassement::create(['nom' => 'Sinistres 2026', 'cree_par_id' => $agent->id]);
        $courrier = $this->courrierEnregistrePar($agent, [
            'dossier_classement_id' => $dossier->id,
            'dossier_reference' => 'DOS-2026-0042',
        ]);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Sinistres 2026')
            ->assertSee('DOS-2026-0042');
    }

    // 2026-10-02 — retour utilisateur avec capture d'écran : "where does it
    // represent here that this courier is inside a folder", puis "i want it
    // somewhere there" (capture de l'en-tête), puis "here" (capture recadrée
    // sur la rangée de badges N°/Statut) — le nom du dossier n'apparaissait
    // qu'en bas de page (Détails complémentaires + Actions rapides + l'option
    // du menu déroulant de la modale "Classer dans un dossier", qui liste
    // aussi ce dossier parmi les accessibles — 3 occurrences avant ces
    // correctifs), hors du premier écran visible sans défiler. Ajouté dans
    // DEUX endroits toujours visibles : le champ "Dossier" du panneau
    // "Informations générales", ET un badge pilule dans la rangée de badges
    // de l'en-tête (à côté de x-statut-badge et x-chronometre), au format
    // "NomDossier/NuméroCourrier" ("i want this isnstead dossier
    // name/courier name") — l'emplacement le plus en évidence de toute la
    // page. "Non classé" ne s'affiche QUE dans "Informations générales",
    // jamais dans l'en-tête (réservé à ce qui est effectivement vrai sur ce
    // courrier, même logique que le numéro de tampon juste en dessous). Ce
    // test compte les occurrences pour vérifier que ces deux nouveaux
    // endroits rendent bien, sans dépendre d'un texte de libellé ("Dossier")
    // trop générique pour être cherché sans ambiguïté avec "Dossier
    // lié"/"Dossier de classement" déjà existants.
    public function test_le_nom_du_dossier_apparait_aussi_dans_informations_generales(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $dossier = DossierClassement::create(['nom' => 'Sinistres 2026 Unique', 'cree_par_id' => $agent->id]);
        $courrier = $this->courrierEnregistrePar($agent, ['dossier_classement_id' => $dossier->id]);
        $this->actingAs($agent);

        $html = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->html();

        $this->assertSame(5, substr_count($html, 'Sinistres 2026 Unique'), 'Le nom du dossier doit apparaître 5 fois : en-tête, Informations générales, Détails complémentaires, Actions rapides, option de la modale "Classer".');
    }

    // Non classé : le champ "Dossier" du panneau "Informations générales"
    // doit quand même afficher un état clair, jamais rester vide — MAIS
    // la rangée de badges de l'en-tête ne doit RIEN montrer dans ce cas,
    // "Non classé" n'y ayant pas sa place (réservé à ce qui est
    // effectivement vrai sur ce courrier).
    public function test_non_classe_saffiche_dans_informations_generales_sans_dossier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSeeInOrder([__('Référence externe'), __('Dossier'), __('Non classé')])
            ->assertDontSee(__('Dossier').' :', false);
    }

    public function test_classer_dans_un_dossier_assigne_le_courrier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $dossier = DossierClassement::create(['nom' => 'Mon dossier', 'cree_par_id' => $agent->id]);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('ouvrirClassement')
            ->set('dossierAClasserId', $dossier->id)
            ->call('classerDansDossier')
            ->assertHasNoErrors();

        $this->assertSame($dossier->id, $courrier->fresh()->dossier_classement_id);
    }

    // Règle n°6 — un dossier hors de dossiersAccessibles (ici créé par
    // quelqu'un d'autre, jamais partagé) ne doit jamais être accepté, même
    // si son ID est posté directement.
    public function test_classer_refuse_un_dossier_non_accessible(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $autreUtilisateur = $this->utilisateurAvecProfil('Responsable de service');
        $dossierEtranger = DossierClassement::create(['nom' => 'Dossier étranger', 'cree_par_id' => $autreUtilisateur->id]);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('dossierAClasserId', $dossierEtranger->id)
            ->call('classerDansDossier')
            ->assertHasErrors('dossierAClasserId');

        $this->assertNull($courrier->fresh()->dossier_classement_id);
    }

    public function test_retirer_du_dossier_vide_le_champ(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $dossier = DossierClassement::create(['nom' => 'Mon dossier', 'cree_par_id' => $agent->id]);
        $courrier = $this->courrierEnregistrePar($agent, ['dossier_classement_id' => $dossier->id]);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('retirerDuDossier');

        $this->assertNull($courrier->fresh()->dossier_classement_id);
    }

    public function test_le_parcours_du_courrier_a_6_etapes_relibellees(): void
    {
        // Maquette "Détail du courrier" (2026-09-18) — 6 étapes génériques
        // (Création/Transfert/Affectation/Traitement/Réponse/Clôture) au
        // lieu des statuts bruts (voir ShowCourrier::etapesParcours()) —
        // "Transfert" ajouté après "Création" sur demande explicite de
        // l'utilisateur ("on the parcour add transfere after creation").
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['statut' => 'en_traitement']);

        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertOk()
            ->assertSee('Création')
            ->assertSee('Transfert')
            ->assertSee('Affectation')
            ->assertSee('Traitement')
            ->assertSee('Réponse')
            ->assertSee('Clôture')
            ->assertSee('En cours')
            ->assertSee('En attente')
            ->assertSee('Non clôturé');
    }

    public function test_letape_transfert_est_non_applicable_pour_un_courrier_qui_na_jamais_ete_transfere(): void
    {
        // Un courrier sortant (ou un sinistre auto-routé, voir
        // RegistrationForm::enregistrer()) reste toujours au statut
        // 'enregistre' de la création à l'affectation SANS jamais passer
        // par 'en_attente_de_transfert'/'en_cours_de_transfert' — "Transfert"
        // doit être marquée franchie (rien ne bloque l'affectation) mais
        // "Non applicable", jamais "En attente" (qui suggérerait à tort
        // qu'un transfert reste encore à faire) ni "En cours".
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['sens' => 'sortant', 'statut' => 'enregistre']);

        $this->actingAs($agent);

        $composant = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertOk();

        $etapeTransfert = collect($composant->get('etapesParcours'))->firstWhere('statut', 'transfert');

        $this->assertTrue($etapeTransfert['atteinte']);
        $this->assertSame('Non applicable', $etapeTransfert['sousLibelle']);
    }

    public function test_letape_transfert_affiche_la_date_reelle_une_fois_le_service_valide_par_la_dga(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['statut' => 'affecte']);

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => $agent->id,
            'action' => 'service_valide_dga',
            'commentaire' => 'Service confirmé par la DGA',
        ]);

        $this->actingAs($agent);

        $composant = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertOk();

        $etapeTransfert = collect($composant->get('etapesParcours'))->firstWhere('statut', 'transfert');

        $this->assertTrue($etapeTransfert['atteinte']);
        $this->assertFalse($etapeTransfert['courante']);
        $this->assertNotSame('Non applicable', $etapeTransfert['sousLibelle']);
        $this->assertNotSame('En attente', $etapeTransfert['sousLibelle']);
    }

    // 2026-09-22, demande explicite de l'utilisateur (diagnostic en direct :
    // "why do i have to navigate there first where is that shortcut one")
    // — un lien (Tâches du jour, Actions rapides) doit pouvoir ouvrir directement sur
    // l'onglet "Circuit de traitement" via ?onglet=circuit, au lieu de
    // forcer un clic supplémentaire sur chaque courrier.
    public function test_onglet_circuit_est_ouvert_directement_via_le_parametre_durl(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $this->actingAs($agent);

        $reponse = $this->get(route('courriers.show', ['courrierId' => $courrier->id, 'onglet' => 'circuit']));

        $reponse->assertOk();
        $reponse->assertSee("onglet: 'circuit'", false);
    }

    // Une valeur inconnue (manipulation d'URL, lien cassé) ne doit jamais
    // laisser la page sans aucun onglet visible — repli sur 'general'.
    public function test_une_valeur_donglet_inconnue_retombe_sur_general(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $this->actingAs($agent);

        $reponse = $this->get(route('courriers.show', ['courrierId' => $courrier->id, 'onglet' => 'inexistant']));

        $reponse->assertOk();
        $reponse->assertSee("onglet: 'general'", false);
    }
}
