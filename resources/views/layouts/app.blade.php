{{-- Fond posé ICI directement, pas seulement sur <body> (2026-09-18,
     demande explicite de l'utilisateur : le fond de la zone de contenu
     principal ne correspondait pas à celui de l'image) — la zone
     [grid-area:main] de <flux:main> n'a par défaut AUCUN fond propre
     (transparente, voir vendor/livewire/flux/stubs/.../flux/main.blade.php),
     dépendant donc de ce qui est visuellement derrière elle plutôt que
     d'afficher la couleur de façon garantie. Posé explicitement sur cette
     boîte précise pour ne plus dépendre de cette transparence. Couleur
     exacte #F5F8FC (2026-09-18, demande explicite de l'utilisateur) — déjà
     le token --color-brand-surface (app.css, "fond de page général") plutôt
     qu'une nouvelle valeur ad hoc. --}}
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="bg-brand-surface">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
