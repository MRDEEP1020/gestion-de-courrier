# Spécifications fonctionnelles — GEC
### Solution de Gestion Électronique du Courrier — Phase 1 — Nsia Assurances

Document de préparation de réunion — intègre les éléments confirmés par le
client (workflow réel, règles métier, liste des services).

**Mise à jour 2026-09-15** — ce document remplace intégralement la version
précédente suite à un nouveau document SRS reçu du client
(`SRS-GEC.pdf`), qui confirme/précise de nombreux points, dont deux
changements de fond par rapport à la version précédente de ce document :
le **tampon papier n'est plus utilisé** (déjà acté le 2026-09-04, voir
DECISIONS.md) et le **champ "Service" est retiré du formulaire de
l'agent/réceptionniste** (nouveau — précédemment l'agent saisissait un
service que le DGA pouvait ensuite corriger ; désormais l'agent ne le
voit plus du tout, c'est le DGA/ADJ DGA qui le choisit entièrement).
Voir DECISIONS.md pour le détail de ce qui était déjà implémenté avant ce
document et ce qui reste à faire.

---

## Module 1 — Enregistrement du courrier entrant et sortant

**Objectif**
Créer un point d'entrée unique et normalisé pour tout courrier reçu ou envoyé par l'organisation.

**Acteurs**
- Agent courrier / secrétariat (upload du scan, saisie/correction des champs pré-remplis)
- DGA / Adjoint DGA (ADJ DGA) — validation du transfert et choix du service (voir Module 3/4)

