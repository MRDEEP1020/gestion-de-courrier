<?php

namespace Tests\Feature\Jobs;

use App\Events\OcrTermine;
use App\Jobs\ProcessDocumentOcr;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Parametre;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ProcessDocumentOcrTest extends TestCase
{
    use RefreshDatabase;

    private function courrierScanne(): Courrier
    {
        return Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier de test',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'service_id' => Service::factory()->create(['code' => 'TST'])->id,
            'fichier_path' => 'courriers/'.now()->year.'/TST/GEC-'.now()->year.'-TST-000001.pdf',
            'ocr_statut' => 'en_cours',
        ]);
    }

    private function tsv(array $lignes): string
    {
        $entete = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext";

        return $entete."\n".implode("\n", $lignes)."\n";
    }

    public function test_le_tsv_est_reconstruit_en_lignes_et_paragraphes_avec_une_confiance_moyenne(): void
    {
        $tsv = $this->tsv([
            "1\t1\t0\t0\t0\t0\t0\t0\t1000\t1000\t-1\t",
            "5\t1\t1\t1\t1\t1\t10\t10\t50\t20\t96\tHeure/Time",
            "5\t1\t1\t1\t1\t2\t70\t10\t50\t20\t90\t04:00",
            "5\t1\t1\t1\t2\t1\t10\t40\t50\t20\t80\tAller/Retour",
            "5\t1\t2\t1\t1\t1\t10\t100\t50\t20\t70\tBagage",
            "5\t1\t2\t1\t1\t2\t70\t100\t50\t20\t-1\t",
            "5\t1\t2\t1\t1\t3\t130\t100\t50\t20\t60\t   ",
        ]);

        [$texte, $confiance] = ProcessDocumentOcr::extraireTexteEtConfiance($tsv);

        $this->assertSame("Heure/Time 04:00\nAller/Retour\n\nBagage", $texte);
        $this->assertSame(84, $confiance); // (96+90+80+70)/4
    }

    public function test_un_tsv_sans_mot_donne_un_texte_vide_et_aucune_confiance(): void
    {
        [$texte, $confiance] = ProcessDocumentOcr::extraireTexteEtConfiance($this->tsv([
            "1\t1\t0\t0\t0\t0\t0\t0\t1000\t1000\t-1\t",
        ]));

        $this->assertSame('', $texte);
        $this->assertNull($confiance);

        [$texte, $confiance] = ProcessDocumentOcr::extraireTexteEtConfiance('');

        $this->assertSame('', $texte);
        $this->assertNull($confiance);
    }

    public function test_un_seuil_de_confiance_filtre_les_mots_les_moins_fiables(): void
    {
        // Demande explicite de l'utilisateur (2026-09-18, second passage OCR
        // sur une page inversée pour lire un bandeau clair-sur-sombre — voir
        // texteBanniereInversee()) : un seuil élevé ne garde que les mots
        // fiables, filtrant le bruit généré par l'inversion du reste de la
        // page (devenue illisible), sans affecter le comportement par
        // défaut (seuil 0, tout mot reconnu est gardé, non-régression).
        $tsv = $this->tsv([
            "5\t1\t1\t1\t1\t1\t0\t0\t50\t20\t96\tRC/YAO/2022/B/1207",
            "5\t1\t1\t1\t1\t2\t60\t0\t50\t20\t20\tbruit",
            "5\t1\t2\t1\t1\t1\t0\t50\t50\t20\t85\tNIU",
        ]);

        [$texteSansSeuil] = ProcessDocumentOcr::extraireTexteEtConfiance($tsv);
        $this->assertSame("RC/YAO/2022/B/1207 bruit\n\nNIU", $texteSansSeuil);

        [$texteAvecSeuil] = ProcessDocumentOcr::extraireTexteEtConfiance($tsv, seuilConfianceMot: 70);
        $this->assertSame("RC/YAO/2022/B/1207\n\nNIU", $texteAvecSeuil);
    }

    public function test_le_motif_qualite_distingue_absence_de_texte_et_confiance_faible(): void
    {
        $this->assertStringContainsString('Aucun texte', ProcessDocumentOcr::motifQualite('abc', null));
        $this->assertStringContainsString('confiance faible (40 %', ProcessDocumentOcr::motifQualite(str_repeat('mot ', 20), 40));
    }

    // "Group A" (2026-09-24, voir DECISIONS.md "Paramètres système
    // configurables — Groupe A/B") : ex-`const CONFIANCE_MINIMALE`/
    // `LONGUEUR_MINIMALE_TEXTE`, désormais lues dynamiquement sur Parametre.
    public function test_les_seuils_qualite_sont_configurables(): void
    {
        Parametre::actuel()->update(['ocr_confiance_minimale' => 90, 'ocr_longueur_minimale_texte' => 100]);
        Parametre::invaliderCache();

        $this->assertSame(90, ProcessDocumentOcr::confianceMinimale());
        $this->assertSame(100, ProcessDocumentOcr::longueurMinimaleTexte());
        $this->assertStringContainsString('minimum 90 %', ProcessDocumentOcr::motifQualite(str_repeat('mot ', 30), 80));
    }

    public function test_le_numero_de_tampon_est_detecte_de_facon_indicative(): void
    {
        // Format confirmé le 2026-09-04 sur deux vrais tampons (voir DECISIONS.md
        // "Numéro de tampon") : jour, mois abrégé en lettres, apostrophe, année
        // sur 2 chiffres, heure avec secondes.
        $texte = "NSIA ASSURANCES\n21 JUIL '26 10:26:48-1789553\n\nObjet : réclamation sinistre auto";

        $this->assertSame(
            "NSIA ASSURANCES 21 JUIL '26 10:26:48-1789553",
            ProcessDocumentOcr::extraireNumeroTampon($texte),
        );
    }

    public function test_le_numero_de_tampon_est_detecte_avec_un_mois_sur_trois_lettres(): void
    {
        $texte = "NSIA ASSURANCES\n27 JUL '26 13:14:34-1789067";

        $this->assertSame(
            "NSIA ASSURANCES 27 JUL '26 13:14:34-1789067",
            ProcessDocumentOcr::extraireNumeroTampon($texte),
        );
    }

    public function test_labsence_de_tampon_reconnaissable_ne_produit_aucune_suggestion(): void
    {
        $this->assertNull(ProcessDocumentOcr::extraireNumeroTampon('Lettre ordinaire sans tampon d\'entrée.'));
        $this->assertNull(ProcessDocumentOcr::extraireNumeroTampon(''));
    }

    public function test_lobjet_est_extrait_apres_la_mention_objet(): void
    {
        $this->assertSame(
            'Défis Actuels',
            ProcessDocumentOcr::extraireObjet("Yaoundé, le 07 Janvier 2026\n\nObjet : Défis Actuels\n\nMonsieur,"),
        );
        $this->assertSame('Réclamation sinistre auto', ProcessDocumentOcr::extraireObjet("Objet: Réclamation sinistre auto\nSuite..."));
        $this->assertNull(ProcessDocumentOcr::extraireObjet('Lettre sans mention explicite de son objet.'));
        $this->assertNull(ProcessDocumentOcr::extraireObjet(null));
        $this->assertNull(ProcessDocumentOcr::extraireObjet(''));
    }

    public function test_lobjet_est_extrait_apres_la_mention_concerne(): void
    {
        // "Concerne :" est un synonyme administratif courant d'"Objet :" —
        // vu sur un vrai document ("The Best Group (TBG)"), 2026-09-07.
        $this->assertSame(
            "Accompagnement dans la mise en place d'une solution informatique",
            ProcessDocumentOcr::extraireObjet("Concerne : Accompagnement dans la mise en place d'une solution informatique\n\nMonsieur,"),
        );
    }

    public function test_lobjet_recolle_la_ligne_suivante_quand_elle_se_termine_par_une_conjonction(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-17, "The Best
        // Group (TBG)", TBG/CMR/NSIA) : l'objet est tronqué avant
        // "DIGITALISATION..." parce que la phrase est coupée par un simple
        // retour à la ligne dû à la largeur de la page, juste après la
        // conjonction "OU" — même bug (mêmes causes : un simple retour à la
        // ligne) que déjà corrigé pour le repli sans mention "Objet :" (voir
        // test ci-dessous, document FORMAVISION.COM), mais jamais corrigé
        // pour la mention explicite "Objet :"/"Concerne :" elle-même.
        $this->assertSame(
            'ACCOMPAGNEMENT DANS LA MISE EN PLACE DES SOLUTIONS INFORMATIQUES, DEMATERIALISATION OU DIGITALISATION DES PROCESSUS METIERS.',
            ProcessDocumentOcr::extraireObjet(
                "Concerne : ACCOMPAGNEMENT DANS LA MISE EN PLACE DES SOLUTIONS INFORMATIQUES, DEMATERIALISATION OU\n"
                .'DIGITALISATION DES PROCESSUS METIERS.',
            ),
        );

        // Ne recolle PAS quand la ligne suivante n'est pas une continuation
        // de la même phrase (pas de mot de liaison en fin de ligne captée) —
        // même texte que le test "Suite..." ci-dessus, qui doit rester
        // inchangé.
        $this->assertSame(
            'Réclamation sinistre auto',
            ProcessDocumentOcr::extraireObjet("Objet: Réclamation sinistre auto\nSuite..."),
        );
    }

    public function test_lobjet_recolle_la_ligne_suivante_quand_les_deux_sont_tout_en_majuscules(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-22, CDS
        // Technologies Sarl, "objet doesn't take all the info") : "Objet :
        // OFFRE SPECIALE DE DEUX MOIS SDG" se coupe sur "SDG", un mot qui
        // n'est PAS dans la liste des liaisons reconnues (probablement du
        // bruit OCR d'un tampon de service voisin) — le motif de liaison
        // seul ratait donc la vraie suite "DE CONNEXION INTERNET PAR FIBRE
        // OPTIQUE GRATUITE". Un objet tout en majuscules qui continue sur
        // une ligne ELLE AUSSI tout en majuscules est un second signal fiable.
        $this->assertSame(
            'OFFRE SPECIALE DE DEUX MOIS SDG DE CONNEXION INTERNET PAR FIBRE OPTIQUE GRATUITE',
            ProcessDocumentOcr::extraireObjet(
                "N/Réf : 0041/CDS/CSMC/08-2024\nObjet : OFFRE SPECIALE DE DEUX MOIS SDG\n"
                ."DE CONNEXION INTERNET PAR FIBRE OPTIQUE GRATUITE\nMonsieur le Directeur Général,",
            ),
        );

        // Ne doit PAS avaler la formule d'appel qui suit (casse mixte, donc
        // plus "tout en majuscules") — vérifié sur la ligne SUIVANTE, pas
        // seulement la ligne déjà captée, sinon la 2e ligne (elle aussi tout
        // en majuscules) continuerait d'avaler "Monsieur le Directeur
        // Général,".
        $this->assertStringNotContainsString(
            'Monsieur',
            ProcessDocumentOcr::extraireObjet(
                "Objet : OFFRE SPECIALE DE DEUX MOIS SDG\nDE CONNEXION INTERNET PAR FIBRE OPTIQUE GRATUITE\nMonsieur le Directeur Général,",
            ),
        );
    }

    public function test_lobjet_est_extrait_du_paragraphe_precedant_la_formule_dappel_sans_mention_objet(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-04, "FORMAVISION.COM") :
        // pas de mention "Objet :", mais un intitulé différent ("Solutions
        // innovantes : ...") au même endroit structurel, juste avant la
        // formule d'appel. La phrase continue sur la ligne suivante
        // ("surveillance avancés.", simple retour à la ligne dû à la largeur
        // de la page) — doit être recollée, pas tronquée à la première ligne
        // (bug réel constaté par l'utilisateur le 2026-09-07, voir
        // DECISIONS.md).
        $this->assertSame(
            'Optimisez votre bureau avec nos fournitures connectées et systèmes de surveillance avancés.',
            ProcessDocumentOcr::extraireObjet(
                "Yaoundé, le 07 Janvier 2026\n\nA\nMonsieur le Directeur Général\nNSIA ASSURANCE\nYaoundé\n\n"
                ."Solutions innovantes : Optimisez votre bureau avec nos fournitures connectées et systèmes de\nsurveillance avancés.\n\n"
                ."Monsieur le Directeur Général,\n\nNous vous prions d'accorder une attention particulière à l'étude de notre offre.",
            ),
        );
    }

    public function test_un_libelle_hors_du_paragraphe_juste_avant_nest_pas_confondu_avec_lobjet(): void
    {
        // Bug réel trouvé en écrivant ce test : une première version de ce
        // repli remontait jusqu'à 6 lignes en arrière, y compris à travers
        // plusieurs paragraphes — elle confondait à tort un "Contacts :"
        // d'en-tête, deux paragraphes plus haut, avec l'objet. Corrigé en
        // restreignant au SEUL paragraphe qui précède immédiatement la
        // formule d'appel.
        $this->assertNull(ProcessDocumentOcr::extraireObjet(
            "Contacts : 655 12 34 56\n\nCher client,\n\nVoici notre catalogue.\n\nMonsieur le Directeur,\n\nNous avons le plaisir...",
        ));
    }

    public function test_le_destinataire_est_extrait_de_la_mention_a_lattention_de(): void
    {
        $this->assertSame(
            'Monsieur le Responsable RH',
            ProcessDocumentOcr::extraireDestinataire("À l'attention de Monsieur le Responsable RH\n\nObjet : Candidature"),
        );
        $this->assertSame('Madame la Directrice', ProcessDocumentOcr::extraireDestinataire("A l'attention de : Madame la Directrice"));
    }

    public function test_le_destinataire_est_extrait_de_la_civilite_suivie_dun_titre(): void
    {
        $this->assertSame(
            'Monsieur Le Directeur Général de NSIA Assurances',
            ProcessDocumentOcr::extraireDestinataire("Mardi le 21 Juillet 2026\n\nMonsieur Le Directeur Général de NSIA Assurances\n\nObjet : Défis Actuels\n\nMonsieur,"),
        );
    }

    public function test_le_destinataire_est_extrait_du_bloc_adresse_introduit_par_a_isole(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-04, "FORMAVISION.COM") :
        // convention "A" isolé sur sa propre ligne, suivi du bloc adresse sur
        // plusieurs lignes — ni "à l'attention de", ni une seule ligne de
        // civilité.
        $this->assertSame(
            "Monsieur le Directeur Général\nNSIA ASSURANCE\nYaoundé",
            ProcessDocumentOcr::extraireDestinataire(
                "Yaoundé, le 07 Janvier 2026\n\nA\nMonsieur le Directeur Général\nNSIA ASSURANCE\nYaoundé\n\nSolutions innovantes : Optimisez votre bureau.",
            ),
        );
    }

    public function test_le_destinataire_est_extrait_de_a_ou_a_accentue_suivi_de_la_civilite_sur_la_meme_ligne(): void
    {
        // Autre convention réelle signalée par l'utilisateur : "À Monsieur…"
        // directement, sans "l'attention de".
        $this->assertSame(
            'Monsieur le Directeur Général de NSIA Assurances',
            ProcessDocumentOcr::extraireDestinataire("A Monsieur le Directeur Général de NSIA Assurances\n\nObjet : Test"),
        );
        $this->assertSame(
            'Monsieur le Directeur des Sinistres',
            ProcessDocumentOcr::extraireDestinataire("À Monsieur le Directeur des Sinistres\n\nObjet : Test"),
        );
    }

    public function test_un_a_isole_non_suivi_dune_civilite_nest_pas_confondu_avec_un_marqueur_dadresse(): void
    {
        // Garde-fou du motif "A isolé" : sans civilité (Madame/Monsieur) sur
        // la ligne immédiatement suivante, "A" seul est trop souvent un
        // artefact OCR pour être traité comme un marqueur d'adresse.
        $this->assertNull(ProcessDocumentOcr::extraireDestinataire("A\nCeci n'est pas une adresse\nautre ligne"));
    }

    public function test_la_civilite_suivie_dun_titre_en_minuscule_est_reconnue(): void
    {
        // Bug réel constaté sur le même document réel : "Monsieur le
        // Directeur Général," (titre en minuscule) ne correspondait pas au
        // motif de repli, sensible à la casse sur "Le/La/Les".
        $this->assertSame(
            'Monsieur le Directeur Général',
            ProcessDocumentOcr::extraireDestinataire("Objet : Test\n\nMonsieur le Directeur Général,\n\nNous avons le plaisir..."),
        );
    }

    public function test_a_lattention_de_ne_capture_plus_toute_la_phrase_quand_le_marqueur_est_en_milieu_de_texte(): void
    {
        // Bug réel constaté lors de la revue adversariale du 2026-09-04 :
        // quand le marqueur apparaît au milieu d'une phrase plutôt que sur
        // sa propre ligne d'adresse, "(.+)" capturait toute la suite de la
        // ligne — corrigé en s'arrêtant à la première virgule ou au premier
        // point.
        $this->assertSame(
            'Madame la Chargée du dossier',
            ProcessDocumentOcr::extraireDestinataire(
                'Je vous saurais gré de bien vouloir faire suivre ce dossier à l\'attention de '
                .'Madame la Chargée du dossier, comme convenu lors de notre entretien téléphonique du 05 août dernier.',
            ),
        );
    }

    public function test_une_formule_dintroduction_nest_pas_avalee_dans_le_nom_via_le_motif_suffixe(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-22, CDS
        // Technologies Sarl, "the number now and the niu" — vérification
        // croisée qui a révélé ce bug adjacent) : "L'entreprise CDS
        // Technologies Sarl, spécialiste..." — le motif "forme juridique en
        // suffixe" capture toute la fenêtre de mots précédant "Sarl", et
        // "L'entreprise" y matche $mot au même titre qu'un vrai mot du nom
        // (majuscule initiale, reste en minuscules — rien ne les distingue
        // structurellement). Résultat sans le correctif : "L'entreprise CDS
        // Technologies Sarl" proposé au lieu de "CDS Technologies Sarl".
        $this->assertSame(
            'CDS Technologies Sarl',
            ProcessDocumentOcr::extraireExpediteurOrganisation(
                "L'entreprise CDS Technologies Sarl, spécialiste dans l'ingénierie des services.",
            ),
        );

        // Mêmes formules d'introduction, toutes couvertes par le même
        // filtre — la forme juridique elle-même (Sarl/SA) reste incluse
        // dans la valeur retournée, seule l'introduction est retirée
        // (cohérent avec "ITSC Sarl" du motif suffixe seul, testé plus haut).
        $this->assertSame(
            'TransCam Logistique Sarl',
            ProcessDocumentOcr::extraireExpediteurOrganisation('La société TransCam Logistique Sarl vous informe.'),
        );
        $this->assertSame(
            'Douala Freight SA',
            ProcessDocumentOcr::extraireExpediteurOrganisation('Le groupe Douala Freight SA vous informe.'),
        );
    }

    public function test_la_simple_formule_de_politesse_nest_pas_confondue_avec_un_destinataire(): void
    {
        $this->assertNull(ProcessDocumentOcr::extraireDestinataire("Objet : Défis Actuels\n\nMonsieur,\n\nÀ l'heure de la quête de performance..."));
        $this->assertNull(ProcessDocumentOcr::extraireDestinataire('Lettre sans mention explicite de son destinataire.'));
        $this->assertNull(ProcessDocumentOcr::extraireDestinataire(null));
        $this->assertNull(ProcessDocumentOcr::extraireDestinataire(''));
    }

    public function test_lorganisation_expeditrice_est_extraite_de_la_mention_expediteur(): void
    {
        $this->assertSame(
            'ABC Assurances Sarl',
            ProcessDocumentOcr::extraireExpediteurOrganisation("Expéditeur : ABC Assurances Sarl\n\nObjet : Sinistre"),
        );
    }

    public function test_lorganisation_expeditrice_est_extraite_de_la_mention_raison_sociale(): void
    {
        // "Raison sociale :" est l'intitulé légal exact de ce marqueur sur un
        // document administratif/fiscal camerounais (vu sur un vrai document,
        // "The Best Group (TBG)", 2026-09-07) — même fiabilité qu'"Expéditeur :".
        $this->assertSame(
            'The Best Group (TBG)',
            ProcessDocumentOcr::extraireExpediteurOrganisation("Raison sociale : The Best Group (TBG)\nRégime d'imposition : Réel"),
        );
    }

    public function test_lorganisation_expeditrice_est_extraite_dune_forme_juridique_courante(): void
    {
        $this->assertSame(
            'ITSC Sarl',
            ProcessDocumentOcr::extraireExpediteurOrganisation('ITSC Sarl Cybersécurité — Pentesting, Analyse de risque réseau - Audit.'),
        );
        $this->assertSame(
            'Cabinet Ndiaye',
            ProcessDocumentOcr::extraireExpediteurOrganisation('Le Cabinet Ndiaye vous informe que...'),
        );
    }

    public function test_un_fragment_ocr_isole_sur_sa_propre_ligne_nest_pas_recolle_au_nom_de_lorganisation(): void
    {
        // Bug réel constaté par l'utilisateur (2026-09-04) sur le vrai
        // document ITSC Sarl/NSIA : "URITE" (bruit OCR, fin d'un mot coupé
        // en haut de page) sur sa propre ligne, suivi d'"ITSC Sarl" sur la
        // ligne suivante — \s englobant le saut de ligne, le motif recollait
        // les deux en "URITE\nITSC Sarl", une chaîne absente du document.
        $this->assertSame(
            'ITSC Sarl',
            ProcessDocumentOcr::extraireExpediteurOrganisation("URITE\nITSC Sarl cyeiRi en Pen testing Anatyse\n\nMardi le 21 Juillet 2026"),
        );
    }

    public function test_un_courrier_sans_forme_juridique_ne_propose_aucune_organisation(): void
    {
        // Cas le plus fréquent en réalité : un assuré, personne physique,
        // n'a ni "Sarl" ni "SA" à son nom — aucune proposition, pas de faux
        // positif inventé sur un fragment de texte quelconque.
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation('Je soussigné Jean Dupont, déclare avoir été victime...'));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation(null));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation(''));
    }

    public function test_le_suffixe_sa_avec_points_est_reconnu(): void
    {
        // Bug réel constaté lors de la revue adversariale du 2026-09-04 : la
        // frontière de fin `\b` échoue après un point ("." n'est pas un
        // caractère de mot), donc "S.A." ne matchait jamais en pratique dans
        // un texte réel — corrigé par un lookahead "pas suivi d'une lettre".
        $this->assertSame(
            'ABC Assurances S.A.',
            ProcessDocumentOcr::extraireExpediteurOrganisation('Police souscrite auprès de la compagnie ABC Assurances S.A. le 2 janvier.'),
        );
    }

    public function test_une_mention_de_nsia_elle_meme_nest_jamais_proposee_comme_expediteur(): void
    {
        // Bug réel constaté lors de la revue adversariale du 2026-09-04 :
        // "NSIA Assurances SA" apparaît dans la quasi-totalité des
        // réclamations clients ("...bien assuré auprès de NSIA Assurances
        // SA...") — sans ce filtre, c'était le cas le plus fréquent en
        // pratique, pas un cas marginal. Nsia ne s'envoie jamais de courrier
        // à elle-même.
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation(
            "Ce bien est assuré auprès de NSIA Assurances SA depuis janvier 2024.\nMonsieur EYENGA Paul",
        ));
    }

    public function test_un_vrai_tiers_est_trouve_meme_apres_une_mention_de_nsia(): void
    {
        // Le filtre sur "NSIA" doit chercher la PREMIÈRE correspondance qui
        // n'est PAS Nsia, pas juste rejeter la toute première correspondance
        // trouvée — sinon un véritable expéditeur/tiers mentionné plus loin
        // dans le texte serait ignoré à tort.
        $this->assertSame(
            'Garage Excellence Auto Sarl',
            ProcessDocumentOcr::extraireExpediteurOrganisation(
                'Ce bien est assuré auprès de NSIA Assurances SA depuis janvier 2024. '
                .'Le véhicule a été réparé par Garage Excellence Auto Sarl.',
            ),
        );
    }

    public function test_les_connecteurs_minuscules_ne_tronquent_plus_le_nom_de_lorganisation(): void
    {
        // Bug réel constaté lors de la revue adversariale du 2026-09-04 :
        // sans tolérer "de/du/des/d'/l'" à l'intérieur du nom, seule la
        // portion après le dernier connecteur était capturée (ex. "Centre
        // Sarl" au lieu du nom complet).
        $this->assertSame(
            "Cabinet d'Expertise Immobilière du Centre Sarl",
            ProcessDocumentOcr::extraireExpediteurOrganisation("L'Expert,\nCabinet d'Expertise Immobilière du Centre Sarl"),
        );
    }

    public function test_cabinet_davocats_est_reconnu_malgre_lelision(): void
    {
        // Bug réel constaté lors de la revue adversariale du 2026-09-04 : le
        // motif préfixe "Cabinet" exigeait un mot capitalisé immédiatement
        // après, donc "Cabinet d'Avocats..." (construction très courante
        // pour un cabinet professionnel) ne matchait jamais.
        $this->assertSame(
            'Cabinet d\'Avocats NKOTO',
            ProcessDocumentOcr::extraireExpediteurOrganisation("Cabinet d'Avocats NKOTO & Associés\nAvocats au Barreau du Cameroun"),
        );
    }

    public function test_les_formes_juridiques_anglophones_sont_reconnues(): void
    {
        // Demande explicite de l'utilisateur (2026-09-04) : le Cameroun est
        // officiellement bilingue, les entreprises des régions anglophones
        // (Nord-Ouest/Sud-Ouest) utilisent PLC/Ltd plutôt que SA/Sarl —
        // volontairement hors périmètre au départ, ajouté ici.
        $this->assertSame(
            'Golden Motors Cameroon PLC',
            ProcessDocumentOcr::extraireExpediteurOrganisation("Golden Motors Cameroon PLC\nP.O. Box 331 Buea, South West Region"),
        );
        $this->assertSame(
            'CamTrans Logistics Ltd',
            ProcessDocumentOcr::extraireExpediteurOrganisation('Fourni par CamTrans Logistics Ltd le 5 mars.'),
        );
        $this->assertSame(
            'Douala Freight Co. Ltd',
            ProcessDocumentOcr::extraireExpediteurOrganisation('Livré par Douala Freight Co. Ltd la semaine dernière.'),
        );
    }

    public function test_une_organisation_sans_forme_juridique_est_proposee_pres_dun_bloc_de_coordonnees(): void
    {
        // Demande explicite de l'utilisateur (2026-09-04) : le nom et les
        // coordonnées d'une organisation sont souvent mentionnés ensemble en
        // en-tête ou en pied de page, même sans forme juridique reconnaissable
        // (Sarl/SA/PLC/Ltd…) — dernier motif de repli, le moins fiable des
        // quatre, restreint aux lignes tout en majuscules ou contenant déjà
        // une forme juridique connue.
        $this->assertSame(
            'TRANSCAM VOYAGES',
            ProcessDocumentOcr::extraireExpediteurOrganisation("TRANSCAM VOYAGES\nBP 4521 Douala - Tél: 233 42 10 12"),
        );
        $this->assertSame(
            'GARAGE DÉPANNAGE EXPRESS',
            ProcessDocumentOcr::extraireExpediteurOrganisation("GARAGE DÉPANNAGE EXPRESS\nBP 900 Yaoundé - Tél: 699001122"),
        );
    }

    public function test_une_ligne_en_casse_normale_contenant_juste_le_mot_sarl_nest_plus_renvoyee_en_entier(): void
    {
        // Bug réel constaté le 2026-09-07 sur un vrai document OCR dégradé
        // ("Univsoft SARL") : mise en page à deux colonnes (bloc expéditeur
        // à gauche, "à l'attention de..." à droite) recollée par l'OCR sur
        // UNE seule ligne physique, avec le début du nom illisible ("oft
        // SARL, À l'attention de Monsieur le Directeur Général" — "Univs"
        // perdu). Aucun motif principal (suffixe/préfixe) ne trouve de nom
        // valide nulle part dans le document (ils exigent un mot capitalisé
        // valide immédiatement avant "Sarl", absent ici : "oft" n'en est pas
        // un). Le dernier repli acceptait alors n'importe quelle ligne en
        // casse normale du seul fait qu'elle contient le mot "Sarl", et
        // renvoyait la ligne ENTIÈRE — corrigé : ce repli ne renvoie plus
        // qu'une ligne tout en majuscules (seul cas où aucun motif principal,
        // qui couvre tout le document, n'aurait pu la trouver avant) ; mieux
        // vaut ne rien proposer qu'une valeur fausse.
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation(
            "oft SARL, À l'attention de Monsieur le Directeur Général\nTel: 696458382/673925399",
        ));
    }

    public function test_le_nom_est_repere_meme_a_plusieurs_lignes_du_marqueur(): void
    {
        // Fenêtre élargie à 3 lignes (au lieu d'une seule) le 2026-09-04 :
        // sur un vrai document ("FORMAVISION.COM"), le nom est séparé du
        // marqueur (téléphone/RC) par un slogan et une ligne d'adresse — un
        // simple voisinage direct ne suffisait pas. Ici, les lignes
        // intermédiaires sont en casse normale (pas de faux positif
        // possible), donc la recherche continue jusqu'à trouver le nom.
        $this->assertSame(
            'CAMEROON LOGISTICS EXPRESS',
            ProcessDocumentOcr::extraireExpediteurOrganisation(
                "CAMEROON LOGISTICS EXPRESS\nVotre partenaire transport et logistique\nZone Industrielle Bassa\nRC/YAO/2015/B/2201",
            ),
        );
    }

    public function test_le_marqueur_rc_fonctionne_sans_le_symbole_n_degre(): void
    {
        // Bug réel introduit puis corrigé dans la même session : "N°?" ne
        // rendait optionnel que le symbole degré, pas tout le préfixe "N°"
        // — "RC/YAO/..." sans "N°" devant (fréquent en pratique) ne
        // déclenchait donc jamais ce motif.
        $this->assertSame(
            'GARAGE MODERNE',
            ProcessDocumentOcr::extraireExpediteurOrganisation("GARAGE MODERNE\nRC/YAO/2010/B/1500"),
        );
    }

    public function test_le_marqueur_niu_fonctionne_avec_des_points_entre_les_lettres(): void
    {
        // "N.I.U." (avec points, convention observée sur un vrai document)
        // ne matchait pas le motif "NIU" collé sans points.
        $this->assertSame(
            'COMPTOIR GENERAL DU CENTRE',
            ProcessDocumentOcr::extraireExpediteurOrganisation("COMPTOIR GENERAL DU CENTRE\nN.I.U. : M012345678T"),
        );
    }

    public function test_le_nom_pres_de_la_direction_generale_est_propose(): void
    {
        $this->assertSame(
            'GROUPEMENT DES TRANSPORTEURS DU CENTRE',
            ProcessDocumentOcr::extraireExpediteurOrganisation("Bien à vous,\n\nLa Direction Générale\nGROUPEMENT DES TRANSPORTEURS DU CENTRE"),
        );
    }

    public function test_lorganisation_est_extraite_dune_formule_dautopresentation(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-04, "FORMAVISION.COM") :
        // ni l'en-tête (OCR trop dégradé, "RMA N.COM" pour "FORMAVISION.COM")
        // ni aucun des motifs ci-dessus ne trouvaient l'organisation — mais
        // le corps du texte contient "La société FORMAVISION Cameroun créée
        // en 2007 est une filiale du groupe FORMAVISION International.",
        // formule d'auto-présentation très courante dans un courrier
        // commercial, constatée le 2026-09-04.
        $this->assertSame(
            'TransCam Logistique',
            ProcessDocumentOcr::extraireExpediteurOrganisation('La société TransCam Logistique propose ses services depuis 2010.'),
        );
        $this->assertSame(
            'Bati Plus',
            ProcessDocumentOcr::extraireExpediteurOrganisation("L'entreprise Bati Plus vous remercie de votre confiance."),
        );
        $this->assertSame(
            'Douala Freight',
            ProcessDocumentOcr::extraireExpediteurOrganisation('Le groupe Douala Freight est heureux de vous compter parmi ses clients.'),
        );
        $this->assertSame(
            'Air Cameroun',
            ProcessDocumentOcr::extraireExpediteurOrganisation('La compagnie Air Cameroun dessert cette ligne.'),
        );
    }

    public function test_lorganisation_est_extraite_dune_formule_dautopresentation_a_la_premiere_personne(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-17, "The Best
        // Group (TBG)", TBG/CMR/NSIA) : "Raison sociale :" existe en pied de
        // page mais dans un bandeau clair-sur-sombre illisible par l'OCR
        // ("isles" au lieu de "Raison sociale") — seule la phrase
        // d'auto-présentation à la première personne du pluriel ("Notre
        // firme The Best Group (TBG) est spécialisée dans...") permet de
        // retrouver l'organisation. Variante de la formule à la 3e personne
        // ("La société X…") déjà couverte ci-dessus.
        $this->assertSame(
            'The Best Group',
            ProcessDocumentOcr::extraireExpediteurOrganisation('Notre firme The Best Group est spécialisée dans l\'Ingénierie Informatique.'),
        );
        $this->assertSame(
            'Bati Plus',
            ProcessDocumentOcr::extraireExpediteurOrganisation('Notre société Bati Plus vous remercie de votre confiance.'),
        );
    }

    public function test_lorganisation_est_extraite_du_vrai_texte_ocr_degrade_du_document_formavision(): void
    {
        // Extrait réel du texte OCR (dégradé) du document "FORMAVISION.COM"
        // fourni par l'utilisateur — l'en-tête ("RMA N.COM" pour
        // "FORMAVISION.COM") est illisible par les motifs ci-dessus, seule
        // la phrase d'auto-présentation dans le corps du texte permet de
        // retrouver l'organisation.
        $extraitReel = "RMA N.COM *\nn GADGXII'SSI!IPGH-TEŒ\n\nSis Carrefour Camp\n\nSonel Essos\n1 34 /656 54 11 56\n\n"
            ."Tel : 222 20 09 11/670 915\nRC/YAO/2007/B/4014 NLU. : P037200023062T\n"
            ."Nous vous prions d'accorder une attention particulière à l'étude de notre offre.\n"
            .'La société FORMAVISION Cameroun créée en 2007 est une filiale du groupe FORMAVISION International.';

        $this->assertSame('FORMAVISION Cameroun', ProcessDocumentOcr::extraireExpediteurOrganisation($extraitReel));
    }

    public function test_lauto_presentation_ne_confond_pas_nsia_ni_un_tiers_cite(): void
    {
        // Même garde-fou NSIA que les autres motifs.
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation('Je suis assuré auprès de la société NSIA Assurances depuis 2020.'));

        // Même risque déjà accepté (tiers cité dans le récit d'un
        // assuré) que pour les autres motifs — non traité différemment ici.
        $this->assertSame(
            'Garage Excellence',
            ProcessDocumentOcr::extraireExpediteurOrganisation(
                "Le véhicule a été pris en charge par la société Garage Excellence, qui a établi un devis.\nMonsieur EYENGA Paul",
            ),
        );
    }

    public function test_un_assure_qui_donne_son_numero_personnel_nest_jamais_confondu_avec_une_organisation(): void
    {
        // Garde-fou du motif "bloc de coordonnées" ci-dessus : un assuré qui
        // donne son propre numéro de téléphone signe en casse normale
        // ("Jean Dupont", "Monsieur EYENGA Paul"), jamais tout en majuscules
        // et sans forme juridique — ne doit jamais être proposé comme
        // organisation expéditrice.
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation("Je vous prie de me contacter au besoin.\nJean Dupont\nTél : 6 77 88 99 00"));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation("Cordialement,\nMonsieur EYENGA Paul\nTél: 677889900"));
    }

    // Dernier recours (demande explicite de l'utilisateur, 2026-09-21, vrai
    // document "kpamela@easytechgroup.net") : à défaut de toute forme
    // juridique ou bloc de coordonnées, le nom de domaine d'un email trouvé
    // dans le texte est proposé comme organisation.
    public function test_lorganisation_est_proposee_depuis_le_domaine_dun_email_en_dernier_recours(): void
    {
        $this->assertSame(
            'Easytechgroup',
            ProcessDocumentOcr::extraireExpediteurOrganisation(
                "Bonjour,\nJe vous contacte au sujet de notre dossier.\nCordialement,\nKamga Pamela\nkpamela@easytechgroup.net",
            ),
        );

        // Un motif plus fiable trouvé ailleurs (forme juridique) garde
        // toujours la priorité sur ce repli par email.
        $this->assertSame(
            'ITSC Sarl',
            ProcessDocumentOcr::extraireExpediteurOrganisation('ITSC Sarl vous informe. Contact : compta@itsc-sarl.cm'),
        );

        // Jamais un fournisseur d'email public/générique — ce n'est le nom
        // d'aucune entreprise, ce serait une valeur inventée.
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation(
            'Merci de me répondre à john@gmail.com dès que possible.',
        ));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation(
            'Contact : service.client@yahoo.fr',
        ));

        // Nsia ne s'envoie jamais de courrier à elle-même, même via son
        // propre domaine email (même filtre que premiereOrganisationHorsNsia()).
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation(
            'Message envoyé depuis agent@nsia-assurances.cm, sans autre repère.',
        ));
    }

    public function test_le_telephone_et_ladresse_de_lexpediteur_sont_extraits_de_la_mention_contacts(): void
    {
        // Demande explicite de l'utilisateur (2026-09-04, séparé en
        // téléphone/email/adresse le 2026-09-17) : les coordonnées
        // apparaissent souvent près du nom en en-tête/pied de page, comme sur
        // le vrai document ITSC Sarl/NSIA. Le téléphone s'arrête avant "BP"
        // (lettres, hors du jeton numérique) ; l'adresse retire le label
        // "- Mail:" vide qui suit sur la même ligne (aucune adresse email
        // n'est réellement présente ici).
        //
        // 2026-10-08 — retour utilisateur sur ce même document réel ("let it
        // take one phone number not all") : "/" retiré du jeton capturé, le
        // motif s'arrête désormais au premier numéro ("(00237)658239075")
        // au lieu de traverser le "/" et recoller le second numéro
        // ("675179454") à la suite — UN seul numéro, pas les deux collés.
        $texte = "N° RC/YAO/2019/B/433 - Contribuable : M051912784615T - NIU : M051912784615T\nContacts : (00237)658239075/ 675179454 BP 2138 Yaoundé Cameroun - Mail:";

        $this->assertSame('(00237)658239075', ProcessDocumentOcr::extraireExpediteurTelephone($texte));
        $this->assertSame('BP 2138 Yaoundé Cameroun', ProcessDocumentOcr::extraireExpediteurAdresse($texte));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurEmail($texte));
    }

    public function test_ladresse_tolere_une_virgule_entre_bp_et_le_numero(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-18, "MEGATIM/AFG
        // BANK") : "BP, 4568, Buc Ngousso..." — une virgule entre "BP" et le
        // numéro, que le motif d'origine (qui n'acceptait qu'un espace)
        // manquait.
        $this->assertSame(
            'BP, 4568 Yaoundé Cameroun',
            ProcessDocumentOcr::extraireExpediteurAdresse('Contactez-nous : BP, 4568 Yaoundé Cameroun'),
        );
    }

    public function test_le_telephone_est_extrait_de_la_mention_tel(): void
    {
        $this->assertSame(
            '677889900',
            ProcessDocumentOcr::extraireExpediteurTelephone("Cordialement,\nJean Dupont\nTél: 677889900"),
        );
    }

    public function test_le_telephone_est_extrait_sans_marqueur_via_un_indicatif_africain(): void
    {
        // Demande explicite de l'utilisateur (2026-09-17, "take into
        // consideration those that have +237 as country code... all
        // country code of africa") : un numéro préfixé "+" suivi d'un
        // indicatif téléphonique africain reconnu est un signal fiable en
        // lui-même, même principe que l'email (format seul, sans marqueur).
        // Vrai document ("+237 694 006 485", TBG/CMR/NSIA, 2026-09-17) où
        // les deux autres numéros de l'en-tête sont trop dégradés par l'OCR
        // pour être lisibles, mais celui-ci survit intact, sans aucun
        // marqueur "Tél :"/"Contacts :" à proximité.
        $this->assertSame(
            '+237 694 006 485',
            ProcessDocumentOcr::extraireExpediteurTelephone("info@thebest-group.com\n+237 694 006 485\n\nHQ, Douala Cameroun"),
        );

        // Un autre pays africain (Ghana, +233) — la liste couvre tout le
        // continent, pas seulement le Cameroun de l'exemple ci-dessus.
        $this->assertSame(
            '+233 24 123 4567',
            ProcessDocumentOcr::extraireExpediteurTelephone('Nous sommes joignables au +233 24 123 4567 pour toute question.'),
        );

        // Un indicatif HORS Afrique (+33, France) ne doit jamais matcher —
        // seul le format "+" seul (sans indicatif reconnu) reste trop
        // ambigu pour être accepté sans marqueur explicite.
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurTelephone('Appelez le +33 6 12 34 56 78 pour toute question.'));

        // Un simple numéro nu, sans "+" ni marqueur, reste hors périmètre
        // (pourrait être une référence de dossier, une date...).
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurTelephone('Référence dossier 2379481234, voir pièce jointe.'));
    }

    public function test_lemail_est_extrait_sans_marqueur_explicite(): void
    {
        // Contrairement au téléphone, l'email ne nécessite aucun marqueur
        // ("Email :"...) — vu sur un vrai document en-tête sans label
        // ("info@thebest-group.com", TBG/CMR/NSIA, 2026-09-17).
        $this->assertSame(
            'info@thebest-group.com',
            ProcessDocumentOcr::extraireExpediteurEmail('info@thebest-group.com'),
        );
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurEmail('Aucune adresse email dans ce texte.'));
    }

    public function test_sans_mention_de_contact_les_champs_telephone_email_adresse_restent_vides(): void
    {
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurTelephone('Je soussigné Jean Dupont, déclare avoir été victime...'));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurTelephone(null));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurTelephone(''));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurAdresse('Je soussigné Jean Dupont, déclare avoir été victime...'));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurAdresse(null));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurAdresse(''));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurEmail(null));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurEmail(''));
    }

    public function test_le_rc_est_extrait_du_vrai_document_itsc_sarl(): void
    {
        // Demande explicite de l'utilisateur (2026-09-07).
        $this->assertSame(
            'RC/YAO/2019/B/433',
            ProcessDocumentOcr::extraireExpediteurRc(
                'N° RC/YAO/2019/B/433 - Contribuable : M051912784615T - NIU : M051912784615T',
            ),
        );
    }

    public function test_le_rc_est_extrait_quand_n_degre_suit_rc_au_lieu_de_le_preceder(): void
    {
        // Vrai document fourni par l'utilisateur (2026-09-07, "Ste SAPDIST
        // SARL") : "RC N° : CM-DLA-02-2025-B-00827", "N°" placé APRÈS "RC"
        // plutôt qu'avant (contrairement au document ITSC Sarl ci-dessus).
        $this->assertSame(
            'RC N° : CM-DLA-02-2025-B-00827',
            ProcessDocumentOcr::extraireExpediteurRc('Sté au Capital de 990 000 FCFA RC N° : CM-DLA-02-2025-B-00827'),
        );
    }

    public function test_le_rc_ne_colle_pas_le_mot_suivant_sans_espace(): void
    {
        // Vrai document fourni par l'utilisateur (EASYTECH GROUP SA,
        // 2026-09-22) : l'OCR colle "Contr." (début de "Contribuable")
        // directement après le numéro sans espace — "3204Contr." — le
        // numéro RC réel se termine toujours par le numéro de séquence
        // (un chiffre), jamais par une lettre : la capture doit s'arrêter
        // au dernier chiffre, sans avaler le mot suivant.
        $this->assertSame(
            'RC/DLA/2020/B/3204',
            ProcessDocumentOcr::extraireExpediteurRc(
                "EASYTECH GROUP S.A avec CA Capital social 50 000 000 FCFA, RCCM N°\nRC/DLA/2020/B/3204Contr. N° M062015196381P",
            ),
        );
    }

    public function test_sans_mention_rc_le_champ_reste_vide(): void
    {
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurRc('Je soussigné Jean Dupont, déclare avoir été victime...'));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurRc(null));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurRc(''));
    }

    public function test_le_niu_est_extrait_de_la_mention_niu_ou_contribuable(): void
    {
        $this->assertSame(
            'M051912784615T',
            ProcessDocumentOcr::extraireExpediteurNiu(
                'N° RC/YAO/2019/B/433 - Contribuable : M051912784615T - NIU : M051912784615T',
            ),
        );
        $this->assertSame('P012345678A', ProcessDocumentOcr::extraireExpediteurNiu('Contribuable : P012345678A'));
    }

    public function test_le_niu_tolere_la_confusion_ocr_i_l_sur_le_vrai_document_formavision(): void
    {
        // Vrai texte OCR (dégradé) du document FORMAVISION.COM : l'OCR
        // confond systématiquement "I" et "L" à cet endroit précis
        // ("NLU." et "N.U.L" pour "N.I.U."/"N.U.I."), à deux endroits
        // différents du même document — tolérance étroite, pas une
        // correction OCR générale.
        $this->assertSame('P037200023062T', ProcessDocumentOcr::extraireExpediteurNiu(
            'RC/YAO/2007/B/4014 NLU. : P037200023062T',
        ));
        $this->assertSame('P037200023062T', ProcessDocumentOcr::extraireExpediteurNiu(
            'N.U.L: P037200023062T',
        ));
    }

    public function test_le_niu_est_extrait_sans_label_quand_il_est_juste_a_cote_du_rc(): void
    {
        // Demande explicite de l'utilisateur (2026-09-18, "it always start
        // either with P or M is always beside RC") : sans AUCUNE étiquette
        // "NIU"/"Contribuable", un NIU camerounais commence systématiquement
        // par "P" ou "M" suivi d'une longue suite de chiffres, et se trouve
        // quasi toujours immédiatement à côté du RC sur la même ligne — vu
        // sur un vrai document en pied de page sans aucune étiquette,
        // juste "RC/DLA/2019/B/942 | M021912751106K" (TBG/CMR/NSIA,
        // 2026-09-17 — l'OCR réel de CE document précis dégrade
        // malheureusement ce numéro au point de le rendre illisible, limite
        // déjà documentée dans le journal de développement ; ce test reproduit la
        // même convention avec un OCR propre pour vérifier le motif
        // lui-même indépendamment de ce problème de qualité de scan).
        $this->assertSame(
            'M021912751106K',
            ProcessDocumentOcr::extraireExpediteurNiu("GARAGE MODERNE\nRC/DLA/2019/B/942 | M021912751106K"),
        );

        // Fonctionne aussi sans séparateur "|" entre RC et NIU, et avec le
        // préfixe "P".
        $this->assertSame(
            'P123456789012K',
            ProcessDocumentOcr::extraireExpediteurNiu('RC/DLA/2019/B/942 P123456789012K'),
        );

        // Un mot français ordinaire commençant par "M"/"P" juste après le RC
        // (pas un identifiant) ne doit JAMAIS être proposé comme NIU — exige
        // au moins 6 chiffres après la lettre, qu'aucun mot n'a.
        $this->assertNull(
            ProcessDocumentOcr::extraireExpediteurNiu('RC/YAO/2019/B/433 Monsieur Jean vous informe...'),
        );

        // Un RC seul, sans rien après, ne doit pas non plus en inventer un.
        $this->assertNull(
            ProcessDocumentOcr::extraireExpediteurNiu("GARAGE MODERNE\nRC/YAO/2010/B/1500"),
        );
    }

    public function test_le_niu_est_extrait_quand_etiquete_n_degre_au_lieu_de_niu(): void
    {
        // Demande explicite de l'utilisateur (2026-09-21) : certains
        // documents réels notent le NIU sous "N° :" sans jamais écrire
        // "NIU" ni "Contribuable" — exige la forme NIU (P/M + chiffres)
        // pour ne jamais confondre avec un "N°" de téléphone/adresse/RC.
        $this->assertSame(
            'P012345678A',
            ProcessDocumentOcr::extraireExpediteurNiu('N° : P012345678A'),
        );
        $this->assertSame(
            'M051912784615T',
            ProcessDocumentOcr::extraireExpediteurNiu('N° M051912784615T'),
        );

        // "N°" suivi d'un RC (pas d'une forme NIU) ne doit jamais être
        // confondu avec ce nouveau repli.
        $this->assertNull(
            ProcessDocumentOcr::extraireExpediteurNiu('N° RC/YAO/2019/B/433'),
        );
    }

    public function test_le_niu_est_extrait_quand_etiquete_cont_n_degre(): void
    {
        // Vrai document EASYTECH GROUP SA (2026-09-22) : l'OCR colle "Contr."
        // (abrégé de "Contribuable") directement après le numéro RC, suivi
        // de "N°" puis du NIU — "...3204Contr. N° M062015196381P". Étiquette
        // explicite (comme "Contribuable" en toutes lettres) : accepte
        // n'importe quelle valeur plausible, pas seulement la forme P/M.
        $this->assertSame(
            'M062015196381P',
            ProcessDocumentOcr::extraireExpediteurNiu(
                "EASYTECH GROUP S.A avec CA Capital social 50 000 000 FCFA, RCCM N°\nRC/DLA/2020/B/3204Contr. N° M062015196381P",
            ),
        );

        // Variantes d'abréviation/casse tolérées ("Cont" sans le "r", tout
        // en majuscules).
        $this->assertSame('P012345678A', ProcessDocumentOcr::extraireExpediteurNiu('Cont. N° : P012345678A'));
        $this->assertSame('M051912784615T', ProcessDocumentOcr::extraireExpediteurNiu('CONTR N° M051912784615T'));
    }

    public function test_le_niu_est_extrait_quand_etiquete_n_degre_cont_dans_lordre_inverse(): void
    {
        // Second vrai document fourni par l'utilisateur (NOW TECHNOLOGIES
        // CENTER Sarl, 2026-09-22, "for the niu also add if he find this
        // too N° cont.") — ordre INVERSÉ par rapport au document EASYTECH
        // GROUP SA ci-dessus : "N°" précède "cont." au lieu de le suivre —
        // "RC/DLA/2018/B/2407 N° cont. MOQ71812712493".
        $this->assertSame(
            'MOQ71812712493',
            ProcessDocumentOcr::extraireExpediteurNiu('RC/DLA/2018/B/2407 N° cont. MOQ71812712493'),
        );
        $this->assertSame('P012345678A', ProcessDocumentOcr::extraireExpediteurNiu('N° Cont. : P012345678A'));
        $this->assertSame('M051912784615T', ProcessDocumentOcr::extraireExpediteurNiu('N° CONTR M051912784615T'));
    }

    public function test_sans_mention_niu_le_champ_reste_vide(): void
    {
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurNiu('Je soussigné Jean Dupont, déclare avoir été victime...'));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurNiu(null));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurNiu(''));
    }

    public function test_le_nom_de_lexpediteur_est_extrait_de_je_soussigne(): void
    {
        // Convention administrative française bien établie pour qu'un
        // déclarant individuel (assuré rédigeant une réclamation) s'identifie
        // lui-même sans ambiguïté — demande explicite de l'utilisateur
        // (2026-09-04, voir DECISIONS.md).
        $this->assertSame('Jean Dupont', ProcessDocumentOcr::extraireExpediteurNom('Je soussigné Jean Dupont, déclare avoir été victime...'));
        $this->assertSame('Marie Curie', ProcessDocumentOcr::extraireExpediteurNom('Je soussignée Marie Curie, née le...'));
        $this->assertSame('Paul Biya', ProcessDocumentOcr::extraireExpediteurNom('Je soussigné, Monsieur Paul Biya, demeurant...'));
    }

    public function test_le_nom_de_lexpediteur_est_extrait_dun_marqueur_de_signature(): void
    {
        $this->assertSame('Jean Dupont', ProcessDocumentOcr::extraireExpediteurNom("Cordialement,\n\nSigné : Jean Dupont"));
        $this->assertSame('Marie Curie', ProcessDocumentOcr::extraireExpediteurNom("Bien à vous,\nSignature : Marie Curie"));
    }

    public function test_une_simple_civilite_en_signature_ne_propose_pas_de_nom(): void
    {
        // Contrairement à "Je soussigné(e)"/"Signé :", une civilité seule en
        // signature ("Monsieur EYENGA Paul") n'a pas de marqueur fixe fiable
        // — volontairement hors périmètre, même raisonnement que celui qui
        // avait initialement exclu tout le champ.
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurNom("Cordialement,\nMonsieur EYENGA Paul\nTél: 677889900"));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurNom(null));
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurNom(''));
    }

    public function test_le_mode_de_reception_email_est_detecte_via_len_tete_de(): void
    {
        // Signal spécifique : "De :"/"From :" DIRECTEMENT suivi d'une adresse
        // mail — un simple "Email : ..." de contact dans une signature ne
        // suffit pas seul (voir test suivant).
        $this->assertSame('email', ProcessDocumentOcr::extraireModeReception("De : jean.dupont@gmail.com\nEnvoyé : lundi 3 mars\nObjet : Réclamation"));
        $this->assertSame('email', ProcessDocumentOcr::extraireModeReception('From: paul@example.cm'));
    }

    public function test_une_simple_adresse_mail_en_signature_ne_suffit_pas_pour_le_mode_de_reception(): void
    {
        $this->assertNull(ProcessDocumentOcr::extraireModeReception("Cordialement,\nJean Dupont\nEmail: jean@gmail.com"));
    }

    public function test_le_mode_de_reception_fax_est_detecte_via_le_bandeau_de_telecopie(): void
    {
        $this->assertSame('fax', ProcessDocumentOcr::extraireModeReception('04/09/2026 14:32 FAX +237 6XX XX XX XX P.001/003'));
        $this->assertSame('fax', ProcessDocumentOcr::extraireModeReception('TELECOPIE Page 1/2'));
    }

    public function test_un_simple_numero_de_fax_en_en_tete_ne_suffit_pas_pour_le_mode_de_reception(): void
    {
        // "Tél/Fax : ..." dans un en-tête est juste un moyen de contact parmi
        // d'autres, pas une preuve que CE courrier est arrivé par fax.
        $this->assertNull(ProcessDocumentOcr::extraireModeReception("ITSC Sarl\nTél/Fax : 222 20 09 11"));
        $this->assertNull(ProcessDocumentOcr::extraireModeReception("Yaoundé, le 07 Janvier 2026\n\nMonsieur le Directeur Général,"));
        $this->assertNull(ProcessDocumentOcr::extraireModeReception(null));
        $this->assertNull(ProcessDocumentOcr::extraireModeReception(''));
    }

    public function test_une_administration_sans_bloc_de_coordonnees_ni_forme_juridique_reste_une_limite_assumee(): void
    {
        // Limite documentée dans DECISIONS.md, volontairement non résolue :
        // sans bloc de coordonnées (BP/Tél/Contacts) ni forme juridique dans
        // le texte, une administration reste non détectée plutôt que de
        // deviner à partir de mots-clés institutionnels non vérifiés sur un
        // vrai document (RÉPUBLIQUE DU/MINISTÈRE…).
        $this->assertNull(ProcessDocumentOcr::extraireExpediteurOrganisation(
            "RÉPUBLIQUE DU CAMEROUN\nMINISTÈRE DES FINANCES\n\nMonsieur Le Directeur Général,\nconformément aux dispositions du Code CIMA.",
        ));
    }

    public function test_une_sortie_vide_de_tesseract_est_une_absence_de_texte_pas_une_panne(): void
    {
        $sortieVide = new RuntimeException("Error! The command did not produce any output.\n\nReturned message:\n\nEstimating resolution as 316");
        $pdfNonLisible = new RuntimeException("Error! The command did not produce any output.\n\nError in pixReadStream: Pdf reading is not supported\nError during processing.");
        $binaireAbsent = new RuntimeException('tesseract not found');

        $this->assertTrue(ProcessDocumentOcr::estUneAbsenceDeTexte($sortieVide));
        $this->assertFalse(ProcessDocumentOcr::estUneAbsenceDeTexte($pdfNonLisible));
        $this->assertFalse(ProcessDocumentOcr::estUneAbsenceDeTexte($binaireAbsent));
    }

    public function test_une_configuration_tessdata_incomplete_est_une_panne_expliquee(): void
    {
        // Cas réel : configs/ absent du dossier tessdata → Tesseract ignore `tsv`,
        // le wrapper ne trouve pas de .tsv. Ne doit PAS passer pour "aucun texte".
        $configManquante = new RuntimeException("Error! The command did not produce any output.\n\nReturned message:\n\nread_params_file: Can't open tsv\nEstimating resolution as 316");

        $this->assertFalse(ProcessDocumentOcr::estUneAbsenceDeTexte($configManquante));
        $this->assertStringContainsString('configs/', ProcessDocumentOcr::messageLisible($configManquante));
        $this->assertStringContainsString('pas en cause', ProcessDocumentOcr::messageLisible($configManquante));
    }

    public function test_un_echec_definitif_marque_le_courrier_trace_un_message_lisible_et_notifie(): void
    {
        Event::fake([OcrTermine::class]);

        $courrier = $this->courrierScanne();

        (new ProcessDocumentOcr($courrier))->failed(
            new RuntimeException("Error! The command did not produce any output.\nError in pixReadStream: Pdf reading is not supported")
        );

        $this->assertSame('echec', $courrier->refresh()->ocr_statut);

        $entree = CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'ocr_echec')->firstOrFail();

        $this->assertStringContainsString('Ghostscript', $entree->commentaire);
        $this->assertStringNotContainsString('pixReadStream', $entree->commentaire);

        Event::assertDispatched(OcrTermine::class, fn (OcrTermine $e) => $e->courrierId === $courrier->id && $e->statut === 'echec');
    }

    public function test_un_echec_inconnu_recoit_un_message_generique(): void
    {
        $courrier = $this->courrierScanne();

        (new ProcessDocumentOcr($courrier))->failed(new RuntimeException('Segmentation fault'));

        $entree = CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'ocr_echec')->firstOrFail();

        $this->assertStringContainsString('relancer la numérisation', $entree->commentaire);
        $this->assertStringNotContainsString('Segmentation', $entree->commentaire);
    }
}
