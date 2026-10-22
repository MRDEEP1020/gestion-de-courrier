@props(['noeud', 'dossierIdSelectionne', 'tousLesDossiers'])

{{--
    Module 3 — nœud récursif de l'arborescence des dossiers de classement
    (2026-09-21). PREMIER composant récursif de ce projet — voir
    <ui-disclosure> (Flux, vendor/livewire/flux/dist/flux-lite.min.js) pour
    la primitive de repli/dépli déjà utilisée par la sidebar (un seul
    niveau) ; réutilisée ici en profondeur illimitée.

    Deux contraintes vérifiées directement dans le JS de <ui-disclosure> et
    à respecter scrupuleusement :
    1. button() = premier <button> du sous-arbre, dans l'ordre du DOM — le
       chevron DOIT être un <button>, et DOIT précéder la ligne
       cliquable/sélectionnable du nom du dossier, qui elle NE DOIT PAS être
       un <button> (sinon querySelector risque de cibler le mauvais élément).
    2. details() = lastElementChild — un nœud SANS enfant ne doit jamais
       être enveloppé dans <ui-disclosure> (le panneau replié/déplié
       résoudrait alors sur la ligne elle-même). Un nœud feuille est rendu
       en simple ligne, sans chevron.
--}}
@php
    $dossier = $noeud['dossier'];
    $total = $dossier->compterDocumentsDescendants($tousLesDossiers);
    $selectionne = $dossierIdSelectionne === $dossier->id;
    $aDesEnfants = count($noeud['enfants']) > 0;
    $classesLigne = 'flex flex-1 min-w-0 cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/60'
        .($selectionne ? ' bg-brand-accent-pale font-medium text-brand-accent dark:bg-zinc-800' : '');
    $classesCompteur = 'shrink-0 rounded-full px-1.5 py-0.5 text-xs '
        .($selectionne ? 'bg-white text-brand-accent' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800');
@endphp

@if ($aDesEnfants)
    <ui-disclosure {{ $attributes->class('group/dossier') }}>
        <div class="flex items-center gap-1">
            <button type="button" class="flex size-6 shrink-0 items-center justify-center text-zinc-400 hover:text-zinc-600">
                <flux:icon.chevron-down class="hidden size-3! group-data-open/dossier:block" />
                <flux:icon.chevron-right class="block size-3! group-data-open/dossier:hidden" />
            </button>
            <div wire:click="selectionnerDossier({{ $dossier->id }})" class="{{ $classesLigne }}">
                <span class="truncate">{{ $dossier->nom }}</span>
                <span class="{{ $classesCompteur }}">{{ $total }}</span>
            </div>
        </div>
        <div class="relative hidden space-y-[2px] ps-7 data-open:block">
            <div class="absolute inset-y-[3px] start-0 ms-3 w-px bg-zinc-200 dark:bg-zinc-700"></div>
            @foreach ($noeud['enfants'] as $enfant)
                <x-dossier-tree-node :noeud="$enfant" :dossier-id-selectionne="$dossierIdSelectionne" :tous-les-dossiers="$tousLesDossiers" wire:key="dossier-noeud-{{ $enfant['dossier']->id }}" />
            @endforeach
        </div>
    </ui-disclosure>
@else
    <div wire:click="selectionnerDossier({{ $dossier->id }})" class="{{ $classesLigne }} pl-8">
        <span class="truncate">{{ $dossier->nom }}</span>
        <span class="{{ $classesCompteur }}">{{ $total }}</span>
    </div>
@endif
