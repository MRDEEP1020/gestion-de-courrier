{{-- Statut + chronomètre, empilés sur deux lignes plutôt que posés côte à
     côte dans un seul flex-wrap (2026-10-02, retour utilisateur avec
     capture d'écran : les badges se repliaient déjà sur deux lignes dans
     une colonne de tableau étroite, mais via un flex-wrap qui ne garantit
     PAS où la coupure tombe — dépend de la largeur exacte disponible, donc
     incohérent selon le zoom/la résolution). Ici la coupure est
     DÉLIBÉRÉE : le statut occupe toujours sa propre ligne, le chronomètre
     (compte à rebours + badge "Bientôt en retard"/"En retard" éventuel)
     occupe toujours la ligne suivante, quelle que soit la largeur
     disponible. Réservé aux emplacements étroits (colonnes de tableau,
     panneaux latéraux) — les en-têtes de page (ShowCourrier/EditForm, déjà
     assez larges) gardent leur disposition horizontale existante. --}}
@props(['courrier', 'masquerTemps' => false])

<div class="space-y-1">
    <x-statut-badge :statut="$courrier->statut" />
    <div class="flex flex-wrap items-center gap-1">
        <x-chronometre :courrier="$courrier" :masquer-temps="$masquerTemps" />
    </div>
</div>
