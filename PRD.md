# PRD.md — GEC (Gestion Électronique du Courrier)
### Client : Nsia Assurances | Contexte : projet évalué dans le cadre d'un stage de 3 mois

---

## 1. Contexte et explication détaillée du projet

**Le problème actuel.** Nsia Assurances traite aujourd'hui son courrier (entrant et
sortant) de façon manuelle/papier : un courrier arrive, quelqu'un le lit, décide à
qui il doit aller, le transmet physiquement ou par email informel, et personne n'a
de vue d'ensemble fiable sur où en est un courrier donné, s'il est en retard, ou
qui doit encore agir dessus. Ça crée trois problèmes concrets : des courriers
perdus ou oubliés, des délais de traitement non maîtrisés (donc un risque pour la
relation client dans une compagnie d'assurance, où un courrier peut être une
réclamation ou le début d'un dossier sinistre), et aucune trace exploitable pour
prouver qu'un dossier a bien été traité dans les règles.

**Ce que la solution doit changer concrètement.** Un courrier, dès son arrivée,
devient un objet numérique unique et traçable dans le système, du premier jour
jusqu'à son archivage :

1. **Il est scanné et le système extrait automatiquement les informations du
   corps du courrier** — **mise à jour (2026-09-15, voir DECISIONS.md et
   `specifications-modules-GEC.md`) : le tampon d'entrée papier n'est plus
   utilisé, confirmé par le client** (décision antérieure déjà actée le
   2026-09-04). Le pré-remplissage reste l'objectif, mais désormais via une
   reconnaissance de motifs structurels sur le courrier lui-même (date au
   format "{Ville}, le {date}", objet après "Objet :", expéditeur en
   en-tête, destinataire après "À l'attention de…"), pas via le tampon.
   L'agent scanne d'abord (Module 2), le système **pré-remplit
   automatiquement** le formulaire, et l'agent relit/corrige (Module 1) au
   lieu de tout ressaisir à la main. Le numéro de référence est **toujours
   généré par le système**, jamais extrait d'un tampon, et une fois confirmé
   ne changera plus jamais. **Le service/la direction visée n'est PAS
   saisi par l'agent** — ce champ est retiré de son formulaire ; c'est le
   DGA/Adjoint DGA qui choisit ou confirme le service, au moment de valider
   le transfert (Module 3/4), en s'appuyant sur la proposition de
   classification automatique.
2. **Il devient pleinement numérique** — le texte complet est extrait par OCR
   pour la recherche, donc plus besoin de manipuler le papier physique pour le
   traiter ou le retrouver. Exception : un courrier marqué confidentiel n'est
   **jamais ouvert ni scanné** par la réceptionniste — seul le nom visible sur
   l'enveloppe est relevé (voir Module 1).
3. **Il est rangé intelligemment** — le système propose automatiquement un
   classement (service concerné, type de courrier — Module 3), pour que la
   recherche future soit rapide. Les chefs de service et leurs collaborateurs
   peuvent aussi créer leurs propres dossiers de classement, avec leurs
   propres droits d'accès par dossier, en plus du classement automatique.
4. **Il suit un circuit défini** — au lieu qu'une personne décide à la main à
   chaque fois où envoyer le courrier, un circuit de validation (Module 4)
   l'achemine vers le bon service (via le DGA/Adjoint DGA), avec des étapes de
   validation hiérarchique si nécessaire — y compris, pour la hiérarchie
   interne à certains services, des sous-départements avec leur propre chef.
5. **Quelqu'un en est responsable à tout moment** — le courrier est affecté à
   une personne précise (Module 6), jamais "orphelin".
6. **Le temps est surveillé automatiquement** — chaque type de courrier a un
   délai maximum (SLA), et le système sait à tout instant si on est dans les
   temps ou en retard (Module 5), et prévient les bonnes personnes avant/après
   un dépassement (Module 7) — au lieu que ce soit quelqu'un qui s'en aperçoive
   trop tard.
7. **On peut le retrouver en quelques secondes** — recherche par numéro, objet,
   expéditeur, date, ou contenu du texte scanné (Module 8), au lieu de fouiller
   des classeurs ou des dossiers email.
8. **Une fois traité, il est archivé proprement** — conservé, non modifiable,
   avec des droits d'accès contrôlés (Module 9), pour répondre à des exigences
   de conformité propres au secteur de l'assurance. **Mise à jour
   (2026-09-15)** : les droits d'accès combinent désormais trois règles
   cumulatives — le système de privilèges par profil/utilisateur (déjà
   construit, voir DECISIONS.md "Système de privilèges"), un droit par
   dossier de classement, et un **niveau de confidentialité numérique
   hiérarchique** (1, 2, 3, 4…) comparé au niveau d'accès maximum de
   l'utilisateur, appliqué indépendamment du canal (même un lien direct par
   email n'ouvre pas un courrier au-delà du niveau autorisé). Un emprunt d'un
   original physique archivé génère une "décharge" (reçu traçable).
9. **La direction voit l'activité globale** — un tableau de bord (Module 10)
   montre les volumes, les retards, les délais moyens — utile pour piloter et
   pour justifier des décisions (embauche, réorganisation) avec des chiffres
   réels plutôt qu'une impression.

**Qui utilise l'outil.** Potentiellement tous les employés de Nsia Assurances
qui reçoivent ou traitent du courrier — agents de saisie/secrétariat (enregistrement),
responsables de service (affectation, validation), collaborateurs (traitement),
direction (tableaux de bord). C'est ce qui motive la contrainte de charge/scalabilité
du projet : ce n'est pas un outil pour 5 personnes, mais potentiellement toute
l'organisation en simultané.

**Pourquoi une phase 2 "sinistres" est prévue.** Une déclaration de sinistre
*est*, structurellement, un courrier entrant particulier : quelqu'un envoie un
document, il doit être classé, affecté, suivi avec un délai, et archivé. En
construisant le Module 3 (classement) de façon extensible dès la phase 1, la
phase 2 pourra réutiliser le même moteur pour trier et acheminer les sinistres
plutôt que de repartir de zéro — c'est pourquoi certaines décisions
d'architecture (voir ARCHITECTURE.md) anticipent cette évolution sans pour
autant développer le module sinistres maintenant.

**L'enjeu pour ce projet précisément.** C'est le projet sur lequel Moustapha est
évalué pour un stage de 3 mois chez Nsia Assurances. Au-delà du produit livré,
ça veut dire que la qualité du code, la cohérence de l'architecture dans la
durée, et la capacité à tenir un planning réaliste comptent autant que les
fonctionnalités elles-mêmes.

## 2. Objectif du produit

Remplacer un processus de gestion du courrier papier/manuel par une solution web
centralisée, permettant l'enregistrement, le suivi, le traitement et l'archivage
de tout courrier entrant/sortant, avec traçabilité complète et respect des délais.

## 3. Portée — Phase 1 (MVP, 3 mois)

Fonctionnalités IN SCOPE pour la livraison à 3 mois (voir `specifications-modules-GEC.md`
pour le détail complet de chaque module) :

1. Enregistrement courrier entrant/sortant (numéro de référence unique)
2. Numérisation + OCR basique
3. Classement/indexation par règles simples (PAS de ML en phase 1)
4. Circuit de validation (workflow générique unique, pas de circuits multiples par type)
5. Suivi/traçabilité avec SLA basique (délai fixe par type de courrier)
6. Gestion des affectations
7. Alertes et relances automatiques
8. Recherche (critères classiques ; plein-texte OCR si le temps le permet)
9. Archivage électronique sécurisé avec droits d'accès
10. Tableau de bord avec indicateurs essentiels (volumes, retards, délai moyen)

## 4. Explicitement HORS SCOPE pour la phase 1 (vague 2)

- Classification automatique par Machine Learning (nécessite des données réelles
  collectées pendant la phase 1 — voir le prototype `classification_reelle.py`
  comme preuve de concept méthodologique)
- Règles de workflow avancées différenciées par type de courrier
- Tableaux de bord avec filtres avancés/export personnalisé
- Module sinistres (Phase 2, hors périmètre de ce PRD)

## 5. Contraintes non-fonctionnelles (critiques pour l'évaluation du stage)

- **Charge** : l'outil sera utilisé par toute l'entreprise — aucune tâche lourde
  (OCR, alertes, stats) ne doit bloquer une requête HTTP (voir ARCHITECTURE.md).
- **Déploiement** : progressif, jamais en "big bang" — pilote sur un service avant
  ouverture complète.
- **Délai** : 3 mois jusqu'au MVP + pilote. Le calendrier détaillé est dans
  `plan-projet-gec-scalabilite.md`.
- **Équipe** : 1 développeur (Moustapha). Toute décision d'architecture
  reste validée manuellement.

## 6. Critères de succès (ce qui sera évalué)

- Les 10 modules de la phase 1 sont fonctionnels et déployés en pilote sur au
  moins un service réel.
- Aucun incident de surcharge constaté pendant le pilote.
- Historique et traçabilité complets et non falsifiables sur chaque courrier traité.
- Documentation suffisante pour qu'un tiers (IT de Nsia Assurances) puisse reprendre
  le projet.

## 7. Documents liés

- `ARCHITECTURE.md` — décisions techniques détaillées
- `ARCHITECTURE-ESSENTIALS.md` — résumé condensé pour référence rapide
- `CONVENTIONS.md` — règles de code à respecter
- `DECISIONS.md` — journal des décisions techniques prises en cours de route
- `specifications-modules-GEC.md` — spécification fonctionnelle détaillée des 10 modules
