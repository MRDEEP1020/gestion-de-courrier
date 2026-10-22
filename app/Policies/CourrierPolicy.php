<?php

namespace App\Policies;

use App\Models\Courrier;
use App\Models\User;
use App\Services\WorkflowService;

class CourrierPolicy
{
    // Module 9 — Droits d'accès. Toute action sensible passe par ici,
    // jamais un `if` direct dans une vue ou un composant Livewire.
    //
    // 2026-09-15 — Système de privilèges (voir DECISIONS.md "Système de
    // privilèges") : chaque branche ci-dessous vérifiait auparavant
    // `$user->profil?->nom` contre un nom de profil en dur ; désormais
    // `$user->hasPrivilege('...')`, assignable dynamiquement à un profil
    // ET/OU à un utilisateur précis depuis /admin/privileges, sans changer
    // de code. Le PÉRIMÈTRE (scope) de chaque privilège "_service"/
    // "_propre"/"_affecte"/"_dga" reste inchangé (même condition qu'avant),
    // seul le contrôle "qui a le droit" est désormais dynamique.

    // Module 1 — saisie réservée à l'agent courrier / secrétariat (voir
    // specifications-modules-GEC.md), l'administrateur gardant accès à tout.
    public function create(User $user): bool
    {
        return $user->hasPrivilege('courriers.creer');
    }

    // "Confidentialité numérique hiérarchique" (2026-09-21, voir
    // DECISIONS.md) : le niveau de confidentialité MAXIMUM de l'utilisateur
    // (User::niveau_confidentialite, page "Utilisateurs & Accès") est
    // comparé numériquement au niveau du courrier (Courrier::confidentialite,
    // entier 1-5 — voir User::NIVEAU_CONFIDENTIALITE_MAX) — SE CUMULE avec
    // le système de privilèges ci-dessous sur TOUTE ability qui reçoit un
    // $courrier précis, ne le remplace jamais : même un privilège
    // "modifier_tout"/"affecter_tout"/etc. ne suffit plus si le niveau est
    // insuffisant. Un utilisateur ne devrait pas pouvoir MODIFIER/AFFECTER/
    // TRAITER un courrier qu'il n'a de toute façon pas le droit de voir —
    // pas seulement bloqué sur view() pendant que les autres abilities
    // resteraient ouvertes (ex. EditForm autorise directement 'update',
    // jamais 'view' d'abord).
    private function niveauSuffisant(User $user, Courrier $courrier): bool
    {
        return $user->niveauConfidentialiteEffectif() >= $courrier->confidentialite;
    }

    // Module 3/9 — troisième mécanisme cumulatif (DECISIONS.md 2026-09-16 :
    // "les trois se cumulent"), en plus de niveauSuffisant() et du système
    // de privilèges ci-dessous. Un courrier NON classé (dossier_classement_id
    // null) n'est jamais affecté — seuls les courriers rangés dans un
    // dossier réel héritent de sa restriction d'accès (gerer_tout, ou
    // créateur/responsable du dossier, ou partage explicite
    // dossier_classement_user — jamais un privilège de périmètre générique,
    // voir PrivilegeSeeder.php).
    private function accesDossierSuffisant(User $user, Courrier $courrier): bool
    {
        if ($courrier->dossier_classement_id === null) {
            return true;
        }

        $dossier = $courrier->dossierClassement;

        if ($dossier === null) {
            // FK orpheline (nullOnDelete) — ne bloque jamais un accès sur
            // un état incohérent.
            return true;
        }

        if ($user->hasPrivilege('dossiers_classement.gerer_tout')) {
            return true;
        }

        // 2026-09-24 (voir DECISIONS.md "Dossier de classement : les acteurs
        // du circuit gardent l'accès") — un dossier personnel ne masque
        // jamais un courrier à ceux qui travaillent dessus.
        if ($courrier->impliqueUtilisateur($user)) {
            return true;
        }

        if ($user->id === $dossier->cree_par_id || $user->id === $dossier->responsable_id) {
            return true;
        }

        return $dossier->utilisateursAutorises()->where('users.id', $user->id)->exists();
    }

