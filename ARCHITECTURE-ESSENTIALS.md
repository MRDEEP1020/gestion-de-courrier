# ARCHITECTURE-ESSENTIALS.md
### Résumé — à garder en tête à chaque tâche. Détails complets dans ARCHITECTURE.md.

- Stack : Laravel 11/13 + Livewire 3 + Flux UI Pro + Alpine.js + Tailwind v4
- Queue/Cache : Redis + Horizon — **toute tâche lourde (OCR, alertes, stats, indexation)
  est un Job, jamais du code synchrone dans un contrôleur/composant**
- Stockage fichiers : disque S3-compatible uniquement, jamais `local`
- Composants Livewire : propriétés publiques = primitifs uniquement (pas de modèle
  Eloquent complet), pas de `wire:poll`, imbrication max niveau 1
- DB : pagination systématique, eager loading systématique, index sur numéro de
  référence/statut/service/date
- Historique (`courrier_historiques`) : append-only, jamais modifié/supprimé
- Droits d'accès : toujours via Policy Laravel, jamais un `if` dans la vue
- Déploiement : progressif par service, jamais toute l'entreprise d'un coup
- Décision technique nouvelle → l'écrire dans `DECISIONS.md`, ne pas juste coder

**Avant de créer un nouveau fichier/dossier** : structure volontairement à plat
(Models/, Livewire/, Jobs/, Policies/, Notifications/ — pas de sous-dossiers par
module, style MVC Laravel classique). Vérifier `scaffold.sh` — ne pas réinventer
une structure différente à chaque évolution.