**Données à capturer** (pré-remplies automatiquement à partir du scan — extraites
du corps du courrier lui-même, plus du tampon, voir Module 2)
- Numéro de référence unique — **toujours généré par le système**, jamais extrait d'un tampon
- Sens du courrier : entrant / sortant
- Date de réception ou d'envoi (pré-remplie — OCR du motif « {Ville}, le {date} » en haut du courrier)
- Expéditeur (pré-rempli — extrait de l'en-tête de lettre)
- Destinataire(s) (pré-rempli — extrait du motif « À l'attention de… » ou « Monsieur/Madame le Directeur Général de… »)
- Objet du courrier (pré-rempli — extrait de la ligne commençant par « Objet : »)
- Type de document (lettre, facture, réclamation, demande, Offre de service, Sinistre — avec sous-types, etc.)
- Mode de réception (dépôt physique, email, poste, fax)
- **Service ou direction visée — RETIRÉ du formulaire d'enregistrement.** Masqué
  pour l'Agent/réceptionniste. Cohérent avec le processus réel : c'est le
  **DGA ou l'Adjoint DGA (ADJ DGA)** qui décide du service, pas la
  réceptionniste, qui n'a par ailleurs aucun accès à la proposition de
  service générée par la classification automatique (Module 3) — cette
  proposition reste réservée au DGA/ADJ DGA au moment de valider le
  transfert (Module 4). Exception déjà actée : un courrier clairement
  identifié comme sinistre est envoyé directement à DSIN par la
  réceptionniste, sans passer par cette étape.
- Niveau de priorité (Normal / Urgent — urgence de traitement)
- **Niveau de confidentialité (1, 2, 3, 4… — échelle numérique hiérarchique)**,
  contrôle QUI peut voir le courrier, distinct de la priorité. Un
  utilisateur ayant un niveau d'accès inférieur (ex. niveau 2) ne peut pas
  consulter un courrier de niveau supérieur (ex. niveau 3+), même s'il a
  par ailleurs accès au service concerné — voir Module 9 pour la règle complète.
- Pièce(s) jointe(s) — types typiques selon la catégorie (ex. « Offre de
  service » : attestation de conformité fiscale, RCCM, plan de
  localisation, NIU, modes de paiement, photocopie CNI du gérant). Point
  de vigilance : certaines pièces jointes (photocopies de CNI, pièces
  d'identité) contiennent des données personnelles sensibles — droits
  d'accès renforcés à prévoir (voir Module 9).

**Déroulement attendu**
1. L'agent scanne le courrier et l'upload dans le système.
2. Le système traite le scan (Module 2 : OCR du corps + reconnaissance de
   motifs structurels + classification automatique du service — Module 3)
   et pré-remplit le formulaire : date, expéditeur, destinataire, objet,
   service pressenti.
3. L'agent relit et corrige les champs pré-remplis, puis valide.
4. Le système génère le numéro de référence unique, non modifiable.
5. L'enregistrement passe au statut « Enregistré ».

**Point ouvert** — la fiabilité du pré-remplissage dépend de la
régularité de mise en forme des courriers reçus (tous ne suivent pas
exactement le même gabarit) — l'agent devra probablement corriger plus
souvent au début ; à mesurer une fois un échantillon réel plus large
disponible en production.

**Cas particulier : courrier confidentiel** — certains courriers arrivent
marqués « confidentiel » et ne sont **jamais ouverts** par la réception.
Processus différent :
1. L'agent ne scanne **pas** le contenu (le courrier reste fermé).
2. Il relève uniquement le nom visible sur l'enveloppe (destinataire).
3. Le courrier est enregistré avec un minimum d'informations
   (destinataire, date de réception, mention « Confidentiel ») et transmis
   directement, sans passer par la classification automatique du Module 3
   (impossible sans ouvrir le courrier).
4. Il est envoyé directement à la **RH** ou au **DGA/ADJ DGA** (une
   personne, pas un service de la liste fixe).
5. Un **accusé de réception** est généré et remis au déposant (exclusif à
   ce cas) : numéro de référence, date/heure d'enregistrement, nom visible
   sur l'enveloppe, mention « Confidentiel », agent ayant enregistré —
   c'est la seule preuve documentée de ce courrier (pas de scan, pas
   d'OCR, pas d'historique de contenu). Cas 100% manuel, volontairement minimal.

**Règles métier**
- Le numéro de référence ne doit jamais pouvoir être dupliqué ou réattribué.
- Un enregistrement incomplet (champs obligatoires manquants après relecture par l'agent) ne peut pas être validé.
- L'agent doit toujours pouvoir corriger un champ mal extrait automatiquement — l'extraction automatique n'est jamais bloquante, seulement une aide à la saisie.
- Historique des modifications de l'enregistrement conservé (qui a modifié, quand).

---

## Module 2 — Numérisation et dématérialisation

**Objectif**
Transformer tout courrier papier en document électronique exploitable, et en
extraire automatiquement les informations nécessaires pour pré-remplir le Module 1.

**Acteurs**
- Agent courrier (scan, upload)
- Système (traitement OCR pour l'indexation + classification automatique)

**Déroulement attendu**
1. Le courrier physique est scanné (scanner déjà en place) et uploadé.
   **Scope confirmé du scan : la/les première(s) page(s) du courrier
   uniquement** — pas le document dans son intégralité (ex. annexes
   volumineuses jointes).
2. Le fichier numérique (PDF/image) est rattaché à l'enregistrement.
3. OCR classique sur le contenu complet du courrier.
4. **Extraction par reconnaissance de motifs** sur la structure habituelle
   d'un courrier professionnel : date (motif « {Ville}, le {date} »),
   objet (ligne « Objet : »), expéditeur (zone d'en-tête), destinataire
   (motif « À l'attention de… » ou « Monsieur/Madame le Directeur Général
   de… »). Chaque champ extrait pré-remplit le formulaire du Module 1.
5. Le texte extrait alimente à la fois l'index de recherche plein-texte
   (Module 8) et la classification automatique du service (Module 3),
   devenue le **seul** mécanisme de proposition automatique de service
   (plus de détection de cercle manuscrit — abandonnée avec le tampon).
6. Contrôle qualité : vérification que le scan est lisible ; sinon,
   tentative d'amélioration automatique de l'image avant de redemander un re-scan.

**Simplification de scope, nuancée** — le traitement de vision par
ordinateur pour détecter un cercle manuscrit sort du périmètre. En
contrepartie, l'extraction par motifs sur le corps du courrier demande un
travail de reconnaissance de structure plus riche qu'une simple regex sur
un tampon fixe — la complexité se déplace, elle ne disparaît pas entièrement.

**Cas particulier : courrier confidentiel** — ce module ne s'applique
**pas** aux courriers marqués confidentiels — ils ne sont jamais ouverts
ni scannés (voir Module 1). Le champ confidentiel désactive tout le
pipeline OCR/extraction/classification pour ce courrier.

**Règles métier**
- Format de stockage standardisé (PDF/A recommandé pour la pérennité).
- Le document original scanné ne doit jamais être modifiable après validation.
- Taille et résolution de scan encadrées pour équilibrer qualité/espace de stockage.
- L'extraction automatique est une aide, jamais une vérité imposée sans
  recours — l'agent valide ou corrige. Exception : le service pressenti
  n'est ni affiché ni corrigé par l'agent — c'est le DGA/ADJ DGA qui le
  valide ou le corrige, au moment du transfert (voir Module 3/4).

**Points tranchés avec le client**
- Matériel de numérisation déjà en place chez le client.
- Liste fixe des services/directions confirmée (voir annexe) — reste
  valable même sans le tampon, comme liste de sélection manuelle/de
  référence pour la classification.
- Le tampon papier n'est plus utilisé — confirmé par le client.
- Le pré-remplissage complet du formulaire reste un objectif confirmé, via
  l'extraction du corps du courrier plutôt que du tampon.

**Points techniques encore ouverts**
- L'OCR doit-il gérer le français uniquement, ou aussi d'autres langues/écritures ?
- Comment mesurer la volumétrie réelle sans le tampon (compteur système
  une fois en production ? décompte manuel ponctuel côté client ?).
- **Périmètre géographique de la phase 1** : le siège social uniquement,
  ou aussi les agences ? (question notée par le client lui-même en
  réunion — impacte le dimensionnement et le déploiement progressif déjà
  prévu par service).
- Fiabilité de l'extraction par motifs sur des courriers dont la mise en
  forme varie (tous les expéditeurs ne suivent pas exactement le même
  gabarit) — à mesurer sur un échantillon réel plus large une fois en production.

---

## Module 3 — Classement et indexation automatiques

**Objectif**
Organiser chaque courrier selon des critères structurés pour permettre un classement cohérent et une recherche rapide.

**Acteurs**
- Système (indexation automatique selon règles)
- DGA / Adjoint DGA (ADJ DGA) — validation/correction de la proposition de
  service (pas l'agent/réceptionniste, voir Module 1 et Module 4)

**Données d'indexation**
- Expéditeur / destinataire
- Service concerné
- Date
- Type de document
- Mots-clés (extraits automatiquement du texte OCR ou saisis manuellement)
- Statut de traitement

**Déroulement attendu**
1. À l'enregistrement, le système propose un classement automatique basé
   sur deux règles complémentaires — **désormais le seul moyen de
   proposition automatique du service** (la détection de cercle sur
   tampon a été abandonnée avec le tampon lui-même) :
   - **Règle par mots-clés** : objet contenant « sinistre » → catégorie « Sinistre » (avec sous-type à préciser).
   - **Règle par historique d'expéditeur** (prioritaire sur les mots-clés) :
     si cet expéditeur a déjà été routé vers un service par le passé, ce
     service est proposé automatiquement pour ses courriers suivants.
2. Le DGA/ADJ DGA (et non l'agent/réceptionniste) peut valider ou corriger
   la classification proposée, au moment de valider le transfert (Module
   4) — jamais imposée sans validation.
3. Le document est rangé dans une arborescence logique (par service, par
   type, par période) tout en restant retrouvable via plusieurs critères
   simultanément (métadonnées, pas seulement un dossier physique).

**Dossiers de classement créés par service**
- Le chef de service **ou les collaborateurs** de ce service (pas
  uniquement un administrateur) peuvent créer leurs propres dossiers de
  classement dans le système, en plus de l'arborescence automatique par service/type/période.
- Un courrier déjà traité est d'abord rangé dans le dossier numérique
  correspondant ; le rangement de l'original physique se fait après, à
  l'endroit qui correspond à ce dossier numérique — le numérique guide le
  physique, pas l'inverse (voir Module 9 pour la référence de localisation physique).
- **Privilège d'accès par dossier** : chaque dossier créé a ses propres
  droits de visualisation — qui peut le voir ou non. Ce n'est pas
  automatiquement tout le service qui voit tous les dossiers créés en son
  sein ; le créateur du dossier définit qui y a accès, plus finement que
  le niveau service. Ce privilège s'ajoute aux deux autres règles d'accès
  (niveau de confidentialité hiérarchique, et permission par
  service/dossier × profil — voir Module 9) — **les trois se cumulent**.

**Sous-type « Sinistre »**
- Matériel : dégâts biens (véhicule, propriété)
- Corporel : blessures/dommages aux personnes

**Cas « Contentieux » — dossier opposant deux parties** — certains
dossiers de sinistre concernent un litige entre deux parties (partie
demanderesse / partie défenderesse), pas un simple sinistre à une seule
partie assurée. Champs supplémentaires par rapport à un courrier classique :
- Partie A (nom, rôle : demandeur/assuré)
- Partie B (nom, rôle : défendeur/tiers)
- Référence du dossier sinistre lié (si déjà existant)

Ce sous-type reste dans le périmètre du Module 3 pour la classification
(mot-clé « contentieux » ou détection de la structure « X contre Y »),
mais le modèle de données du courrier doit prévoir ces champs optionnels
**dès la phase 1** pour rester cohérent avec le lien futur vers les
dossiers sinistres (phase 2).

**Règles métier**
- Un document appartient à une classification principale mais peut avoir
  plusieurs mots-clés/tags secondaires.
- Les règles de classement automatique doivent être paramétrables par un
  administrateur, sans intervention développeur.
- La règle « historique d'expéditeur » prime sur la règle « mots-clés » en
  cas de conflit, mais les deux restent des propositions — jamais bloquantes.

---

## Module 4 — Circuit de validation (Workflow)

**Objectif**
Acheminer automatiquement chaque courrier vers les bonnes personnes, selon un circuit de traitement défini, jusqu'à sa clôture.

**Acteurs**
- Responsable de service (traitement, validation)
- Collaborateur assigné (traitement opérationnel)
- Administrateur (configuration des circuits)

**Déroulement attendu (exemple de circuit type)**
1. **Réception** → le courrier enregistré arrive dans la file d'attente du responsable du service concerné.
2. **Affectation** → le responsable l'assigne à un collaborateur, ou le système l'affecte automatiquement selon des règles (type de courrier, charge de travail).
3. **Traitement** → le collaborateur consulte le courrier, rédige une réponse ou une action, et met à jour le statut.
4. **Validation** → un supérieur hiérarchique valide ou renvoie pour correction.
5. **Clôture** → le courrier est marqué « Traité » et archivé (Module 9).

**Sous-circuit précis : transfert réceptionniste → DGA/ADJ DGA** (détaille
l'étape « Réception » ci-dessus) — le transfert d'un courrier de la
réceptionniste vers son destinataire suit **3 sous-statuts distincts**,
visibles comme des onglets côté réceptionniste :
1. **En attente de transfert** — courrier enregistré, pas encore transféré.
2. **En cours de transfert** — la réceptionniste a cliqué « Transférer » ;
   en attente que le destinataire valide/accepte la réception.
3. **Transféré** — le destinataire a cliqué « Valider/Accepter le transfert ».
   Une fois au statut « Transféré », **la réceptionniste ne peut plus
   modifier le courrier** (verrouillage) — seul le destinataire peut agir dessus ensuite.

Le champ « Service » n'est plus saisi par la réceptionniste (voir Module
1). C'est le DGA/ADJ DGA qui choisit le service au moment de valider le
transfert (étape 3 ci-dessus) — la proposition de la classification
automatique (Module 3) lui est affichée comme suggestion, qu'il confirme
ou corrige avant de valider.

**Chef de service — précisions**
- Un chef de service peut **traiter lui-même** un courrier de son service
  (pas obligé de le déléguer à un collaborateur), notamment quand le
  courrier est **urgent, à priorité maximale, ou confidentiel** — dans
  les autres cas, le circuit normal d'affectation s'applique.
- Il reçoit une **notification** dès qu'un document arrive pour son
  service — c'est un **privilège configurable** (via le mécanisme de
  permissions déjà en place), activé par défaut pour le chef de service,
  mais un administrateur peut le retirer ou l'accorder à quelqu'un d'autre. Pas une règle fixe non modifiable.
- **Notification de traitement** : de la même façon, être notifié quand un
  document de son service est traité (pas seulement à son arrivée) est
  aussi un privilège configurable **distinct** — même mécanisme que la
  notification d'arrivée, mais un déclencheur différent (statut « Traité » plutôt que création du courrier).
- C'est le chef de service (ex. chef DS pour la Direction des Sinistres)
  qui accorde/configure le **délai de traitement (SLA)** de tous les
  documents de son service — pas uniquement un paramétrage global par un
  administrateur (voir Module 5). Ce délai peut être accordé **par
  document individuel**, pas uniquement par type de courrier globalement.

Chaque courrier suit un statut visible à tout moment : *Enregistré →
Affecté → En traitement → En validation → Traité → Archivé* (+
éventuellement *Rejeté* / *En attente d'information*).

**Règles métier**
- Le circuit (les étapes et leur ordre) doit être configurable par type de courrier.
- Un courrier ne peut pas sauter une étape obligatoire sauf dérogation explicite tracée.
- Possibilité de réaffecter un courrier en cours de traitement avec justification obligatoire.
- Un courrier au sous-statut « Transféré » est verrouillé pour son
  expéditeur interne (la réceptionniste) — seul le destinataire peut le faire évoluer.

---

## Module 5 — Suivi et traçabilité (avec gestion des délais SLA)

**Objectif**
Garantir un historique complet et infalsifiable du cycle de vie de chaque courrier, avec mesure du respect des délais.

**Acteurs**
- Tous les utilisateurs (génèrent des traces par leurs actions)
- Superviseurs — au sens « responsables de service » (voir notes internes : pas un nouveau rôle, correspond au
  profil `Responsable de service` déjà existant) — consultation des
  indicateurs de délai

**Déroulement attendu**
1. Chaque action sur un courrier (réception, ouverture, affectation,
   commentaire, réponse, validation) est journalisée avec horodatage et
   identité de l'auteur.
2. Un SLA (délai maximum autorisé) est défini par type de courrier et/ou
   par étape du workflow (ex. 48h pour affecter, 5 jours pour traiter une
   réclamation), et **peut aussi être ajusté par document individuel** par
   le chef de service concerné (voir Module 4), pas seulement au niveau global du type.
3. Le système calcule en continu le temps écoulé par rapport au SLA et
   affiche un statut visuel (à temps / à risque / en retard).
4. L'historique complet est consultable sous forme de chronologie
   (timeline) par courrier.

**Règles métier**
- L'historique ne doit jamais pouvoir être supprimé ou modifié, uniquement
  complété (traçabilité = intégrité).
- Les SLA doivent être paramétrables par un administrateur fonctionnel,
  **et aussi par le chef de service lui-même** pour les documents de son
  propre service — le paramétrage n'est donc pas uniquement centralisé au niveau administrateur.

---

## Module 6 — Gestion des affectations

**Objectif**
Attribuer chaque courrier à la bonne direction, au bon service ou au bon collaborateur, avec possibilité de réattribution maîtrisée.

**Acteurs**
- Responsable de service (affectation)
- Administrateur (gestion des règles d'affectation automatique)

**Déroulement attendu**
1. Affectation initiale manuelle (par un responsable) ou automatique
   (règle basée sur type/mot-clé/service destinataire).
2. Visualisation de la charge de travail par collaborateur (nombre de
   courriers en cours) pour équilibrer les affectations.
3. **Règle d'affectation automatique par charge de travail** : si un
   collaborateur a déjà des courriers en cours (« points d'affectation »)
   et qu'un autre du même service n'en a aucun, le système affecte
   automatiquement au collaborateur le moins chargé.
4. Réaffectation possible à tout moment, avec motif enregistré et notification au nouveau destinataire.

**Hiérarchie interne aux services** — un service peut avoir des
**sous-départements internes**, chacun avec son propre chef — ex. la
Direction des Sinistres (DSIN) a un chef global, mais aussi des chefs pour
différents sous-types de sinistres en interne (Sinistre Auto, Sinistre
Santé, Sinistre Matériel/Corporel).
- Quand un courrier arrive chez le chef de service global (ex. chef
  DSIN), il peut le **rerouter vers un chef de sous-département** de son
  propre service, plutôt que de l'affecter directement à un collaborateur.
- Le chef de sous-département a les **mêmes privilèges** qu'un chef de
  service classique (traiter lui-même un courrier urgent/prioritaire/
  confidentiel, configurer le SLA, recevoir les notifications), mais
  **limités à son propre périmètre** (son sous-département uniquement, pas tout le service).
- Cette hiérarchie est à **plusieurs niveaux potentiels** (pas limitée à
  2) — un service peut avoir des sous-services, eux-mêmes avec des chefs,
  selon l'organisation réelle du client.
- Exemple concret : Chef Sinistre (global) → Sinistre Santé (avec son
  équipe) et Sinistre Auto (avec sa propre équipe).
- **Deux chemins de réception possibles** pour une équipe de
  sous-département : les collaborateurs peuvent recevoir un courrier soit
  **directement** (affectation automatique ou pré-classée jusqu'à leur
  équipe), soit **via le chef de service global** (qui reroute
  manuellement) — les deux chemins coexistent, aucun n'est exclusif.

**Règles métier**
- Un courrier a toujours un responsable identifié à tout instant (jamais « orphelin »).
- Historique des réaffectations conservé (qui a réaffecté, pourquoi, quand).
- La règle de charge de travail est un critère par défaut, mais un
  responsable peut toujours affecter manuellement en priorité sur la règle automatique.
- Le rerouting vers un chef de sous-département suit les mêmes règles de
  traçabilité que toute réaffectation (motif, notification, historique).

---

## Module 7 — Alertes et relances automatiques

**Objectif**
Prévenir les retards en notifiant automatiquement les personnes concernées avant et après dépassement des délais.

**Acteurs**
- Système (déclenchement automatique)
- Collaborateurs et responsables (destinataires des alertes)

**Déroulement attendu**
1. Une tâche planifiée (ex. chaque heure ou chaque jour) vérifie tous les courriers actifs.
2. Si un courrier approche de son échéance SLA (ex. à 24h de la limite) → notification « à risque » envoyée au collaborateur assigné.
3. Si le délai est dépassé → notification « en retard » envoyée au collaborateur et à son responsable (escalade).
4. Les notifications peuvent être envoyées par email et/ou affichées dans l'interface (centre de notifications).

**Règles métier**
- Les seuils de déclenchement (ex. 24h avant échéance) doivent être paramétrables.
- L'escalade vers le responsable hiérarchique doit être configurable selon le niveau de retard.

---

## Module 8 — Recherche avancée

**Objectif**
Permettre de retrouver rapidement n'importe quel courrier, même sans connaître sa référence exacte.

**Acteurs**
- Tous les utilisateurs habilités selon leurs droits d'accès

**Déroulement attendu**
1. Recherche simple par numéro de courrier, objet, expéditeur ou date.
2. Recherche combinée (plusieurs critères simultanés : ex. service = « Sinistres » + période + statut).
3. Recherche plein-texte dans le contenu scanné (grâce à l'OCR du Module 2).
4. **Recherche par traçabilité** : filtrer/rechercher par étape du cycle de
   vie avec qui et quand, en s'appuyant sur `courrier_historiques` (Module 5) :
   - Date + auteur de la saisie/enregistrement
   - Date + auteur de l'affectation (qui a affecté, à qui)
   - Date + auteur du transfert (qui a transféré, à qui, et son
     sous-statut : en attente / en cours / transféré)

   Exemples d'usage : « tous les courriers affectés par X la semaine
   dernière », « tous les courriers en attente de transfert depuis plus de 2 jours ».
5. **Localisation physique du document** : le résultat de recherche
   indique dans quel dossier de classement (Module 3) le document a été
   rangé numériquement, pour permettre de retrouver rapidement l'original
   physique au même endroit.
6. Résultats filtrables et exportables (liste, tableau).

**Règles métier**
- Les résultats de recherche sont filtrés selon les droits d'accès de
  l'utilisateur (un agent ne voit que les courriers de son périmètre, sauf droits élargis).

---

## Module 9 — Archivage électronique sécurisé

**Objectif**
Conserver durablement les documents traités, avec un accès contrôlé et conforme aux exigences réglementaires.

**Acteurs**
- Système (archivage automatique après clôture)
- Administrateur (gestion des droits d'accès et des durées de conservation)

**Déroulement attendu**
1. Un courrier clôturé (statut « Traité ») est automatiquement transféré vers l'espace d'archivage.
2. Les documents archivés restent consultables mais ne sont plus modifiables.
3. Des règles de durée de conservation peuvent être appliquées selon le
   type de document (conformité réglementaire propre au secteur, à confirmer avec la Direction/juridique).
3bis. **Référence de localisation physique** : chaque dossier de
   classement (créé au Module 3) porte une référence de rangement
   physique (ex. « Armoire A, Niveau 2 »). C'est cette référence que le
   Module 8 (recherche) affiche pour guider la recherche physique de l'original.
4. Les droits d'accès aux archives sont gérés finement (par rôle, par
   service, par niveau de confidentialité) :
   - Le privilège d'accès se définit **au niveau du dossier/service** (qui
     peut voir quels dossiers de stockage), pas uniquement au niveau
     global du profil — un même profil (ex. Collaborateur) peut avoir un
     niveau de visualisation différent selon le service concerné. Prévoir
     une table de permissions **dossier × profil** plutôt qu'une règle
     uniquement basée sur le profil global de l'utilisateur.
   - **Règle de niveau hiérarchique** : chaque courrier a un niveau de
     confidentialité **numérique** (1, 2, 3, 4…) ; chaque utilisateur a un
     niveau d'accès maximum. Un utilisateur ne peut jamais consulter un
     courrier dont le niveau de confidentialité dépasse son propre niveau
     d'accès (ex. niveau 2 → invisible tout courrier de niveau 3+). Cette
     règle se cumule avec la permission par dossier/service — **les deux
     doivent être satisfaites**, ni l'une ni l'autre ne suffit seule.
   - **Durcissement de la règle** : cette restriction s'applique
     **indépendamment du canal** — même si le courrier apparaît dans une
     interface (résultat de recherche, liste, notification), un
     utilisateur de niveau insuffisant ne peut pas l'ouvrir. Même s'il est
     envoyé par email (notification, lien), l'ouverture reste bloquée côté
     système si le niveau d'accès n'est pas suffisant au moment du clic —
     la restriction porte sur l'action d'ouverture elle-même, pas
     seulement sur le filtrage des listes affichées.
5. **Décharge** : quand quelqu'un emprunte/consulte un document original
   physique archivé, le système génère une « décharge » — un reçu avec un
   numéro de référence qui indique où et par qui le document a été
   emprunté, pour permettre de le retrouver rapidement même hors du système.

**Règles métier**
- Aucune suppression définitive sans validation d'un administrateur habilité (traçabilité de la suppression elle-même).
- Prévoir dès la phase 1 une structure de données extensible, pour anticiper le lien futur avec les dossiers sinistres (phase 2).
- Toute décharge émise doit être tracée dans l'historique du courrier concerné (Module 5).
- **Pièces jointes sensibles** : certains courriers (ex. « Offre de
  service ») sont accompagnés de pièces jointes contenant des données
  personnelles identifiantes (photocopie de carte d'identité du gérant,
  par exemple). Ces pièces jointes doivent avoir un niveau de droits
  d'accès distinct/renforcé par rapport au reste du dossier — pas le même
  niveau d'accès par défaut que l'objet ou le corps du courrier.

**Point à creuser en réunion**
- Existe-t-il des obligations légales/réglementaires spécifiques (durée de
  conservation, format d'archivage) dans le secteur d'activité du client ?

---

## Module 10 — Tableaux de bord et statistiques

**Objectif**
Donner une vision synthétique et en temps réel de l'activité courrier pour le pilotage managérial.

**Acteurs**
- Responsables de service et direction (consultation)
- Administrateur (configuration des indicateurs)

**Indicateurs types**
- Volume de courriers entrants/sortants par période
- Répartition par service, par type, par statut
- Délai moyen de traitement
- Nombre de courriers en attente / en retard
- Taux de respect des SLA
- Charge de travail par collaborateur
- **Indicateurs de traçabilité** : qui a saisi/affecté/transféré le plus
  de courriers sur une période, délai moyen entre saisie et affectation,
  délai moyen entre affectation et transfert (basé sur
  `courrier_historiques`, mêmes données que la recherche par traçabilité du Module 8).

**Déroulement attendu**
1. Les données sont agrégées automatiquement à partir des enregistrements et de l'historique de traitement (Module 5).
2. Affichage sous forme de graphiques (courbes, histogrammes) et de compteurs clés, actualisés en temps réel ou à intervalle régulier.
3. Filtres par période, service, type de courrier.
4. Export possible (PDF, Excel) pour les rapports de direction.

**Règles métier**
- Les tableaux de bord doivent être personnalisables selon le rôle de l'utilisateur (direction générale vs responsable de service).

---

## Configuration administrateur — listes de référence (transversal)

**Confirmé** : toutes les listes de valeurs utilisées dans les champs à
sélection (select) doivent être gérables par l'**Administrateur** dans une
section de configuration dédiée — ajout, modification, suppression — sans
intervention développeur. Ne pas coder ces listes en dur.

Listes concernées, identifiées à date dans les modules ci-dessus :
- Types de document (Module 1 : lettre, facture, réclamation, demande,
  Offre de service, Sinistre — avec sous-types Matériel/Corporel, etc.)
- Modes de réception (Module 1 : dépôt physique, email, poste, fax)
- Niveaux de priorité (Module 1 : Normal, Urgent)
- Niveaux de confidentialité (Module 1/9 : 1, 2, 3, 4… — l'échelle
  elle-même, pas seulement le niveau assigné à un utilisateur ou un courrier)
- Liste des services/sous-services (Module 3/6 : SDG, DAF, DT, DSIN, ACG,
  DI, DC, SANTE, SJ, DCOM, RH, RAG, TRANS + sous-départements internes
  comme Sinistre Auto/Santé)
- Profils utilisateurs (Agent, Collaborateur, Responsable de service,
  Administrateur — voir DECISIONS.md sur la terminologie « Profil »)

**Règle métier** : une suppression d'une valeur de liste déjà utilisée par
des courriers existants ne doit jamais casser les enregistrements
existants (désactivation/masquage de la valeur plutôt que suppression
physique si elle est référencée).

---

## Récapitulatif du flux global

```
Réception (papier/email)
   ↓
Enregistrement (Module 1) → numéro de référence unique
   ↓
Numérisation + OCR (Module 2)
   ↓
Classement automatique (Module 3)
   ↓
Affectation (Module 6) → Workflow de validation (Module 4)
   ↓
Traitement / Réponse
   ↓
   ├── Suivi & SLA (Module 5) — en continu
   ├── Alertes si retard (Module 7) — en continu
   └── Recherche possible à tout moment (Module 8) — en continu
   ↓
Clôture → Archivage sécurisé (Module 9)
   ↓
Statistiques consolidées (Module 10)
```

---

## Point d'architecture à anticiper (Phase 2)

La phase 2 doit faire évoluer la solution vers la gestion et le suivi des
sinistres. Il est recommandé de concevoir dès la phase 1 :
- Un modèle de données extensible (un « courrier » pouvant être rattaché à
  un futur « dossier sinistre » — voir aussi les champs optionnels du cas « Contentieux », Module 3).
- Un moteur de workflow générique (pas codé en dur pour le seul cas
  « courrier »), réutilisable pour le circuit de traitement des sinistres.
- Une gestion des rôles/droits déjà pensée pour accueillir de nouveaux
  types d'acteurs (experts, assurés, etc.) en phase 2.

---

## Annexe — Liste des services

SDG (Secrétariat du DG), DAF (Affaires Financières et Comptabilité), DT
(Direction Technique), DSIN (Direction des Sinistres), ACG (Audit, Contrôle et
Gestion), DI (Direction Informatique), DC (Direction Commerciale), SANTE, SJ
(Service Juridique), DCOM (Communication), RH, RAG (à confirmer), TRANS
(Transport), + Secrétariat Général. Liste de référence pour la
classification — plus liée au tampon papier, qui n'est plus utilisé (voir
Module 2). DSIN a des sous-départements internes (ex. Sinistre Auto,
Sinistre Santé, Sinistre Matériel/Corporel — voir Module 6).