    // Module "Organisation" v2 (2026-09-22, spec §18/§19) — QUATRIÈME
    // mécanisme cumulatif : "Le frontend ne doit jamais être la seule
    // protection... Les règles doivent également être appliquées côté
    // backend." Un utilisateur SANS périmètre assigné n'est pas restreint
    // par ce gate (opt-in, même principe que accesDossierSuffisant() pour
    // un courrier non classé) ; un utilisateur AVEC un périmètre ne voit/
    // n'agit que sur les courriers dont le service (via le pont
    // OrganizationUnit::service_id) est sous l'une des entités accordées.
    private function accesPerimetreSuffisant(User $user, Courrier $courrier): bool
    {
        return $courrier->estDansLePerimetreDe($user);
    }

    // Périmètre par profil (Module 8 : "un agent ne voit que les courriers de
    // son périmètre, sauf droits élargis"). Vérifié en PREMIER, avant tout
    // privilège de périmètre, pour bloquer même un accès par lien direct
    // (email/notification), pas seulement filtrer les listes (voir aussi
    // CourrierList::resultats()).
    public function view(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesDossierSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        if ($user->hasPrivilege('courriers.voir_tout')) {
            return true;
        }

        // service null-safe : un courrier entrant en attente de validation
        // DGA/ADJ n'a pas encore de service (voir DECISIONS.md,
        // synchronisation SRS-GEC.pdf) — un Responsable de service ne doit
        // simplement pas le voir tant que ce n'est pas le cas, pas planter.
        if ($user->hasPrivilege('courriers.voir_service') && $user->id === $courrier->service?->responsable_id) {
            return true;
        }

        if ($user->hasPrivilege('courriers.voir_propre') && $courrier->estCreeParUtilisateur($user)) {
            return true;
        }

        if ($user->hasPrivilege('courriers.voir_affecte') && $courrier->affectationCourante?->user_id === $user->id) {
            return true;
        }

        // 2026-09-08 — rôle global (pas de service_id), scopé au seul
        // statut où DGA a réellement quelque chose à faire, même logique
        // que les autres profils (périmètre = ce qui les concerne
        // actuellement, pas un historique). 'en_cours_de_transfert', pas
        // 'en_attente_de_transfert' (2026-09-15, voir DECISIONS.md,
        // synchronisation SRS-GEC.pdf) : le DGA n'a rien à faire tant que la
        // réceptionniste n'a pas explicitement cliqué "Transférer".
        // 2026-09-15 (mise à jour) — depuis que la réceptionniste choisit UN
        // destinataire précis (voir DECISIONS.md "Destinataires de
        // transfert"), un courrier adressé à quelqu'un d'autre ne doit plus
        // apparaître ici : `destinataire_transfert_id === null` reste
        // autorisé pour tout DGA-privilégié — comportement d'avant,
        // préservé pour les courriers transférés avant ce changement.
        if ($user->hasPrivilege('courriers.voir_dga')
            && $courrier->statut === 'en_cours_de_transfert'
            && ($courrier->destinataire_transfert_id === null || $courrier->destinataire_transfert_id === $user->id)) {
            return true;
        }

        // 2026-10-06 (entretien terrain, voir DECISIONS.md "Délégation DGA/
        // ADJ absents") : miroir de la branche ci-dessus pour un délégataire
        // actif (ex. RH), sans le privilège courriers.voir_dga lui-même.
        $delegantsIds = $user->delegationsDgaActivesIds();

        if (! empty($delegantsIds)
            && $courrier->statut === 'en_cours_de_transfert'
            && ($courrier->destinataire_transfert_id === null || in_array($courrier->destinataire_transfert_id, $delegantsIds, true))) {
            return true;
        }

        // 2026-09-24 (voir DECISIONS.md "DGA : consultation des courriers
        // transférés") — demande explicite de l'utilisateur ("dga doesn't
        // see all the courier he transfered") : une fois le service validé,
        // la DGA garde la CONSULTATION du courrier (lecture seule — aucune
        // autre ability ne s'appuie sur ce privilège).
        if ($user->hasPrivilege('courriers.voir_transferes') && $courrier->estTransferePar($user)) {
            return true;
        }

        // 2026-09-24 (voir DECISIONS.md "Courrier confidentiel : accès et
        // clôture par le destinataire") — un pli confidentiel envoyé
        // directement à cet utilisateur, quel que soit son statut (il garde
        // la consultation une fois remis puis archivé). Le niveau de
        // confidentialité reste vérifié en tête de méthode.
        if ($user->hasPrivilege('courriers.voir_confidentiel_recu')
            && $courrier->confidentiel_direct
            && $courrier->destinataire_transfert_id === $user->id) {
            return true;
        }

        return false;
    }

