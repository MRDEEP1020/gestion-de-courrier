{{--
    Chrome restylé "visionneuse Office" (maquette replica.html fournie par
    l'utilisateur, 2026-10-02) — ligne d'accent bleue + bandeau fichier +
    actions, puis une seconde ligne pagination/recherche n'apparaissant que
    si nécessaire. Purement présentatiel : ce composant ne déclare AUCUN
    x-data propre, il référence les propriétés/méthodes Alpine du
    x-data PARENT (zoom, zoomIn(), zoomOut(), pageActuelle, numPages,
    pageSuivante(), pagePrecedente()) — un contrat déjà identique sur les
    8 panneaux/modales d'aperçu du projet (voir
    document-preview.js), ce qui permet de factoriser ce chrome sans toucher
    à la logique pdf.js existante.

    Couleurs/typo/rayons repris LITTÉRALEMENT de replica.html (bleu Word
    #185abd/#2b7cd3, gris #e1e1e1/#f7f7f7) — demande explicite de
    l'utilisateur ("replica.html est la source de vérité visuelle... y
    compris les couleurs"), volontairement différents de la palette
    --color-brand-* du reste de l'application : ce chrome imite une
    visionneuse Office, pas le design system GEC.
--}}
@props([
    'variant' => 'panneau', // 'panneau' (panneau latéral compact) | 'modale' (grande vue "Agrandir")
    'nomFichier' => null,
    'numeroReference' => null,
    'telechargerUrl' => null,
    'agrandirEvenement' => null, // nom de modale à ouvrir (bouton d'agrandissement) — variant panneau
    'fermerModale' => null,      // variant modale uniquement (bouton de fermeture)
])

@php
    // Badge coloré par TYPE RÉEL de fichier (2026-10-05, retour utilisateur
    // "use word too as outlook does" — Outlook badge chaque pièce jointe de
    // l'icône/couleur de l'application qui l'ouvrirait, pas toujours
    // "Word"). GEC n'accepte que PDF/JPG/PNG à l'upload (ScanForm/EditForm,
    // jamais de .docx) : la lettre "W" bleue de la maquette ne serait donc
    // jamais honnête ici — le badge suit l'extension réelle du fichier
    // (systématiquement "PDF" en pratique), avec la couleur Word #2b7cd3 de
    // la maquette conservée pour les .doc/.docx, au cas improbable où.
    $extension = strtolower(pathinfo($nomFichier ?? '', PATHINFO_EXTENSION));
    [$badgeLettre, $badgeCouleur, $badgeLibelle] = match ($extension) {
        'doc', 'docx' => ['W', '#2b7cd3', __('Word')],
        'jpg', 'jpeg', 'png' => ['I', '#6b7280', __('Image')],
        default => ['P', '#c7392a', __('PDF')],
    };
@endphp

<div class="overflow-hidden rounded-t-lg border border-[#e1e1e1] bg-white dark:border-zinc-700">
    <div class="h-[3px]" style="background-color: {{ $badgeCouleur }}"></div>

    <div class="flex items-center justify-between gap-2 px-3 py-2">
        <div class="flex min-w-0 items-center gap-2">
            <span class="flex size-6 shrink-0 items-center justify-center rounded-[3px] text-[11px] font-bold text-white" style="background-color: {{ $badgeCouleur }}">
                {{ $badgeLettre }}
            </span>
            <span class="shrink-0 text-[13.5px] font-semibold" style="color: {{ $badgeCouleur }}">{{ $badgeLibelle }}</span>
            <span class="truncate text-[13.5px] text-[#242424] dark:text-zinc-100">
                {{ $nomFichier ?: __('Document') }}@if ($numeroReference) <span class="text-[#616161] dark:text-zinc-400">— {{ $numeroReference }}</span>@endif
            </span>
        </div>

        <div class="flex shrink-0 items-center gap-0.5">
            <flux:button size="sm" variant="ghost" icon="minus" x-on:click="zoomOut()" :aria-label="__('Zoom -')" />
            <span class="w-10 text-center text-xs text-[#616161] dark:text-zinc-400" x-text="zoom + '%'"></span>
            <flux:button size="sm" variant="ghost" icon="plus" x-on:click="zoomIn()" :aria-label="__('Zoom +')" />

            <span class="mx-1 h-4 w-px bg-[#e1e1e1] dark:bg-zinc-700"></span>
            {{-- "Imprimer" ouvre l'URL d'aperçu (déjà dans le x-data parent
                 sous `url`, servie en Content-Disposition: inline par
                 CourrierDocumentApercuController/BrouillonDocumentApercuController)
                 dans un nouvel onglet : le visualiseur PDF natif du
                 navigateur y expose sa propre action d'impression — pas de
                 nouvelle route, pas de window.print() hasardeux sur un
                 document pas encore chargé dans l'onglet cible. --}}
            <flux:button size="sm" variant="ghost" icon="printer" x-on:click="window.open(url, '_blank')" :aria-label="__('Imprimer')" />

            @if ($telechargerUrl)
                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="$telechargerUrl" :aria-label="__('Télécharger')" />
            @endif

            @if ($agrandirEvenement)
                <flux:button size="sm" variant="ghost" icon="arrows-pointing-out" x-on:click="$dispatch('modal-show', { name: '{{ $agrandirEvenement }}' })" :aria-label="__('Voir en plein écran')" />
            @endif

            @if ($fermerModale)
                {{-- Pas de prop `name` : partout ailleurs dans le projet,
                     <flux:modal.close> ferme simplement le <flux:modal>
                     englobant le plus proche (voir les autres modales du
                     projet) — ce composant est toujours rendu À L'INTÉRIEUR
                     du <flux:modal> qu'il doit fermer. --}}
                <flux:modal.close>
                    <flux:button size="sm" variant="ghost" icon="x-mark" :aria-label="__('Fermer')" />
                </flux:modal.close>
            @endif
        </div>
    </div>

    @if ($variant === 'modale')
        <div x-show="numPages > 1" x-cloak class="flex items-center justify-center gap-3 border-t border-[#e1e1e1] bg-[#f7f7f7] px-4 py-1.5 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:button size="xs" variant="ghost" icon="chevron-left" x-on:click="pagePrecedente()" x-bind:disabled="pageActuelle <= 1" :aria-label="__('Page précédente')" />
            <span class="text-[13px] text-[#424242] dark:text-zinc-400">
                {{ __('Page') }} <span x-text="pageActuelle"></span> {{ __('sur') }} <span x-text="numPages"></span>
            </span>
            <flux:button size="xs" variant="ghost" icon="chevron-right" x-on:click="pageSuivante()" x-bind:disabled="pageActuelle >= numPages" :aria-label="__('Page suivante')" />
        </div>
    @else
        <div x-show="numPages > 1" x-cloak class="flex items-center justify-center gap-2 border-t border-[#e1e1e1] bg-[#f7f7f7] px-3 py-1 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:button size="xs" variant="ghost" icon="chevron-left" x-on:click="pagePrecedente()" x-bind:disabled="pageActuelle <= 1" :aria-label="__('Page précédente')" />
            <span class="text-xs text-[#424242] dark:text-zinc-400"><span x-text="pageActuelle"></span> / <span x-text="numPages"></span></span>
            <flux:button size="xs" variant="ghost" icon="chevron-right" x-on:click="pageSuivante()" x-bind:disabled="pageActuelle >= numPages" :aria-label="__('Page suivante')" />
        </div>
    @endif
</div>
