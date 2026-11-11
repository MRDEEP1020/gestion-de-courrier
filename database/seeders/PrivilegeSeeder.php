<?php

namespace Database\Seeders;

use App\Models\Privilege;
use App\Models\Profil;
use Illuminate\Database\Seeder;

// Système de privilèges (2026-09-15, voir DECISIONS.md "Système de
// privilèges") : catalogue initial + assignations par défaut. Les profils
// listés ci-dessous reproduisent le comportement qui existait avant le
// retrofit des Policies (CourrierPolicy/CourrierBrouillonPolicy/
// RegleClassementPolicy) — SAUF Administrateur, ajouté automatiquement à
// TOUS les privilèges (demande explicite de l'utilisateur, 2026-09-15 :
// "admin should have all the privileges") : contrairement à la version
// initiale de cette entrée, Administrateur récupère désormais aussi
// `courriers.archiver`, changement de comportement assumé (l'archivage
// manuel n'est de toute façon pas encore implémenté, voir CourrierPolicy::archive()).
class PrivilegeSeeder extends Seeder
{
    public function run(): void
    {
        // cle => [nom, description, type (Lecture/Écriture/Administratif —
        // 2026-09-21, modale "Gestion des permissions" de la page
        // "Utilisateurs & Accès" reconstruite depuis la maquette fournie par
        // l'utilisateur ; voir Privilege::MODULES), [noms d'AUTRES profils
        // assignés par défaut — Administrateur est ajouté automatiquement à
        // tous, pas la peine de le répéter ici]]
        $catalogue = [
            'courriers.creer' => ['Créer un courrier', 'Enregistrer un nouveau courrier entrant ou sortant.', 'ecriture', ['Agent']],
            'courriers.voir_tout' => ['Voir tous les courriers', 'Consulter n\'importe quel courrier, sans restriction de service ou de créateur.', 'lecture', []],
            'courriers.voir_service' => ['Voir les courriers de son service', 'Consulter les courriers du service dont on est responsable.', 'lecture', ['Responsable de service', 'DG']],
            'courriers.voir_propre' => ['Voir ses propres courriers', 'Consulter les courriers qu\'on a soi-même enregistrés.', 'lecture', ['Agent']],
            'courriers.voir_affecte' => ['Voir les courriers qui lui sont affectés', 'Consulter les courriers actuellement affectés à soi-même.', 'lecture', ['Collaborateur']],
            'courriers.modifier_tout' => ['Modifier tous les courriers', 'Corriger n\'importe quel enregistrement de courrier.', 'ecriture', []],
            'courriers.modifier_propre' => ['Modifier ses propres courriers', 'Corriger les courriers qu\'on a soi-même enregistrés.', 'ecriture', ['Agent']],
            'courriers.transferer_tout' => ['Transférer tous les courriers', 'Transférer n\'importe quel courrier entrant en attente vers le service concerné.', 'ecriture', []],
            'courriers.transferer_propre' => ['Transférer ses propres courriers', 'Transférer vers le service concerné les courriers entrants qu\'on a soi-même enregistrés.', 'ecriture', ['Agent']],
            // 2026-10-23 (voir DECISIONS.md "Transfert direct au service et
            // acceptation") — le responsable du service destinataire accepte
            // (décharge numérotée) ou refuse (motif) ; l'accueil marque ensuite
            // l'original papier comme remis.
            // 2026-10-26 (voir DECISIONS.md "Titres des chefs et Direction
            // Générale") — le DG dirige la racine de l'organigramme, qui peut
            // recevoir un courrier : comme tout chef d'entité, il l'accepte,
            // le transmet, l'affecte, le suit et valide (DGA ajouté par
            // défaut à ces 8 privilèges ; profil « DG », ex-« DGA »).
            'courriers.accepter_transfert' => ['Accepter ou refuser un courrier transféré', 'Accepter (décharge numérotée) ou refuser avec un motif un courrier transféré à son service — son contenu reste masqué tant qu\'il n\'est pas accepté.', 'ecriture', ['Responsable de service', 'DG']],
            // 2026-10-23 (voir DECISIONS.md "Transmission descendante par la
            // Direction") — le responsable d'une entité (ex. une Direction)
            // transmet un courrier accepté à l'une de ses unités.
            'courriers.transmettre' => ['Transmettre un courrier à une unité rattachée', 'Transmettre un courrier accepté par son entité (ex. une Direction) à l\'une des unités placées directement sous elle, dont le chef le reçoit « À affecter ».', 'ecriture', ['Responsable de service', 'DG']],
            'courriers.marquer_original_remis' => ['Marquer l\'original papier comme remis', 'Indiquer que l\'original papier d\'un courrier accepté par son service lui a bien été remis.', 'ecriture', ['Agent']],
            'courriers.archiver' => ['Archiver un courrier', 'Archivage manuel (Module 9 — fonctionnalité pas encore implémentée, ce privilège ne fait donc rien pour l\'instant, même pour Administrateur).', 'ecriture', []],
            // Module 9 — "Décharge" (2026-10-05) : reçu d'emprunt d'un
            // original physique archivé. Responsable de service par défaut
            // (gère déjà l'archivage/la traçabilité de son service), en plus
            // d'Administrateur.
            'courriers.emettre_decharge' => ['Émettre une décharge', 'Enregistrer l\'emprunt (et le retour) de l\'original physique d\'un courrier archivé.', 'ecriture', ['Responsable de service']],
            'courriers.affecter_tout' => ['Affecter tous les courriers', 'Affecter/réaffecter n\'importe quel courrier à un collaborateur.', 'ecriture', []],
            'courriers.affecter_service' => ['Affecter les courriers de son service', 'Affecter/réaffecter les courriers du service dont on est responsable.', 'ecriture', ['Responsable de service', 'DG']],
            'courriers.traiter_tout' => ['Traiter tous les courriers', 'Démarrer le traitement de n\'importe quel courrier.', 'ecriture', []],
            // "Responsable de service" ajouté le 2026-10-13 (demande
            // explicite de l'utilisateur, "can a responsable traite his own
            // courier" puis "yes") — jusque-là accordé seulement à la main
            // sur la vraie base via la page Profils ; ajouté ici pour que ce
            // soit le comportement par défaut d'une nouvelle installation
            // aussi, cohérent avec courriers.soumettre_validation ci-dessous
            // (même raison : sans les deux ensemble, "Traiter moi-même" ne
            // sert à rien, le cycle reste bloqué juste après démarré).
            'courriers.traiter_affecte' => ['Traiter les courriers qui lui sont affectés', 'Démarrer le traitement des courriers affectés à soi-même.', 'ecriture', ['Collaborateur', 'Responsable de service']],
            'courriers.valider_tout' => ['Valider tous les courriers', 'Valider/renvoyer/rejeter n\'importe quel courrier.', 'ecriture', []],
            'courriers.valider_service' => ['Valider les courriers de son service', 'Valider/renvoyer/rejeter les courriers du service dont on est responsable.', 'ecriture', ['Responsable de service', 'DG']],
            // 2026-10-09 — extension du circuit générique (entretien terrain
            // Sinistres, voir DECISIONS.md "Signature DG / Trésorerie") : un
            // courrier peut avoir besoin de la signature DG avant clôture
            // (ex. une facture), parfois suivi d'un passage en trésorerie
            // (décaissement) — jamais un circuit Sinistres à part, une
            // extension opt-in de la MÊME machine à états (voir
            // WorkflowService). Aucun profil par défaut pour le premier
            // privilège (hors Administrateur) : à assigner explicitement au
            // département qui en a besoin, depuis Utilisateurs & Accès/Profils.
            'courriers.envoyer_signature_dg' => ['Envoyer un courrier en signature DG', 'Envoyer un courrier validé en signature DG avant clôture, au lieu de clôturer directement (ex. une facture).', 'ecriture', []],
            'courriers.dga_signer' => ['Signer un courrier (DG)', 'Signer un courrier reçu en attente de signature DG — clôturer directement, ou transmettre à la trésorerie pour un courrier de décaissement.', 'ecriture', ['DG']],
            'courriers.traiter_tresorerie' => ['Traiter un courrier en trésorerie', 'Clôturer un courrier de décaissement une fois transmis par la DG à la trésorerie.', 'ecriture', []],
            'courriers.rechercher' => ['Rechercher des courriers', 'Accéder à la recherche multi-critères (Module 8).', 'lecture', ['Responsable de service', 'Agent', 'Collaborateur', 'DG']],
            // 2026-10-30 (« for the agent only, they don't need to see Tous les
            // courriers, only Mes courriers at that place ») — entrée de menu
            // séparée de la recherche : l'Agent garde la recherche de l'en-tête
            // (qui ouvre la même page) sans l'entrée de menu.
            'courriers.menu_tous_les_courriers' => ['Voir « Tous les courriers » dans le menu', 'Afficher l\'entrée « Tous les courriers » dans le menu. La recherche de l\'en-tête (Ctrl+K) dépend de « Rechercher des courriers ».', 'lecture', ['Responsable de service', 'Collaborateur', 'DG']],
            // 2026-10-08 — retour utilisateur direct (capture d'écran de
            // l'en-tête "Tous les courriers" annotée "priveliges") : mêmes
            // boutons "Importer"/"Exporter" désactivés sans fonctionnalité
            // réelle que "Répondre"/"Créer une note" plus haut, eux aussi
            // jusqu'ici sans aucun privilège. Mêmes profils par défaut que
            // courriers.rechercher, dans le même en-tête de page.
            'courriers.importer' => ['Importer des courriers', 'Afficher le bouton "Importer" (en-tête de "Tous les courriers") — fonctionnalité à venir, aucun import de masse n\'existe encore.', 'ecriture', ['Responsable de service', 'Agent', 'Collaborateur', 'DG']],
            'courriers.exporter' => ['Exporter des courriers', 'Afficher le bouton "Exporter" (en-tête de "Tous les courriers") — fonctionnalité à venir, aucun export n\'existe encore (voir PRD.md, "export personnalisé" explicitement hors périmètre phase 1).', 'ecriture', ['Responsable de service', 'Agent', 'Collaborateur', 'DG']],
            'courriers.calendrier' => ['Voir le calendrier des échéances', 'Accéder à la vue calendrier des échéances SLA (Module 5/8).', 'lecture', ['Responsable de service', 'Agent', 'Collaborateur', 'DG']],
            'brouillons.utiliser_tout' => ['Utiliser tous les brouillons scannés', 'Reprendre/finaliser n\'importe quel document scanné en attente, pas seulement les siens.', 'ecriture', []],
            'regles_classement.gerer' => ['Gérer les règles de classement', 'Créer/modifier/supprimer les règles de classement automatique (Module 3).', 'administratif', []],
            // Configuration administrateur — listes de référence
            // (specifications-modules-GEC.md, "transversal") : la liste des
            // services/directions doit être gérable par l'Administrateur
            // sans intervention développeur, au même titre que les règles de
            // classement ci-dessus. Ajouté le 2026-09-22, demande explicite
            // de l'utilisateur ("where can i add services").
            'services.gerer' => ['Gérer les services', 'Créer/modifier/désactiver les services et directions (liste de référence, Module 1/3/6).', 'administratif', []],
            // Module 3 — "Dossiers de classement créés par service" (specifications-modules-GEC.md) :
            // le chef de service OU ses collaborateurs (pas seulement un
            // administrateur) peuvent créer leurs propres dossiers, en plus
            // de l'arborescence automatique service/type/période. L'accès à
            // UN dossier précis (qui peut le voir) est ensuite défini par
            // son créateur, dossier par dossier — ce n'est PAS un privilège
            // (une donnée assignée par dossier, pas par profil/utilisateur
            // global, voir DossierClassement/dossier_classement_user à
            // venir) : seule la capacité de CRÉER, et le garde-fou
            // administrateur pour gérer N'IMPORTE QUEL dossier (même esprit
            // que courriers.modifier_tout vs modifier_propre), sont des
            // privilèges.
            'dossiers_classement.creer' => ['Créer un dossier de classement', 'Créer un dossier de classement personnalisé au sein de son service, en plus de l\'arborescence automatique (Module 3).', 'ecriture', ['Responsable de service', 'Collaborateur']],
            'dossiers_classement.gerer_tout' => ['Gérer tous les dossiers de classement', 'Voir, renommer, gérer les droits d\'accès et supprimer n\'importe quel dossier de classement, pas seulement ceux qu\'on a soi-même créés.', 'administratif', []],
            'privileges.gerer' => ['Gérer les privilèges', 'Assigner les privilèges existants à un profil ou à un utilisateur (le catalogue lui-même est fixe depuis le 2026-09-21).', 'administratif', []],
            // Module 10 — tableau de bord (demande explicite de l'utilisateur,
            // 2026-09-16, maquette GPT) : "Tâches du jour" (aperçu de la file
            // d'attente du jour) n'est pas affiché à tout le monde — "mainly
            // for collaborateur ... sometimes responsable service". Par
            // défaut Collaborateur (le cas principal) ; un Responsable de
            // service qui en a besoin le reçoit individuellement via
            // /admin/utilisateurs (le cas "sometimes"), pas un défaut de profil.
            'dashboard.taches_du_jour' => ['Voir les tâches du jour', 'Afficher l\'aperçu de la file d\'attente du jour ("Tâches du jour") sur le tableau de bord.', 'lecture', ['Collaborateur']],
            // Module "Organisation" v2 (2026-09-22, spec technique complète
            // fournie par l'utilisateur, §18) — hiérarchie dynamique Company/
            // Site/Department/Service/Sub-service + rattachement utilisateur.
            // 'manage_structure' court-circuite create/update/deactivate (voir
            // OrganizationUnitPolicy) — clés séparées gardées pour une
            // délégation plus fine si besoin plus tard (non exploitée
            // aujourd'hui : seul Administrateur reçoit tout par défaut, comme
            // toujours).
            'organisation.view' => ['Voir l\'organisation', 'Consulter la structure organisationnelle (sites, départements, services, sous-services).', 'lecture', []],
            'organisation.create' => ['Créer une entité organisationnelle', 'Ajouter un site, un département, un service ou un sous-service.', 'ecriture', []],
            'organisation.update' => ['Modifier une entité organisationnelle', 'Renommer, déplacer ou modifier les informations d\'une entité existante.', 'ecriture', []],
            'organisation.deactivate' => ['Activer/désactiver une entité organisationnelle', 'Basculer le statut actif/inactif — aucune suppression physique n\'est possible (spec Organisation).', 'ecriture', []],
            'organisation.manage_users' => ['Gérer les utilisateurs d\'une entité organisationnelle', 'Rattacher/retirer un utilisateur, changer son rôle au sein d\'une entité.', 'ecriture', []],
            'organisation.manage_structure' => ['Gérer toute la structure organisationnelle', 'Équivalent à create+update+deactivate combinés, pour une délégation globale sans devoir assigner les 3 clés séparément.', 'administratif', []],
            // Menus et sous-menus (2026-09-23, demande explicite de
            // l'utilisateur : "all in sidebar should be permission even the
            // submenu and menu") — chaque entrée de la sidebar est désormais
            // gouvernée par un privilège, voir DECISIONS.md "Menus pilotés
            // par privilège". Règle de défaut : chaque nouvelle clé est
            // donnée EXACTEMENT aux profils qui voyaient déjà l'entrée, pour
            // ne rien retirer ni ajouter à personne ; seules les pages "à
            // venir" reçoivent un défaut de gestion (administration/direction).
            'dashboard.voir' => ['Voir le tableau de bord', 'Accéder au tableau de bord (page d\'accueil après connexion).', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.numeriser' => ['Numériser un courrier', 'Accéder à "Numérisation & OCR" (scan d\'abord, dossier surveillé) et re-numériser un courrier existant.', 'ecriture', ['Agent']],
            'courriers.creer_confidentiel' => ['Enregistrer un courrier confidentiel', 'Enregistrer un pli confidentiel sans l\'ouvrir ni le scanner (nom sur l\'enveloppe uniquement).', 'ecriture', ['Agent']],
            'dossiers_classement.archives' => ['Consulter les archives', 'Afficher l\'entrée "Archives" (courriers archivés) dans Dossiers & Archives.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'utilisateurs.gerer' => ['Gérer les comptes utilisateurs', 'Accéder à "Utilisateurs & Accès" : coordonnées, activation, réinitialisation du mot de passe, destinataires de transfert. Créer un compte ou changer profil, niveau, périmètre et permissions exige EN PLUS "Gérer les privilèges".', 'administratif', []],
            'statistiques.consulter' => ['Consulter les statistiques', 'Accéder à "Dashboard statistiques" : volumes, délais et retards des courriers, limités à son périmètre de services.', 'lecture', ['DG', 'Responsable de service']],
            'statistiques.rapports' => ['Consulter les rapports', 'Accéder à "Rapports" (page à venir).', 'lecture', ['DG', 'Responsable de service']],
            'administration.automatisation' => ['Gérer l\'automatisation', 'Accéder à "Automatisation" (page à venir).', 'administratif', []],
            'administration.workflows' => ['Gérer les workflows', 'Accéder à "Workflows" (page à venir).', 'administratif', []],
            // Description tenue à jour le 2026-10-08 : cette page n'est
            // PLUS un placeholder depuis le 2026-09-23 ("Paramètres
            // système", voir DECISIONS.md) — le texte "page à venir" était
            // resté périmé dans le catalogue malgré la page réelle.
            'administration.sla' => ['Gérer les paramètres système', 'Accéder à "Paramètres système" (numéro de référence, délais SLA par type, listes de référence).', 'administratif', []],
            // 2026-09-24 — page "Dossier surveillé" (configuration de l'import
            // automatique, déplacée depuis Numérisation) : Administrateur seul
            // par défaut, comme les autres pages d'administration.
            'administration.dossier_surveille' => ['Configurer le dossier surveillé', 'Accéder à "Dossier surveillé" : choisir le dossier de scan d\'un poste, démarrer ou arrêter l\'import automatique.', 'administratif', []],
            'administration.audit' => ['Consulter la sécurité et l\'audit', 'Accéder à "Sécurité & Audit" : journal des consultations, intégrité des fichiers, conservation des archives, état du planificateur et de la sauvegarde.', 'administratif', []],
            // 2026-10-30 — conformité de l'archivage (Module 9, voir DECISIONS.md).
            'courriers.voir_consultations' => ['Voir le journal des consultations', 'Voir qui a ouvert la fiche ou consulté les documents d\'un courrier, et quand.', 'lecture', []],
            'archives.gerer_conservation' => ['Gérer la conservation des archives', 'Fixer les durées de conservation, prolonger, placer sous gel juridique et détruire (avec motif) les archives arrivées à échéance.', 'administratif', []],
            'general.notifications' => ['Voir les notifications', 'Afficher le menu "Notifications" et la cloche de la barre supérieure.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            // 2026-11-10 (« the notif for mail and app only the admin can see
            // and modify it ») — Administrateur seul par défaut.
            'general.choisir_canaux_notification' => ['Choisir ses canaux de notification', 'Afficher et modifier ses interrupteurs E-mail / Application (Paramètres › Notifications). Sans ce privilège, les canaux suivent les réglages de l\'administrateur (Paramètres système) ; le rythme des rappels de courrier en retard reste réglable par tous.', 'ecriture', []],
            // Actions sur un courrier séparées de la simple consultation
            // (2026-09-23, "all of them") — mêmes défauts qu'avant (tout
            // profil pouvant consulter), sauf la suppression, nouvelle.
            // courriers.telecharger retiré le 2026-11-09 (voir plus bas).
            'courriers.imprimer_bordereau' => ['Imprimer le bordereau', 'Générer le bordereau PDF d\'un courrier qu\'on peut consulter.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.supprimer' => ['Supprimer un courrier', 'Suppression logique (jamais physique, Règle n°5) avec motif obligatoire, tracée dans l\'historique ; impossible sur un courrier archivé.', 'administratif', []],
            // "Chaque action / lecture = un privilège" (2026-09-23, demande
            // explicite de l'utilisateur). Les privilèges de PORTÉE existants
            // (…_tout / …_service / …_propre / …_affecte) restent ; ceux-ci
            // s'y AJOUTENT, un par action ou lecture distincte. Défaut =
            // exactement les profils qui pouvaient déjà le faire.
            // — Circuit de traitement (fiche courrier)
            // 2026-09-24 — chronomètre de traitement : délai fixé à
            // l'affectation, modifiable ensuite avec motif.
            'courriers.fixer_delai' => ['Fixer le délai de traitement', 'Définir ou modifier (motif obligatoire) le délai du chronomètre de traitement d\'un courrier, dans sa portée d\'affectation.', 'ecriture', ['Responsable de service']],
            'courriers.reaffecter' => ['Réaffecter un courrier', 'Changer le collaborateur affecté (motif obligatoire), dans sa portée d\'affectation.', 'ecriture', ['Responsable de service', 'DG']],
            // 2026-10-13, demande explicite de l'utilisateur (suite directe
            // de "can a responsable traite his own courier") — un
            // responsable qui s'affecte un courrier à lui-même (voir
            // courriers.traiter_affecte plus bas) doit aussi pouvoir
            // soumettre SA PROPRE réponse pour validation, sinon le cycle
            // reste bloqué juste après "Démarrer le traitement" : bouton
            // "Soumettre pour validation" absent (retour utilisateur,
            // capture d'écran de l'onglet "Circuit de traitement").
            'courriers.soumettre_validation' => ['Soumettre pour validation', 'Envoyer sa réponse/action au responsable pour validation.', 'ecriture', ['Collaborateur', 'Responsable de service']],
            'courriers.renvoyer_correction' => ['Renvoyer pour correction', 'Refuser une soumission et la renvoyer au collaborateur (motif obligatoire).', 'ecriture', ['Responsable de service', 'DG']],
            'courriers.rejeter' => ['Rejeter un courrier', 'Clore un courrier comme rejeté (motif obligatoire).', 'ecriture', ['Responsable de service', 'DG']],
            'courriers.mettre_en_attente' => ['Mettre en attente d\'information', 'Suspendre le traitement en attendant une pièce ou une information (motif obligatoire).', 'ecriture', ['Collaborateur', 'Responsable de service']],
            'courriers.reprendre' => ['Reprendre le traitement', 'Relancer le traitement d\'un courrier mis en attente.', 'ecriture', ['Collaborateur', 'Responsable de service']],
            // 2026-10-30 (Module 7, « on doit pouvoir les relancer manuellement aussi »).
            'courriers.relancer' => ['Relancer la personne affectée', 'Envoyer un rappel (application et e-mail) à la personne affectée à un courrier en cours, avec un message facultatif, tracé dans l\'historique.', 'ecriture', ['Responsable de service', 'DG']],
            // — Classement et contenu
            'courriers.classer' => ['Classer un courrier dans un dossier', 'Ranger/retirer un courrier consultable dans un dossier de classement (unitaire ou en masse).', 'ecriture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.valider_classement' => ['Valider la proposition de classement', 'Accepter ou ignorer le type/service proposé automatiquement (Module 3).', 'ecriture', ['Agent']],
            'courriers.gerer_mots_cles' => ['Gérer les mots-clés', 'Ajouter ou retirer des mots-clés sur un courrier.', 'ecriture', ['Agent']],
            // — Lectures
            'courriers.voir_historique' => ['Voir l\'historique', 'Afficher l\'onglet "Historique" (journal immuable des actions) d\'un courrier consultable.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.voir_texte_ocr' => ['Voir le texte extrait (OCR)', 'Afficher le texte extrait du document et rechercher dans ce contenu.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.voir_pieces_jointes' => ['Voir les pièces jointes', 'Afficher la liste des pièces jointes d\'un courrier consultable et les consulter en aperçu sécurisé (filigrané, jamais téléchargeables).', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            // 2026-10-12, demande explicite de l'utilisateur ("not everybody
            // has to see this") — bloc "Document principal (numérisation)"/
            // OCR + "Classement"/mots-clés de la fiche ; mêmes 4 profils par
            // défaut que les autres lectures de ce même bloc ci-dessus
            // (voir_texte_ocr/voir_pieces_jointes), ajustable ensuite par
            // utilisateur/profil depuis "Utilisateurs & Accès"/"Profils".
            'courriers.voir_traitement_document' => ['Voir le traitement du document (OCR/Classement)', 'Afficher le statut OCR, la proposition de classement automatique et les mots-clés d\'un courrier consultable.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            // 2026-10-08 — retour utilisateur direct ("should be previledges
            // too") : "Répondre"/"Créer une note" (Actions rapides,
            // ShowCourrier) étaient les SEULS boutons de cette page sans
            // aucun privilège, simples placeholders désactivés ("Bientôt
            // disponible", aucune fonctionnalité réelle derrière). Mêmes
            // profils par défaut que `courriers.classer`, dans le même
            // panneau Actions rapides.
            'courriers.repondre' => ['Répondre à un courrier', 'Afficher le bouton "Répondre" (Actions rapides) — fonctionnalité à venir, aucune réponse distincte de l\'historique n\'existe encore.', 'ecriture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.creer_note' => ['Créer une note', 'Afficher le bouton "Créer une note" (Actions rapides) — fonctionnalité à venir, aucun modèle de note distinct de l\'historique n\'existe encore.', 'ecriture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            // 2026-09-23 — scindée en 4 clés, une par carte (même traitement
            // que dashboard.statistiques le même jour, "same thing for all
            // the pages, each card should be a permission") : impossible
            // auparavant de montrer "Total courriers" sans montrer aussi "En
            // erreur". Mêmes 4 profils par défaut que l'ancienne clé unique
            // (ci-dessous, supprimée du catalogue).
            'courriers.voir_carte_total' => ['Voir la carte "Total courriers"', 'Afficher la carte "Total courriers" de "Tous les courriers".', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.voir_carte_en_traitement' => ['Voir la carte "En traitement"', 'Afficher la carte "En traitement" de "Tous les courriers".', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.voir_carte_termines' => ['Voir la carte "Terminés"', 'Afficher la carte "Terminés" de "Tous les courriers".', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.voir_carte_en_erreur' => ['Voir la carte "En erreur"', 'Afficher la carte "En erreur" (échec OCR) de "Tous les courriers".', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.voir_enregistres' => ['Voir "Courriers enregistrés"', 'Accéder à la page "Courriers enregistrés" (courriers entrants par sous-statut de transfert).', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'courriers.voir_mes_courriers' => ['Voir "Mes courriers"', 'Accéder à la page "Mes enregistrements" (ses propres courriers entrants).', 'lecture', ['Agent']],
            // 2026-09-24 (voir DECISIONS.md "Courrier confidentiel : accès et
            // clôture par le destinataire") — portée "pli reçu" : seulement
            // les plis confidentiels dont on est le destinataire désigné.
            // Défaut DGA + Responsable de service (RH), les destinataires
            // typiques cités par le spec Module 1.
            // 2026-09-24 — portée "courriers que j'ai transférés" (DGA) :
            // consultation seule, après la validation du service.
            'courriers.voir_transferes' => ['Voir les courriers qu\'on a transférés', 'Consulter les courriers dont on a validé le service (DGA/ADJ), après leur transfert — lecture seule.', 'lecture', ['DG']],
            'courriers.voir_confidentiel_recu' => ['Voir les plis confidentiels reçus', 'Consulter un courrier confidentiel (jamais ouvert) envoyé directement à soi-même par la réception.', 'lecture', ['DG', 'Responsable de service']],
            'courriers.cloturer_confidentiel' => ['Marquer un pli confidentiel comme remis', 'Clore un pli confidentiel reçu une fois remis/traité ; il est ensuite archivé automatiquement.', 'ecriture', ['DG', 'Responsable de service']],
            'courriers.imprimer_accuse' => ['Imprimer l\'accusé de réception', 'Générer l\'accusé de réception d\'un courrier confidentiel.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            // — Tableau de bord : CHAQUE carte/KPI sa propre clé (2026-09-23,
            // demande explicite de l'utilisateur — "on tableau de board all
            // kpi and card there should be permission"). Remplace l'ancienne
            // clé unique `dashboard.statistiques` qui gouvernait les 5 cartes
            // d'un coup (voir le journal de développement — privilège retiré du
            // catalogue, supprimé explicitement plus bas). Mêmes 4 profils
            // par défaut que l'ancienne clé partagée, pour ne rien changer
            // au comportement observé.
            'dashboard.courrier_entrant' => ['Voir "Courrier entrant"', 'Afficher la carte "Courrier entrant" (aujourd\'hui vs hier) du tableau de bord.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'dashboard.courrier_sortant' => ['Voir "Courrier sortant"', 'Afficher la carte "Courrier sortant" (aujourd\'hui vs hier) du tableau de bord.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'dashboard.en_attente' => ['Voir "En attente de traitement"', 'Afficher la carte "En attente de traitement" du tableau de bord.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'dashboard.urgents' => ['Voir "Courriers urgents"', 'Afficher la carte "Courriers urgents" du tableau de bord.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'dashboard.en_retard' => ['Voir "En retard"', 'Afficher la carte "En retard" (dépassement SLA) du tableau de bord.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            // 2026-10-09, demande explicite de l'utilisateur — nouvelle carte,
            // même 4 profils par défaut que les autres cartes KPI ci-dessus.
            // 2026-11-10 — devenue « À affecter » (voir Courrier::scopeAAffecter()),
            // clé inchangée ; plus l'Agent par défaut : ses cartes sont
            // désormais celles de « Mes courriers » ci-dessous.
            'dashboard.non_transferes' => ['Voir "À affecter"', 'Afficher la carte "À affecter" (courriers acceptés par le service, pas encore affectés) du tableau de bord.', 'lecture', ['DG', 'Responsable de service', 'Collaborateur']],
            // 2026-11-10 (« it should match her mes courrier ») — les trois
            // onglets de « Mes courriers » en cartes, une clé chacune.
            'dashboard.mes_a_transferer' => ['Voir "À transférer"', 'Afficher la carte "À transférer" (ses courriers enregistrés, pas encore envoyés à un service) du tableau de bord.', 'lecture', ['Agent']],
            'dashboard.mes_en_attente_acceptation' => ['Voir "En attente d\'acceptation"', 'Afficher la carte "En attente d\'acceptation" (ses courriers transférés, pas encore acceptés par le service) du tableau de bord.', 'lecture', ['Agent']],
            'dashboard.mes_acceptes' => ['Voir "Acceptés"', 'Afficher la carte "Acceptés" (ses courriers acceptés par le service, avec les originaux papier à remettre) du tableau de bord.', 'lecture', ['Agent']],
            'dashboard.derniers_courriers' => ['Voir les derniers courriers', 'Afficher la liste "Derniers courriers enregistrés" du tableau de bord.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'dashboard.delai_moyen' => ['Voir le délai moyen de traitement', 'Afficher la carte "Délai moyen de traitement" (toute l\'entreprise avec "Voir tous les courriers", sinon ses services).', 'lecture', ['Responsable de service']],
            // Panneau latéral : cartes "Notifications" et "Calendrier"
            // (contenu décoratif/placeholder, mais chaque carte a désormais
            // sa permission comme le reste — même défaut que general.notifications).
            'dashboard.notifications' => ['Voir la carte Notifications', 'Afficher la carte "Notifications" du tableau de bord (panneau latéral).', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            // — Dossiers de classement
            'dossiers_classement.voir' => ['Voir les dossiers de classement', 'Accéder à "Dossiers & Archives" (seuls les dossiers créés, dirigés ou partagés restent visibles).', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
            'dossiers_classement.modifier' => ['Renommer / déplacer ses dossiers', 'Renommer ou déplacer un dossier qu\'on a créé.', 'ecriture', ['Responsable de service', 'Collaborateur']],
            'dossiers_classement.partager' => ['Partager ses dossiers', 'Définir qui a accès à un dossier qu\'on a créé.', 'ecriture', ['Responsable de service', 'Collaborateur']],
            'dossiers_classement.supprimer' => ['Supprimer ses dossiers', 'Supprimer un dossier vide qu\'on a créé.', 'ecriture', ['Responsable de service', 'Collaborateur']],
            // — Règles de classement et profils
            'regles_classement.voir' => ['Voir les règles de classement', 'Consulter la page "Référentiels (règles de classement)" sans pouvoir la modifier.', 'lecture', []],
            'regles_classement.reanalyser' => ['Réanalyser les courriers', 'Relancer la classification automatique sur les courriers existants.', 'administratif', []],
            'profils.creer' => ['Créer un profil', 'Créer un nouveau profil sur la page "Profils".', 'administratif', []],
            'general.aide' => ['Voir l\'aide', 'Afficher le menu "Aide / Documentation" et le bouton d\'aide de la barre supérieure.', 'lecture', ['Agent', 'DG', 'Responsable de service', 'Collaborateur']],
        ];

        $profils = Profil::pluck('id', 'nom');

        // 2026-10-08 — BUG RÉEL corrigé (retour utilisateur : "stop giving
        // back all the priveleges to the agents i remove some and you give
        // him again why") : syncWithoutDetaching() tournait AVANT sur
        // CHAQUE ligne du catalogue à CHAQUE exécution du seeder, y compris
        // pour des privilèges déjà existants — ce qui réattachait
        // silencieusement les profils par défaut même après qu'un
        // administrateur les ait explicitement retirés via
        // /admin/profils ou /admin/utilisateurs. Ce seeder est relancé
        // volontairement après CHAQUE modification de ce fichier (règle
        // d'exécution, voir notes internes) — sans cette garde,
        // chaque relance effaçait silencieusement toute personnalisation
        // faite depuis l'interface, même pour des privilèges sans rapport
        // avec la modification en cours. Les profils par défaut ne sont
        // désormais assignés QUE lors de la toute première création du
        // privilège (wasRecentlyCreated) — jamais retouchés ensuite,
        // laissant les choix faits depuis l'interface intacts pour de bon.
        // 2026-10-08 — même audit : nom/description/type utilisaient aussi
        // firstOrCreate(), qui ne les écrit QUE à la création. Contrairement
        // aux profils (modifiables depuis l'interface, jamais retouchés
        // ensuite, voir ci-dessus), ce texte n'est PAS éditable depuis l'UI
        // ("le catalogue lui-même est fixe", voir notes internes)
        // — ce fichier en est la seule source de vérité, donc updateOrCreate()
        // pour que toute correction de libellé/description faite ici
        // atteigne réellement la vraie base au prochain reseed (sinon un
        // texte périmé pouvait rester affiché indéfiniment, découvert sur
        // administration.sla : "page à venir" laissé alors que la page
        // réelle existe depuis le 2026-09-23).
        foreach ($catalogue as $cle => [$nom, $description, $type, $nomsProfils]) {
            $privilege = Privilege::updateOrCreate(['cle' => $cle], ['nom' => $nom, 'description' => $description, 'type' => $type]);

            if (! $privilege->wasRecentlyCreated) {
                continue;
            }

            $nomsProfils = array_unique([...$nomsProfils, 'Administrateur']);
            $idsProfils = collect($nomsProfils)->map(fn ($nomProfil) => $profils[$nomProfil] ?? null)->filter()->values();

            $privilege->profils()->syncWithoutDetaching($idsProfils);
        }

        // dashboard.statistiques (2026-09-23) : remplacée ci-dessus par 5
        // clés, une par carte — supprimée explicitement (cascade sur les
        // pivots privilege_profil/privilege_user, voir leurs migrations)
        // plutôt que laissée comme entrée morte dans le catalogue.
        Privilege::where('cle', 'dashboard.statistiques')->delete();
        // courriers.voir_statistiques (2026-09-23, même jour) : remplacée
        // par 4 clés, une par carte de "Tous les courriers" — même raison.
        Privilege::where('cle', 'courriers.voir_statistiques')->delete();
        // courriers.voir_file_attente (2026-09-24) : page "Transferts"
        // (WorkflowQueue) supprimée, doublon de "Tous les courriers" — même
        // traitement, pas d'entrée morte dans le catalogue.
        Privilege::where('cle', 'courriers.voir_file_attente')->delete();
        // dashboard.calendrier (2026-10-12) : mini-carte décorative du
        // tableau de bord retirée (demande explicite de l'utilisateur,
        // "what does that calendar on the dasboard even do" — purement
        // décorative, aucune échéance réelle affichée) ; la vraie page
        // calendrier (/courriers/calendrier, courriers.calendrier) reste
        // inchangée et accessible depuis la sidebar.
        Privilege::where('cle', 'dashboard.calendrier')->delete();
        // 2026-10-23 — routage DGA retiré (voir DECISIONS.md "Transfert
        // direct au service et acceptation") : validation du service par la
        // DGA, sa file, sa délégation et l'envoi direct (devenu le circuit
        // normal) n'existent plus — même traitement que les clés ci-dessus.
        Privilege::whereIn('cle', ['courriers.voir_dga', 'courriers.dga_valider_service', 'courriers.envoyer_direct_service', 'administration.delegation_dga'])->delete();
        // 2026-11-09 — plus aucun téléchargement, pour personne (voir
        // DECISIONS.md « Fichiers : chiffrement, aperçu sécurisé, aucun
        // téléchargement ») : un document se consulte en aperçu sécurisé.
        Privilege::where('cle', 'courriers.telecharger')->delete();
    }
}
