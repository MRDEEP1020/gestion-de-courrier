{{-- Entrée de menu pour un module pas encore construit (Affectations, Traitement
     & Réponse, Automatisation, Workflows, Rapports). Maquette sidebar du
     2026-09-18 : affichées grisées, non cliquables (`pointer-events-none
     opacity-60`). 2026-11-11 (« hide the pages that are grisées on the
     sidebar ») : PLUS AFFICHÉES — le composant ne produit rien. Les appels
     restent dans sidebar.blade.php : le jour où un module existe, il suffit
     de remplacer l'appel par un vrai <flux:sidebar.item :href=...>. --}}
@props(['icon'])
