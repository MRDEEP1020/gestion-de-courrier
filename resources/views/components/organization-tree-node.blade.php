@props(['noeud', 'noeudIdSelectionne', 'tousLesNoeuds'])

{{--
    Module "Organisation" v2 — nœud récursif de l'arborescence Company →
    Site → Department → Service → Sub-service (2026-09-22). Copie directe du
    patron déjà éprouvé deux fois dans ce projet (<x-dossier-tree-node>,
    Module 3) — voir ce fichier pour le détail des contraintes
    <ui-disclosure> (Flux) :
    1. Le chevron DOIT être un <button>, premier <button> du sous-arbre, et
       DOIT précéder la ligne cliquable du nom (qui elle NE DOIT PAS être un
       <button>).
    2. Un nœud SANS enfant n'est jamais enveloppé dans <ui-disclosure> — rendu
       en simple ligne, sans chevron.
    IMPORTANT (piège réel rencontré cette session) : jamais
    <flux:icon.{{ $variable }} /> (interpolation dans le NOM d'un tag de
    composant) — toujours <flux:icon :icon="$variable" />, sinon la
    compilation Blade peut épuiser toute la mémoire PHP.
--}}
@php
    $unite = $noeud['noeud'];
    $totalUtilisateurs = $unite->compterUtilisateursDescendants($tousLesNoeuds);
    $selectionne = $noeudIdSelectionne === $unite->id;
    $aDesEnfants = count($noeud['enfants']) > 0;
    $classesLigne = 'flex flex-1 min-w-0 cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/60'
        .($selectionne ? ' bg-brand-accent-pale font-medium text-brand-accent dark:bg-zinc-800' : '')
        .(! $unite->estActif() ? ' opacity-50' : '');
    $classesCompteur = 'shrink-0 rounded-full px-1.5 py-0.5 text-xs '
        .($selectionne ? 'bg-white text-brand-accent' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800');
    $icone = match ($unite->type) {
        \App\Models\OrganizationUnit::TYPE_COMPANY => 'building-office-2',
        \App\Models\OrganizationUnit::TYPE_SITE => 'map-pin',
        \App\Models\OrganizationUnit::TYPE_DIRECTION => 'building-office',
        \App\Models\OrganizationUnit::TYPE_DEPARTMENT => 'folder',
        \App\Models\OrganizationUnit::TYPE_SUB_SERVICE => 'user-group',
        default => 'users',
    };
@endphp

@if ($aDesEnfants)
    <ui-disclosure {{ $attributes->class('group/unite') }}>
        <div class="flex items-center gap-1">
            <button type="button" class="flex size-6 shrink-0 items-center justify-center text-zinc-400 hover:text-zinc-600">
                <flux:icon.chevron-down class="hidden size-3! group-data-open/unite:block" />
                <flux:icon.chevron-right class="block size-3! group-data-open/unite:hidden" />
            </button>
            <div wire:click="selectionnerNoeud({{ $unite->id }})" class="{{ $classesLigne }}">
                <span class="flex min-w-0 items-center gap-1.5 truncate">
                    <flux:icon :icon="$icone" class="size-3.5 shrink-0 text-zinc-400" />
                    <span class="truncate">{{ $unite->name }}</span>
                </span>
                <span class="{{ $classesCompteur }}">{{ $totalUtilisateurs }}</span>
            </div>
        </div>
        <div class="relative hidden space-y-[2px] ps-7 data-open:block">
            <div class="absolute inset-y-[3px] start-0 ms-3 w-px bg-zinc-200 dark:bg-zinc-700"></div>
            {{-- 2026-10-09 — retour utilisateur direct ("it should work
                 like the sidebar on those dropdown when one is open the
                 rest is close") : ui-disclosure-group exclusive (même
                 primitive Flux native que sidebar.blade.php), SCOPÉ À CE
                 NIVEAU — un seul enfant déplié à la fois PARMI LES
                 FRÈRES de ce nœud ; chaque niveau de l'arbre a son propre
                 groupe (récursif), indépendant des autres branches. --}}
            <ui-disclosure-group exclusive>
                @foreach ($noeud['enfants'] as $enfant)
                    <x-organization-tree-node :noeud="$enfant" :noeud-id-selectionne="$noeudIdSelectionne" :tous-les-noeuds="$tousLesNoeuds" wire:key="unite-noeud-{{ $enfant['noeud']->id }}" />
                @endforeach
            </ui-disclosure-group>
        </div>
    </ui-disclosure>
@else
    <div wire:click="selectionnerNoeud({{ $unite->id }})" class="{{ $classesLigne }} pl-8">
        <span class="flex min-w-0 items-center gap-1.5 truncate">
            <flux:icon :icon="$icone" class="size-3.5 shrink-0 text-zinc-400" />
            <span class="truncate">{{ $unite->name }}</span>
        </span>
        <span class="{{ $classesCompteur }}">{{ $totalUtilisateurs }}</span>
    </div>
@endif