    // Module 1/9 — "Marquer comme remis" : le destinataire d'un pli
    // confidentiel le clôt une fois remis/traité hors application ; il est
    // ensuite archivé automatiquement comme tout courrier traité.
    public function cloturerConfidentiel(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        return $user->hasPrivilege('courriers.cloturer_confidentiel')
            && $courrier->confidentiel_direct
            && $courrier->statut === 'enregistre'
            && $courrier->destinataire_transfert_id === $user->id;
    }

    // Module 1 — corriger un enregistrement : même périmètre que la consultation
    // (Agent limité à ce qu'il a lui-même enregistré), l'Administrateur pouvant
    // tout corriger.
    public function update(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesDossierSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        // Module 9 — "les documents archivés restent consultables mais ne
        // sont plus modifiables" : jamais sur view(), seulement sur les
        // abilities qui mutent le courrier.
        if ($courrier->statut === 'archive') {
            return false;
        }

        if ($user->hasPrivilege('courriers.modifier_tout')) {
            return true;
        }

        if ($user->hasPrivilege('courriers.modifier_propre') && $courrier->estCreeParUtilisateur($user)) {
            return true;
        }

        return false;
    }

    public function archive(User $user, $courrier): bool
    {
        // Pas encore implémenté (voir specifications-modules-GEC.md Module 9) —
        // câblé sur un privilège dédié pour être prêt. Assigné à
        // Administrateur (comme tous les privilèges, 2026-09-15) mais sans
        // effet réel tant que l'action d'archivage elle-même n'existe pas
        // ailleurs dans le code.
        return $user->hasPrivilege('courriers.archiver');
    }

