{{-- Colonnes (une ou deux séries) — Dashboard statistiques (2026-10-30).
     Colonnes fines (12 px, 2 px entre deux séries), arrondies côté valeur,
     carrées sur l'axe ; traits de grille fins ; légende dès deux séries.
     Si $dimension est donné, chaque groupe de colonnes est cliquable et
     filtre la page (sélection en couleur, le reste estompé). Valeur null =
     pas de colonne (ex. aucun courrier évaluable ce mois-là). Données aussi
     dans « Voir les données » (version tableau). --}}
@props(['points' => [], 'series' => [], 'dimension' => null, 'selection' => '', 'maximum' => null, 'suffixe' => '', 'enTeteX' => ''])

@php
    $valeurs = [];
    foreach ($points as $point) {
        foreach ($series as $serie) {
            $valeurs[] = (int) ($point['valeurs'][$serie['cle']] ?? 0);
        }
    }
    $maximum ??= \App\Livewire\Backend\Statistiques::maximumArrondi(max(array_merge([1], $valeurs)));
@endphp

{{-- @container : dans une carte étroite, avec plus de 6 points, un libellé
     de l'axe sur deux seulement (sinon ils se tronquaient les uns les autres). --}}
<div class="@container">
@if (count($series) > 1)
    <div class="mt-2 flex flex-wrap items-center gap-4 text-sm text-brand-text-secondary">
        @foreach ($series as $serie)
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: {{ $serie['couleur'] }}"></span>{{ $serie['nom'] }}</span>
        @endforeach
    </div>
@endif

<div class="mt-4 flex gap-2">
    <div class="relative h-44 w-9 shrink-0 text-right text-xs tabular-nums text-zinc-500">
        <span class="absolute right-0 top-0 -translate-y-1/2">{{ $maximum }}{{ $suffixe }}</span>
        @if ($maximum % 2 === 0)
            <span class="absolute right-0 top-1/2 -translate-y-1/2">{{ $maximum / 2 }}{{ $suffixe }}</span>
        @endif
        <span class="absolute bottom-0 right-0 translate-y-1/2">0</span>
    </div>
    <div class="relative h-44 min-w-0 flex-1">
        <div class="absolute inset-x-0 top-0 border-t border-brand-border"></div>
        @if ($maximum % 2 === 0)
            <div class="absolute inset-x-0 top-1/2 border-t border-brand-border"></div>
        @endif
        <div class="absolute inset-x-0 bottom-0 border-t border-zinc-300 dark:border-zinc-600"></div>
        <div class="absolute inset-0 flex items-end justify-around">
            @foreach ($points as $point)
                @php
                    $choisi = $selection !== '' && (string) $point['cle'] === (string) $selection;
                    $estompe = $selection !== '' && ! $choisi;
                @endphp
                {{-- Bouton seulement si le graphique filtre la page : un bouton
                     désactivé bloquerait aussi le survol (info-bulles). --}}
                @php $balise = $dimension ? 'button' : 'div'; @endphp
                <div wire:key="colonne-{{ $dimension }}-{{ $point['cle'] }}" @class(['flex h-full flex-1 justify-center transition-opacity', 'opacity-40' => $estompe])>
                    <{{ $balise }}
                        @if ($dimension) type="button" wire:click="filtrer('{{ $dimension }}', @js((string) $point['cle']))" aria-pressed="{{ $choisi ? 'true' : 'false' }}" @endif
                        aria-label="{{ $point['libelle'] }}"
                        @class(['flex h-full items-end justify-center gap-0.5 rounded-md px-1.5 focus-visible:outline-2 focus-visible:outline-brand-accent', 'cursor-pointer hover:bg-brand-surface-soft dark:hover:bg-zinc-800' => $dimension, 'bg-brand-surface-soft dark:bg-zinc-800' => $choisi])>
                        @foreach ($series as $serie)
                            @php $valeur = $point['valeurs'][$serie['cle']] ?? null; @endphp
                            @if ($valeur)
                                <flux:tooltip :content="\Illuminate\Support\Str::ucfirst($point['libelle']).' — '.$serie['nom'].' : '.$valeur.$suffixe" class="flex h-full items-end">
                                    <span class="block w-3 rounded-t-[4px]" style="height: {{ round(100 * min($valeur, $maximum) / $maximum, 2) }}%; background: {{ $serie['couleur'] }}"></span>
                                </flux:tooltip>
                            @else
                                <span class="block w-3"></span>
                            @endif
                        @endforeach
                    </{{ $balise }}>
                </div>
            @endforeach
        </div>
    </div>
</div>
<div class="mt-2 flex gap-2">
    <div class="w-9 shrink-0"></div>
    <div class="flex min-w-0 flex-1 justify-around">
        @foreach ($points as $point)
            {{-- Un sur deux en partant du dernier point : le mois le plus récent garde toujours son libellé. --}}
            <span @class(['flex-1 truncate text-center text-xs text-zinc-500', '@max-[36rem]:invisible' => count($points) > 6 && ($loop->remaining % 2 === 1)]) title="{{ $point['libelle'] }}">{{ $point['court'] }}</span>
        @endforeach
    </div>
</div>

<details class="mt-3">
    <summary class="cursor-pointer text-sm font-medium text-brand-accent">{{ __('Voir les données') }}</summary>
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400">
                <tr>
                    <th class="py-3 pl-4 pr-3">{{ $enTeteX }}</th>
                    @foreach ($series as $serie)
                        <th class="py-3 pr-4 text-right">{{ $serie['nom'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($points as $point)
                    <tr>
                        <td class="py-3 pl-4 pr-3">{{ \Illuminate\Support\Str::ucfirst($point['libelle']) }}</td>
                        @foreach ($series as $serie)
                            @php $valeur = $point['valeurs'][$serie['cle']] ?? null; @endphp
                            <td class="py-3 pr-4 text-right tabular-nums">{{ $valeur === null ? '—' : $valeur.$suffixe }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</details>
</div>
