{{-- Barres horizontales cliquables — Dashboard statistiques (2026-10-30).
     Une série (le titre de la carte la nomme) ; si les lignes portent une
     clé 'retard', la part en retard est dessinée en rouge au bout de la
     barre (2 px d'écart entre les deux segments) avec une légende. Toute la
     ligne est la cible du clic et du clavier : un clic filtre la page sur
     cette valeur (Statistiques::filtrer()), un second le retire ; la
     sélection reste en couleur, les autres barres s'estompent. Valeur au bout
     de la barre, info-bulle au survol et au focus. Couleurs : une par phase
     (--serie-recu, --serie-cloture, --serie-retard, voir app.css). --}}
@props(['lignes' => [], 'dimension' => null, 'selection' => '', 'vide' => null, 'couleur' => 'var(--serie-recu)', 'nomSerie' => null])

@php
    $maximum = max(array_merge([1], array_column($lignes, 'valeur')));
    $avecRetard = collect($lignes)->contains(fn ($l) => array_key_exists('retard', $l));
    // Place gardée après la barre pour la valeur (plus large avec « · N en retard »).
    $reserve = $avecRetard ? '7.5rem' : '3rem';
@endphp

@if ($lignes === [])
    <flux:text class="mt-4 text-sm text-zinc-500">{{ $vide ?? __('Aucune donnée sur cette période.') }}</flux:text>
@else
    @if ($avecRetard)
        <div class="mt-2 flex flex-wrap items-center gap-4 text-sm text-brand-text-secondary">
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: {{ $couleur }}"></span>{{ $nomSerie ?? __('Dans les temps') }}</span>
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: var(--serie-retard)"></span>{{ __('En retard') }}</span>
        </div>
    @endif
    {{-- @container : dans une carte étroite, le libellé passe au-dessus de la
         barre (sinon la colonne de libellé ne laissait plus de place à la barre). --}}
    <ul class="@container mt-3 space-y-1">
        @foreach ($lignes as $ligne)
            @php
                $choisie = $selection !== '' && (string) $ligne['cle'] === (string) $selection;
                $estompee = $selection !== '' && ! $choisie;
                $retard = (int) ($ligne['retard'] ?? 0);
                $aLHeure = $ligne['valeur'] - $retard;
                $resume = $ligne['libelle'].' : '.$ligne['valeur'].($retard > 0 ? ' ('.__('dont :n en retard', ['n' => $retard]).')' : '');
            @endphp
            <li wire:key="barre-{{ $dimension }}-{{ md5((string) $ligne['cle']) }}">
                <button type="button"
                    @if ($dimension) wire:click="filtrer('{{ $dimension }}', @js((string) $ligne['cle']))" @endif
                    aria-pressed="{{ $choisie ? 'true' : 'false' }}"
                    aria-label="{{ $resume }}"
                    class="grid w-full cursor-pointer grid-cols-1 items-center gap-1 rounded-md px-1 py-1.5 text-left text-sm transition-opacity @min-[26rem]:grid-cols-[minmax(0,11rem)_1fr] @min-[26rem]:gap-3 hover:bg-brand-surface-soft focus-visible:outline-2 focus-visible:outline-brand-accent dark:hover:bg-zinc-800 {{ $estompee ? 'opacity-40' : '' }}">
                    <span @class(['truncate', 'font-semibold text-brand-text-primary' => $choisie, 'text-brand-text-secondary' => ! $choisie]) title="{{ $ligne['libelle'] }}">{{ $ligne['libelle'] }}</span>
                    <span class="flex min-w-0 items-center gap-2">
                        <flux:tooltip :content="$resume" class="flex gap-0.5" style="width: calc((100% - {{ $reserve }}) * {{ round($ligne['valeur'] / $maximum, 4) }})">
                            @if ($aLHeure > 0)
                                <span @class(['block h-3', 'rounded-r-[4px]' => $retard === 0]) style="flex: {{ $aLHeure }} 1 0%; background: {{ $couleur }}"></span>
                            @endif
                            @if ($retard > 0)
                                <span class="block h-3 rounded-r-[4px]" style="flex: {{ $retard }} 1 0%; background: var(--serie-retard)"></span>
                            @endif
                        </flux:tooltip>
                        <span class="whitespace-nowrap text-xs font-medium tabular-nums text-brand-text-primary">
                            {{ $ligne['valeur'] }}@if ($retard > 0)<span class="font-normal text-brand-text-secondary"> · {{ __(':n en retard', ['n' => $retard]) }}</span>@endif
                        </span>
                    </span>
                </button>
            </li>
        @endforeach
    </ul>
@endif