    // Module 9 — "Décharge" (2026-10-05) : mêmes trois portes que view()
    // (niveau/dossier/périmètre — on ne peut pas emprunter l'original d'un
    // courrier qu'on ne pourrait même pas consulter), plus le privilège
    // dédié. Uniquement sur un courrier déjà archivé (vérifié aussi côté
    // WorkflowService::emettreDecharge(), jamais seulement ici).
    public function emettreDecharge(User $user, Courrier $courrier): bool
    {
        if ($courrier->statut !== 'archive') {
            return false;
        }

        if (! $this->niveauSuffisant($user, $courrier) || ! $this->accesDossierSuffisant($user, $courrier) || ! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        return $user->hasPrivilege('courriers.emettre_decharge');
    }

    // Module 6 — affecter/réaffecter : le responsable du service concerné,
    // ou un administrateur. Un agent ou un collaborateur ne s'auto-affecte pas.
    public function affecter(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesDossierSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        if ($courrier->statut === 'archive') {
            return false;
        }

        if ($user->hasPrivilege('courriers.affecter_tout')) {
            return true;
        }

        if ($user->hasPrivilege('courriers.affecter_service') && $user->id === $courrier->service?->responsable_id) {
            return true;
        }

        return false;
    }

    // 2026-10-13, demande explicite de l'utilisateur ("isnt the another way
    // to that without selecting his name") — raccourci "Traiter moi-même" :
    // auto-affectation + démarrage du traitement en une seule action, pour
    // un responsable qui a en plus le privilège de TRAITEMENT (pas
    // seulement d'affectation). Mêmes gardes que affecter() (accès/
    // périmètre/dossier/statut) : jamais affectationCourante ici, le
    // courrier n'est pas encore affecté au moment de ce contrôle — c'est
    // justement ce que cette action va faire.
    // 2026-10-14 — bug réel (retour utilisateur, "should hide when we have
    // already use it") : affecter() ci-dessus n'a volontairement AUCUNE
    // restriction de statut au-delà de "pas archivé" (il sert aussi à la
    // réaffectation). Mais WorkflowService::affecter() (celui réellement
    // appelé par traiterMoiMeme(), voir ShowCourrier::traiterMoiMeme()) ne
    // transitionne QUE depuis 'enregistre' (première affectation) — jamais
    // utilisé pour réaffecter. Le formulaire "Affecter à" classique gère ça
    // en ajoutant `$courrier->statut === 'enregistre'` directement dans la
    // vue (voir showCourrier.blade.php) ; ce raccourci doit avoir la MÊME
    // restriction, ici dans la policy plutôt que dupliquée dans la vue,
    // pour que peutTraiterMoiMeme reflète fidèlement ce que l'action va
    // réellement faire (sinon le bouton reste visible après un premier
    // usage, et un second clic échoue avec une RuntimeException).
    public function traiterMoiMeme(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === 'enregistre'
            && $this->affecter($user, $courrier)
            && $user->hasPrivilege('courriers.traiter_affecte');
    }

    // Module 5 — fixer / modifier le délai de traitement (chronomètre,
    // 2026-09-24) : même portée que l'affectation (responsable du service,
    // ou affecter_tout), EN PLUS du privilège d'action dédié ; seulement
    // tant que le courrier est encore en circuit.
    public function fixerDelai(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.fixer_delai')
            && in_array($courrier->statut, WorkflowService::statutsActifs(), true)
            && $this->affecter($user, $courrier);
    }

    // Module 4 — démarrer le traitement / soumettre pour validation : réservé
    // au collaborateur actuellement affecté (dernière ligne d'affectations),
    // ou à un administrateur.
    public function traiter(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesDossierSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        if ($courrier->statut === 'archive') {
            return false;
        }

        if ($user->hasPrivilege('courriers.traiter_tout')) {
            return true;
        }

        if ($user->hasPrivilege('courriers.traiter_affecte') && $courrier->affectationCourante?->user_id === $user->id) {
            return true;
        }

        return false;
    }

    // Module 4 — valider/renvoyer/rejeter/mettre en attente : le "supérieur
    // hiérarchique" du spec est ici le responsable du service concerné.
    public function valider(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesDossierSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        if ($courrier->statut === 'archive') {
            return false;
        }

        if ($user->hasPrivilege('courriers.valider_tout')) {
            return true;
        }

        if ($user->hasPrivilege('courriers.valider_service') && $user->id === $courrier->service?->responsable_id) {
            return true;
        }

        return false;
    }

    // Module 1/4 — la réceptionniste clique "Transférer" pour envoyer
    // explicitement au DGA/ADJ DGA un courrier entrant en attente (2026-09-15,
    // voir DECISIONS.md, synchronisation SRS-GEC.pdf). Même forme que
    // update() : "_tout" pour un administrateur, "_propre" limité à qui a
    // créé le courrier (la réceptionniste qui l'a enregistré).
    public function transferer(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesDossierSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        if ($courrier->statut === 'archive') {
            return false;
        }

        if ($user->hasPrivilege('courriers.transferer_tout')) {
            return true;
        }

        return $user->hasPrivilege('courriers.transferer_propre') && $courrier->estCreeParUtilisateur($user);
    }

    // 2026-10-09 — extension du circuit générique (PAS un circuit différent,
    // voir WorkflowService/DECISIONS.md "Signature DG / Trésorerie") :
    // alternative à valider() pour un responsable ayant ce privilège
    // dédié — opt-in par département (assigné via /admin/profils ou
    // /admin/utilisateurs), jamais lié au type de courrier ni au
    // département lui-même dans le code.
    public function envoyerSignatureDg(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.envoyer_signature_dg') && $this->valider($user, $courrier);
    }

    // La DG signe (cloture directe OU transmission trésorerie, deux actions
    // séparées côté WorkflowService — cette seule ability gate les deux,
    // le choix entre les deux reste au DG au moment d'agir).
    public function signerDg(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesDossierSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        if ($courrier->statut !== 'en_attente_signature_dg') {
            return false;
        }

        return $user->hasPrivilege('courriers.dga_signer');
    }

    public function traiterTresorerie(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesDossierSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        if ($courrier->statut !== 'en_attente_tresorerie') {
            return false;
        }

        return $user->hasPrivilege('courriers.traiter_tresorerie');
    }

    // Module 3/9 — filer un courrier dans un dossier de classement : délègue
    // entièrement à view() (si on peut voir un courrier, on peut le ranger),
    // le VRAI verrou est côté dossier (DossierClassementPolicy::view(), voir
    // DossierClassementList/ShowCourrier/CourrierList) — pas un nouveau
    // privilège dédié (confirmé avec l'utilisateur, "pas des privilèges").
    // Délibérément SANS blocage statut === 'archive' (contrairement à
    // update()/affecter()/etc.) : ranger un courrier archivé dans un dossier
    // (Module 9 — retrouvabilité) reste utile, ce n'est pas modifier son contenu.
    public function classer(User $user, Courrier $courrier): bool
    {
        // 2026-09-23 — privilège d'action dédié en plus de la consultation.
        return $user->hasPrivilege('courriers.classer') && $this->view($user, $courrier);
    }

    // ===== "Chaque action / lecture = un privilège" (2026-09-23) =====
    // Principe : le privilège d'ACTION (ex. courriers.rejeter) s'ajoute à la
    // vérification de PORTÉE déjà en place (valider() : valider_tout /
    // valider_service, niveau, dossier, périmètre) — jamais l'un sans l'autre.

    public function reaffecter(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.reaffecter') && $this->affecter($user, $courrier);
    }

    public function soumettreValidation(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.soumettre_validation') && $this->traiter($user, $courrier);
    }

    // 2026-10-14, demande explicite de l'utilisateur ("if is the
    // responsable traite the courier does he have to summit it again...
    // why can he validate directly") — quand le traiteur ET le validateur
    // sont la MÊME personne (responsable qui s'est auto-affecté via
    // traiterMoiMeme()), passer par "Soumettre" PUIS "Valider" séparément
    // n'a de sens que pour deux personnes distinctes. Raccourci composé
    // des DEUX abilities existantes (jamais une nouvelle règle de
    // périmètre) + la restriction de statut 'en_traitement' (l'étape
    // depuis laquelle "Soumettre" part normalement) : si les deux sont
    // vraies pour ce $user précis, c'est qu'il est à la fois le
    // collaborateur affecté ET le responsable validateur. Le circuit à 2
    // étapes reste inchangé pour tout le monde d'autre.
    public function validerDirectement(User $user, Courrier $courrier): bool
    {
        return $courrier->statut === 'en_traitement'
            && $this->soumettreValidation($user, $courrier)
            && $this->valider($user, $courrier);
    }

    public function renvoyerCorrection(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.renvoyer_correction') && $this->valider($user, $courrier);
    }

    public function rejeter(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.rejeter') && $this->valider($user, $courrier);
    }

    // Ouvert au collaborateur affecté ET au responsable (voir
    // ShowCourrier::mettreEnAttente()) : portée traiter() OU valider().
    public function mettreEnAttente(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.mettre_en_attente')
            && ($this->traiter($user, $courrier) || $this->valider($user, $courrier));
    }

    public function reprendre(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.reprendre')
            && ($this->traiter($user, $courrier) || $this->valider($user, $courrier));
    }

    public function validerClassement(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.valider_classement') && $this->update($user, $courrier);
    }

    public function gererMotsCles(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.gerer_mots_cles') && $this->update($user, $courrier);
    }

    public function voirHistorique(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.voir_historique') && $this->view($user, $courrier);
    }

    public function voirTexteOcr(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.voir_texte_ocr') && $this->view($user, $courrier);
    }

    public function voirPiecesJointes(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.voir_pieces_jointes') && $this->view($user, $courrier);
    }

    // 2026-10-12, demande explicite de l'utilisateur ("not everybody has to
    // see this ... the card stop at detail complementaire") — gouverne
    // tout le bloc "Document principal (numérisation)"/OCR + "Classement"/
    // mots-clés de la fiche (statut/confiance OCR, proposition de
    // classement, liste des mots-clés) : sans ce privilège, la carte
    // "Détails complémentaires" s'arrête après ses champs habituels, sans
    // exposer ces informations de traitement interne. Distinct des
    // privilèges d'ACTION déjà existants sur ce même bloc (validerClassement/
    // gererMotsCles/voirTexteOcr/renumeriser) : ceux-ci gouvernent chacun un
    // bouton précis, celui-ci gouverne la simple VISIBILITÉ de la section.
    public function voirTraitementDocument(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.voir_traitement_document') && $this->view($user, $courrier);
    }

    // "Répondre"/"Créer une note" (Actions rapides, ShowCourrier) — boutons
    // désactivés de longue date (aucune fonctionnalité de réponse/note
    // distincte de l'historique n'existe encore), mais jusqu'ici sans
    // privilège du tout, contrairement à TOUTE autre action de cette même
    // page (retour utilisateur direct, capture d'écran : "should be
    // previledges too"). Même patron que les autres : visibilité du
    // bouton conditionnée au privilège, la fonctionnalité réelle restera
    // à implémenter plus tard.
    public function repondre(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.repondre') && $this->view($user, $courrier);
    }

    public function creerNote(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.creer_note') && $this->view($user, $courrier);
    }

    public function voirEnregistres(User $user): bool
    {
        return $user->hasPrivilege('courriers.voir_enregistres');
    }

    public function voirMesCourriers(User $user): bool
    {
        return $user->hasPrivilege('courriers.voir_mes_courriers');
    }

    // Module 1/4 — demande explicite de l'utilisateur (2026-09-08) : la DGA/
    // ADJ confirme/change le service proposé par Module 3 pour un courrier
    // entrant non-sinistre, avant que le circuit habituel ne démarre.
    // $courrier non utilisé dans le corps, mais gardé dans la signature pour
    // rester appelable via ShowCourrier::courrierPour() comme
    // affecter/traiter/valider ci-dessus (même mécanisme d'appel,
    // `$this->authorize($abilite, $courrier)`). Voir DECISIONS.md "Circuit
    // courrier entrant : validation DGA/ADJ".
    public function validerService(User $user, Courrier $courrier): bool
    {
        if (! $this->niveauSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesDossierSuffisant($user, $courrier)) {
            return false;
        }

        if (! $this->accesPerimetreSuffisant($user, $courrier)) {
            return false;
        }

        if ($courrier->statut === 'archive') {
            return false;
        }

        // 2026-09-24 — seule étape où valider le service a un sens. Jusqu'ici
        // la vue et WorkflowService le garantissaient seuls ; nécessaire ici
        // depuis que la DGA garde la consultation des courriers déjà
        // transférés (sinon peutValiderService restait vrai après coup).
        if ($courrier->statut !== 'en_cours_de_transfert') {
            return false;
        }

        if ($user->hasPrivilege('courriers.dga_valider_service')) {
            // 2026-09-15 (mise à jour, voir DECISIONS.md "Destinataires de
            // transfert") : ne valide que ce qui lui a été explicitement
            // adressé — même exception `null` que view() ci-dessus pour les
            // courriers transférés avant ce changement.
            return $courrier->destinataire_transfert_id === null || $courrier->destinataire_transfert_id === $user->id;
        }

        // 2026-10-06 (entretien terrain, voir DECISIONS.md "Délégation DGA/
        // ADJ absents") : un utilisateur sans le privilège DGA peut quand
        // même valider si un DGA/ADJ actif lui a explicitement délégué cette
        // capacité (absence simultanée du DGA ET de l'Adjoint DGA) — jamais
        // au-delà de ce que ce DGA précis couvre : même règle d'adressage
        // que ci-dessus, en remplaçant "soi-même" par "n'importe quel DGA
        // délégant actuellement couvert".
        $delegantsIds = $user->delegationsDgaActivesIds();

        if (empty($delegantsIds)) {
            return false;
        }

        return $courrier->destinataire_transfert_id === null || in_array($courrier->destinataire_transfert_id, $delegantsIds, true);
    }

    // Module 8 — recherche/liste multi-critères : ouverte à tout profil
    // reconnu (contrairement à l'ancienne file d'attente, l'Agent y a accès aussi —
    // il doit pouvoir retrouver les courriers qu'il a lui-même enregistrés).
    // Le filtrage par périmètre lui-même est fait au niveau de la requête
    // dans CourrierList::resultats(), avec la même logique que view().
    // Télécharger (document principal, pièces jointes) et imprimer le
    // bordereau : séparés de la consultation le 2026-09-23 — il faut
    // pouvoir VOIR le courrier ET avoir le privilège de l'action.
    public function telecharger(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.telecharger') && $this->view($user, $courrier);
    }

    public function imprimerBordereau(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.imprimer_bordereau') && $this->view($user, $courrier);
    }

    // Suppression LOGIQUE uniquement (SoftDeletes, Règle n°5 : jamais
    // physique, jamais un courrier archivé), motif obligatoire tracé dans
    // l'historique par l'appelant (CourrierList::supprimerCourrier()).
    public function delete(User $user, Courrier $courrier): bool
    {
        return $user->hasPrivilege('courriers.supprimer')
            && $courrier->statut !== 'archive'
            && $this->view($user, $courrier);
    }

    // Module 1/2 — "Numérisation & OCR" (scan d'abord + dossier surveillé),
    // privilège distinct de courriers.creer depuis le 2026-09-23 (menus
    // pilotés par privilège, voir DECISIONS.md) : un opérateur de scan peut
    // numériser sans enregistrer, et inversement.
    public function numeriser(User $user): bool
    {
        return $user->hasPrivilege('courriers.numeriser');
    }

    // Re-numériser un courrier existant (ScanForm) : numériser ET pouvoir
    // corriger CE courrier.
    public function renumeriser(User $user, Courrier $courrier): bool
    {
        return $this->numeriser($user) && $this->update($user, $courrier);
    }

    // Module 1 — "Cas particulier : courrier confidentiel" : privilège
    // distinct de courriers.creer depuis le 2026-09-23.
    public function creerConfidentiel(User $user): bool
    {
        return $user->hasPrivilege('courriers.creer_confidentiel');
    }

    // Module 1 — accusé de réception d'un courrier confidentiel (2026-09-23) :
    // "remis au déposant" par la réceptionniste qui vient de l'enregistrer —
    // or son niveau de confidentialité est normalement inférieur à celui du
    // courrier, donc view() le lui refusait. L'accusé ne contient que ce
    // qu'elle a elle-même saisi (nom sur l'enveloppe, référence, date),
    // jamais l'objet ni le contenu (voir CourrierAccuseReceptionController),
    // d'où l'exception limitée au créateur.
    public function imprimerAccuseReception(User $user, Courrier $courrier): bool
    {
        // Privilège d'action courriers.imprimer_accuse (2026-09-23).
        if (! $user->hasPrivilege('courriers.imprimer_accuse')) {
            return false;
        }

        if ($this->view($user, $courrier)) {
            return true;
        }

        return $user->hasPrivilege('courriers.voir_propre') && $courrier->estCreeParUtilisateur($user);
    }

    public function rechercher(User $user): bool
    {
        return $user->hasPrivilege('courriers.rechercher');
    }

    public function calendrier(User $user): bool
    {
        return $user->hasPrivilege('courriers.calendrier');
    }

    // "Importer"/"Exporter" (en-tête de "Tous les courriers") — boutons
    // désactivés de longue date (aucune fonctionnalité réelle derrière),
    // mais jusqu'ici sans privilège du tout, comme "Répondre"/"Créer une
    // note" avant eux (retour utilisateur direct, même capture d'écran
    // annotée "priveliges"). Même patron que create()/numeriser()/
    // rechercher() ci-dessus : ability de classe, sans instance $courrier.
    public function importer(User $user): bool
    {
        return $user->hasPrivilege('courriers.importer');
    }

    public function exporter(User $user): bool
    {
        return $user->hasPrivilege('courriers.exporter');
    }
}
