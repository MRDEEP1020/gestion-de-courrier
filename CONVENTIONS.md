# CONVENTIONS.md — Règles du projet GEC (Gestion Électronique du Courrier)

Ce fichier définit les contraintes techniques à respecter dans tout le code de
ce projet. Objectif : un outil utilisé par toute l'entreprise, sans problème de
surcharge.

Documents liés : `PRD.md` (besoin produit), `ARCHITECTURE-ESSENTIALS.md`
(résumé), `ARCHITECTURE.md` (détail complet) et `DECISIONS.md` (décisions déjà
tranchées — ne pas les re-proposer).

---

## Stack

- Laravel 11/13
- Livewire 3
- Flux UI Pro (composants UI)
- Alpine.js
- Tailwind CSS v4
- Queue : Redis + Laravel Horizon
- Cache : Redis
- Stockage fichiers : disque S3-compatible (jamais le disque local du serveur)

---

## Règle n°1 — Tout ce qui est lourd part en queue, jamais en synchrone

Ne JAMAIS exécuter dans le cycle requête/réponse d'un contrôleur ou d'un composant Livewire :
- Le traitement OCR d'un document scanné
- L'envoi d'emails/notifications (alertes, relances)
- Le calcul de statistiques pour les tableaux de bord
- L'indexation d'un document pour la recherche

**À la place** : dispatcher un Job (`ProcessDocumentOcr`, `SendMailAlertJob`, `RefreshDashboardStatsJob`, `IndexCourrierJob`), avec `->onQueue('...')` selon la priorité, et informer l'utilisateur via un statut ("en traitement") + notification quand le job est terminé.

Chaque Job doit :
- Implémenter `ShouldQueue`
- Avoir un `retry` configuré (au minimum `$tries = 3`, avec `backoff()`) pour tolérer les coupures réseau
- Logger ses échecs de façon exploitable (pas juste un `catch` silencieux)

Les tâches périodiques (relances, calcul de retard SLA) passent par le **Scheduler** Laravel (`routes/console.php` ou `Kernel::schedule`), jamais par une vérification faite à chaque chargement de page.

---

## Règle n°2 — Composants Livewire légers

- **Ne jamais passer un modèle Eloquent complet** comme propriété publique d'un composant Livewire. Utiliser des ID primitifs (int/string) + `#[Computed]` ou route model binding pour recharger la donnée côté serveur à chaque requête.
- **Pas de propriétés publiques contenant des objets/collections lourds.** Uniquement des types primitifs (string, int, array, bool).
- **Niveau d'imbrication des composants limité à 1.** Pas de composant Livewire dans un composant Livewire dans un composant Livewire.
- **Pas de `wire:poll`** pour les mises à jour temps réel (notifications, statut de traitement). Utiliser les événements Livewire (`dispatch()` / listeners) ou le broadcasting (Echo + Reverb/Pusher) si du vrai temps réel est nécessaire.
- Utiliser des **Form Objects** Livewire pour toute saisie complexe (Module 1 : enregistrement courrier) plutôt que de multiplier les propriétés publiques directement dans le composant.

---

## Règle n°3 — Base de données

- **Toujours paginer** les listes de courriers — jamais de `::all()` sur une table qui va grossir.
- **Eager loading systématique** (`with()`) sur toute relation affichée dans une liste — pas de N+1.
- **Index obligatoires** sur : numéro de référence, statut, service, date d'enregistrement, et toute colonne utilisée dans un filtre de recherche (Module 8).
- Le numéro de référence unique (Module 1) doit être généré via une contrainte d'unicité en base (pas seulement vérifié côté application) pour éviter les doublons en cas de requêtes concurrentes.

---

## Règle n°4 — Stockage des documents

- Utiliser `Storage::disk('s3')` (ou équivalent MinIO configuré en local/dev) — jamais `Storage::disk('local')` en production.
- Nommage de fichier systématique et prévisible : `courriers/{annee}/{service}/{numero_reference}.pdf` pour rester cohérent avec le classement du Module 3.
- Ne jamais rendre un fichier scanné modifiable après validation (Module 2) — écriture unique, pas de update en place.

---

## Règle n°5 — Traçabilité et intégrité (Module 5)

- Toute action sur un courrier (création, changement de statut, affectation, réponse) doit créer une entrée d'historique immuable — jamais une simple mise à jour de champ sans trace.
- Utiliser un modèle d'événement/historique dédié (ex. table `courrier_historiques`) plutôt que de reconstituer l'historique depuis les `updated_at`.
- Aucune suppression physique d'un enregistrement d'historique ou d'un courrier archivé — soft delete + validation d'un rôle admin uniquement si suppression réellement nécessaire.

---

## Règle n°6 — Sécurité et droits d'accès (Module 9)

- Toute requête retournant des courriers doit être filtrée par les droits de l'utilisateur (policy Laravel), jamais un simple `if` dans la vue.
- Utiliser les **Policies** Laravel pour chaque action sensible (voir un courrier, l'archiver, le supprimer, changer son affectation).
- Ne jamais faire confiance à un ID de courrier passé côté client sans vérifier l'appartenance/les droits côté serveur (y compris dans les composants Livewire, malgré le binding).

---

## Règle n°7 — Tests

- Tout Job (OCR, alertes, indexation) doit avoir un test qui vérifie qu'il est bien dispatché sur la queue (`Queue::fake()`), pas seulement son résultat métier.
- Tout composant Livewire critique (workflow, affectation, SLA) doit avoir un test Livewire (`Livewire::test()`) couvrant au moins le cas nominal et un cas de droits refusés.

---

## Rappel de contexte projet

- 10 modules fonctionnels (voir `specifications-modules-GEC.md`)
- Délai cible : 3 mois pour un MVP + pilote sur un service, puis élargissement progressif
- Contrainte principale : l'outil sera utilisé par toute l'entreprise — la Règle n°1 (queues) et la Règle n°3 (DB) sont celles qui évitent le risque de surcharge à l'échelle
