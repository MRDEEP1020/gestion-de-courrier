{{-- Module 10 étendu — « Dashboard statistiques » façon Power BI (2026-10-30,
     voir DECISIONS.md). Sections : indicateurs, flux et délais (période),
     circuit et ancienneté (aujourd'hui), répartition des courriers reçus,
     charge par personne, détail par service. Un clic sur une barre, une
     colonne ou une ligne de tableau filtre toute la page (puces retirables,
     gardées dans l'URL) ; le graphique cliqué garde toutes ses barres et met
     la sélection en évidence. Graphiques : components/stats/* (guide de
     visualisation : barres fines, grille fine, info-bulles, tableaux). --}}
@php
    $calculeLe = $this->calculeLe;
    $carte = 'rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900';
    $enTeteTableau = 'border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400';
    $jours = fn ($n) => $n === null ? '—' : __(':n j', ['n' => str_replace('.', ',', (string) $n)]);
@endphp

<section class="statistiques w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
                <flux:icon.chart-bar class="size-5" />
            </div>
            <div>
                <flux:heading level="1">{{ __('Dashboard statistiques') }}</flux:heading>
                <flux:subheading>{{ __('Volumes, délais et retards des courriers de votre périmètre. Cliquez sur une barre pour filtrer toute la page.') }}</flux:subheading>
            </div>
        </div>
        @if ($calculeLe)
            <flux:text class="text-xs text-zinc-500">{{ __('Chiffres mis à jour :quand', ['quand' => $calculeLe->diffForHumans()]) }}</flux:text>
        @endif
    </div>

    {{-- Filtres : une rangée au-dessus de tout ce qu'ils filtrent, puis les
         filtres posés au clic, sous forme de puces retirables. --}}
    <div class="space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-60">
                <flux:select wire:model.live="periode" :label="__('Période')">
                    <flux:select.option value="ce_mois">{{ __('Ce mois') }}</flux:select.option>
                    <flux:select.option value="3_mois">{{ __('3 derniers mois') }}</flux:select.option>
                    <flux:select.option value="12_mois">{{ __('12 derniers mois') }}</flux:select.option>
                    <flux:select.option value="cette_annee">{{ __('Cette année') }}</flux:select.option>
                    <flux:select.option value="annee_precedente">{{ __('Année précédente') }}</flux:select.option>
                </flux:select>
            </div>
            @if ($this->servicesDisponibles->count() > 1)
                <div class="w-full sm:w-72">
                    <flux:select wire:model.live="serviceId" :label="__('Service')">
                        <flux:select.option value="">{{ $this->perimetre === null ? __('Tous les services') : __('Tous mes services') }}</flux:select.option>
                        @foreach ($this->servicesDisponibles as $service)
                            <flux:select.option value="{{ $service->id }}">{{ $service->nom }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </div>

        @if ($this->filtresActifs !== [])
            <div class="flex flex-wrap items-center gap-2">
                <flux:text class="text-sm text-zinc-500">{{ __('Filtres :') }}</flux:text>
                @foreach ($this->filtresActifs as $puce)
                    <button type="button" wire:click="retirerFiltre('{{ $puce['dimension'] }}')" wire:key="puce-{{ $puce['dimension'] }}"
                        aria-label="{{ __('Retirer le filtre :nom', ['nom' => $puce['nom'].' : '.$puce['valeur']]) }}"
                        class="inline-flex items-center gap-1.5 rounded-full border border-brand-border bg-brand-surface-soft px-3 py-1 text-sm hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                        <span class="text-zinc-500">{{ $puce['nom'] }} :</span>
                        <span class="font-medium text-brand-text-primary">{{ $puce['valeur'] }}</span>
                        <flux:icon.x-mark variant="micro" class="size-4 text-zinc-500" />
                    </button>
                @endforeach
                <flux:button size="sm" variant="ghost" wire:click="effacerFiltres">{{ __('Tout effacer') }}</flux:button>
            </div>
        @endif
    </div>

    @if ($this->perimetre === [])
        <flux:callout icon="information-circle">
            <flux:callout.text>{{ __('Aucun service n\'est rattaché à votre compte : il n\'y a pas de statistiques à afficher.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($calculeLe === null)
        <flux:callout icon="clock">
            <flux:callout.heading>{{ __('Statistiques en cours de calcul') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Elles seront prêtes dans quelques instants : rechargez la page.') }}</flux:callout.text>
        </flux:callout>
    @else
        @php
            $indicateurs = $this->indicateurs;
            $fluxMensuels = $this->flux;
            // 'couleur' : repère de la phase (reçu bleu, clôturé vert, en retard
            // rouge) à côté du libellé — le texte garde ses couleurs de texte.
            $tuiles = [
                ['libelle' => __('Courriers reçus'), 'valeur' => number_format($indicateurs['recus'], 0, ',', ' '), 'aide' => null, 'couleur' => 'var(--serie-recu)'],
                ['libelle' => __('Courriers envoyés'), 'valeur' => number_format($indicateurs['envoyes'], 0, ',', ' '), 'aide' => null, 'couleur' => null],
                ['libelle' => __('Courriers traités'), 'valeur' => number_format($indicateurs['traites'], 0, ',', ' '), 'aide' => null, 'couleur' => 'var(--serie-cloture)'],
                ['libelle' => __('Délai moyen'), 'valeur' => $jours($indicateurs['delaiMoyen']), 'aide' => __('de la réception à la clôture'), 'couleur' => null],
                ['libelle' => __('Dans les délais'), 'valeur' => $indicateurs['dansLesDelais'] === null ? '—' : $indicateurs['dansLesDelais'].' %', 'aide' => __('des courriers traités'), 'couleur' => 'var(--serie-cloture)'],
                ['libelle' => __('En retard'), 'valeur' => number_format($indicateurs['enRetard'], 0, ',', ' '), 'aide' => __('aujourd\'hui, sur :n en cours', ['n' => $indicateurs['enCours']]), 'couleur' => 'var(--serie-retard)'],
            ];
            // Repère coloré d'une colonne de tableau (même code couleur).
            $repere = fn (string $couleur) => '<span class="mr-1.5 inline-block size-2 rounded-full align-middle" style="background: '.$couleur.'"></span>';
        @endphp

        {{-- Rechargement : le contenu reste en place, atténué (pas de saut). --}}
        <div class="space-y-8 transition-opacity" wire:loading.class="opacity-50">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                @foreach ($tuiles as $tuile)
                    <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <flux:text class="flex items-center gap-1.5 text-sm text-zinc-500">
                            @if ($tuile['couleur'])
                                <span class="inline-block h-3.5 w-1 shrink-0 rounded-full" style="background: {{ $tuile['couleur'] }}"></span>
                            @endif
                            {{ $tuile['libelle'] }}
                        </flux:text>
                        <div class="mt-1 text-2xl font-semibold text-brand-text-primary">{{ $tuile['valeur'] }}</div>
                        @if ($tuile['aide'])
                            <flux:text class="mt-0.5 text-xs text-zinc-500">{{ $tuile['aide'] }}</flux:text>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- ===== Flux et délais (sur la période) ===== --}}
            <div class="space-y-4">
                <div>
                    <flux:heading level="2" size="lg">{{ __('Flux et délais') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">{{ __('Courriers entrants, mois par mois sur la période. Cliquez sur un mois pour filtrer la page.') }}</flux:text>
                </div>
                <div class="{{ $carte }}">
                    <flux:heading level="3">{{ __('Reçus et clôturés par mois') }}</flux:heading>
                    <x-stats.colonnes dimension="mois" :selection="$mois" :en-tete-x="__('Mois')"
                        :series="[['cle' => 'recus', 'nom' => __('Reçus'), 'couleur' => 'var(--serie-recu)'], ['cle' => 'clotures', 'nom' => __('Clôturés'), 'couleur' => 'var(--serie-cloture)']]"
                        :points="array_map(fn ($p) => $p + ['valeurs' => ['recus' => $p['recus'], 'clotures' => $p['clotures']]], $fluxMensuels)" />
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="{{ $carte }}">
                        <flux:heading level="3">{{ __('Stock en cours en fin de mois') }}</flux:heading>
                        <flux:text class="text-xs text-zinc-500">{{ __('Courriers reçus et pas encore clôturés ni rejetés à la fin de chaque mois.') }}</flux:text>
                        <x-stats.colonnes dimension="mois" :selection="$mois" :en-tete-x="__('Mois')"
                            :series="[['cle' => 'stock', 'nom' => __('En cours'), 'couleur' => 'var(--serie-recu)']]"
                            :points="array_map(fn ($p) => $p + ['valeurs' => ['stock' => $p['stock']]], $fluxMensuels)" />
                    </div>
                    <div class="{{ $carte }}">
                        <flux:heading level="3">{{ __('Clôturés dans les délais') }}</flux:heading>
                        <flux:text class="text-xs text-zinc-500">{{ __('Part des courriers clôturés ce mois-là avant leur échéance.') }}</flux:text>
                        <x-stats.colonnes dimension="mois" :selection="$mois" :en-tete-x="__('Mois')" :maximum="100" suffixe=" %"
                            :series="[['cle' => 'delais', 'nom' => __('Dans les délais'), 'couleur' => 'var(--serie-cloture)']]"
                            :points="array_map(fn ($p) => $p + ['valeurs' => ['delais' => $p['dansLesDelais']]], $fluxMensuels)" />
                    </div>
                </div>
            </div>

            {{-- ===== Circuit et ancienneté (aujourd'hui) ===== --}}
            <div class="space-y-4">
                <div>
                    <flux:heading level="2" size="lg">{{ __('Circuit et ancienneté') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">{{ __('Courriers encore en cours aujourd\'hui (la période ne s\'applique pas). Montre où le courrier s\'accumule.') }}</flux:text>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="{{ $carte }}">
                        <flux:heading level="3">{{ __('En cours par étape du circuit') }}</flux:heading>
                        <x-stats.barres dimension="etape" :selection="$etape" :lignes="$this->parEtape" :vide="__('Aucun courrier en cours.')" :nom-serie="__('Dans les temps')" />
                    </div>
                    <div class="{{ $carte }}">
                        <flux:heading level="3">{{ __('Depuis combien de temps ils attendent') }}</flux:heading>
                        <x-stats.colonnes dimension="anciennete" :selection="$anciennete" :en-tete-x="__('Ancienneté')"
                            :series="[['cle' => 'enCours', 'nom' => __('En cours'), 'couleur' => 'var(--serie-recu)'], ['cle' => 'retard', 'nom' => __('Dont en retard'), 'couleur' => 'var(--serie-retard)']]"
                            :points="$this->parAnciennete" />
                    </div>
                </div>
            </div>

            {{-- ===== Répartition des courriers reçus (sur la période) ===== --}}
            <div class="space-y-4">
                <div>
                    <flux:heading level="2" size="lg">{{ __('Répartition des courriers reçus') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">{{ __('Courriers entrants reçus sur la période.') }}</flux:text>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="{{ $carte }}">
                        <flux:heading level="3">{{ __('Par service') }}</flux:heading>
                        <x-stats.barres dimension="service" :selection="$serviceId" :lignes="$this->parService" />
                    </div>
                    <div class="{{ $carte }}">
                        <flux:heading level="3">{{ __('Principaux expéditeurs') }}</flux:heading>
                        <x-stats.barres dimension="expediteur" :selection="$expediteur" :lignes="$this->parExpediteur" />
                    </div>
                    <div class="{{ $carte }}">
                        <flux:heading level="3">{{ __('Par type de document') }}</flux:heading>
                        <x-stats.barres dimension="type" :selection="$type" :lignes="$this->parType" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                        <div class="{{ $carte }}">
                            <flux:heading level="3">{{ __('Par priorité') }}</flux:heading>
                            <x-stats.barres dimension="priorite" :selection="$priorite" :lignes="$this->parPriorite" />
                        </div>
                        <div class="{{ $carte }}">
                            <flux:heading level="3">{{ __('Par canal de réception') }}</flux:heading>
                            <x-stats.barres dimension="canal" :selection="$canal" :lignes="$this->parCanal" />
                        </div>
                    </div>
                </div>
            </div>

            {{-- ===== Charge par personne ===== --}}
            <div class="{{ $carte }}">
                <flux:heading level="2" size="lg">{{ __('Charge par personne') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">{{ __('En cours et en retard aujourd\'hui ; clôturés et délai moyen sur la période. Cliquez sur une personne pour filtrer la page.') }}</flux:text>
                @if ($this->chargeParPersonne === [])
                    <flux:text class="mt-4 text-sm text-zinc-500">{{ __('Aucun courrier affecté.') }}</flux:text>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="{{ $enTeteTableau }}">
                                <tr>
                                    <th class="py-3 pl-4 pr-3">{{ __('Personne') }}</th>
                                    <th class="whitespace-nowrap py-3 pr-3 text-right">{{ __('En cours') }}</th>
                                    <th class="whitespace-nowrap py-3 pr-3 text-right">{!! $repere('var(--serie-retard)') !!}{{ __('En retard') }}</th>
                                    <th class="whitespace-nowrap py-3 pr-3 text-right">{!! $repere('var(--serie-cloture)') !!}{{ __('Clôturés') }}</th>
                                    <th class="whitespace-nowrap py-3 pr-4 text-right">{{ __('Délai moyen') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($this->chargeParPersonne as $ligne)
                                    @php $choisie = $collaborateurId === (string) $ligne['id']; @endphp
                                    <tr wire:key="personne-{{ $ligne['id'] }}" @class(['bg-brand-surface-soft dark:bg-zinc-800' => $choisie, 'opacity-40' => $collaborateurId !== '' && ! $choisie])>
                                        <td class="py-4 pl-4 pr-3">
                                            <button type="button" wire:click="filtrer('collaborateur', '{{ $ligne['id'] }}')" aria-pressed="{{ $choisie ? 'true' : 'false' }}"
                                                class="cursor-pointer text-left font-medium text-brand-accent hover:underline">{{ $ligne['nom'] }}</button>
                                        </td>
                                        <td class="py-4 pr-3 text-right tabular-nums">{{ $ligne['enCours'] }}</td>
                                        <td class="py-4 pr-3 text-right tabular-nums">{{ $ligne['enRetard'] }}</td>
                                        <td class="py-4 pr-3 text-right tabular-nums">{{ $ligne['clotures'] }}</td>
                                        <td class="py-4 pr-4 text-right tabular-nums">{{ $jours($ligne['delaiMoyen']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ===== Détail par service ===== --}}
            <div class="{{ $carte }}">
                <flux:heading level="2" size="lg">{{ __('Détail par service') }}</flux:heading>
                @if ($this->detailParService === [])
                    <flux:text class="mt-4 text-sm text-zinc-500">{{ __('Aucun courrier sur cette période.') }}</flux:text>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="{{ $enTeteTableau }}">
                                <tr>
                                    <th class="py-3 pl-4 pr-3">{{ __('Service') }}</th>
                                    <th class="py-3 pr-3 text-right">{!! $repere('var(--serie-recu)') !!}{{ __('Reçus') }}</th>
                                    <th class="py-3 pr-3 text-right">{{ __('Envoyés') }}</th>
                                    <th class="py-3 pr-3 text-right">{!! $repere('var(--serie-cloture)') !!}{{ __('Traités') }}</th>
                                    <th class="whitespace-nowrap py-3 pr-3 text-right">{{ __('Délai moyen') }}</th>
                                    <th class="whitespace-nowrap py-3 pr-3 text-right">{{ __('Dans les délais') }}</th>
                                    <th class="whitespace-nowrap py-3 pr-3 text-right">{{ __('En cours') }}</th>
                                    <th class="whitespace-nowrap py-3 pr-4 text-right">{!! $repere('var(--serie-retard)') !!}{{ __('En retard') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($this->detailParService as $ligne)
                                    @php $choisi = $serviceId === (string) $ligne['id']; @endphp
                                    <tr wire:key="service-{{ $ligne['id'] }}" @class(['bg-brand-surface-soft dark:bg-zinc-800' => $choisi, 'opacity-40' => $serviceId !== '' && ! $choisi])>
                                        <td class="py-4 pl-4 pr-3">
                                            <button type="button" wire:click="filtrer('service', '{{ $ligne['id'] }}')" aria-pressed="{{ $choisi ? 'true' : 'false' }}"
                                                class="cursor-pointer text-left font-medium text-brand-accent hover:underline">{{ $ligne['service'] }}</button>
                                        </td>
                                        <td class="py-4 pr-3 text-right tabular-nums">{{ $ligne['recus'] }}</td>
                                        <td class="py-4 pr-3 text-right tabular-nums">{{ $ligne['envoyes'] }}</td>
                                        <td class="py-4 pr-3 text-right tabular-nums">{{ $ligne['traites'] }}</td>
                                        <td class="py-4 pr-3 text-right tabular-nums">{{ $jours($ligne['delaiMoyen']) }}</td>
                                        <td class="py-4 pr-3 text-right tabular-nums">{{ $ligne['dansLesDelais'] === null ? '—' : $ligne['dansLesDelais'].' %' }}</td>
                                        <td class="py-4 pr-3 text-right tabular-nums">{{ $ligne['enCours'] }}</td>
                                        <td class="py-4 pr-4 text-right tabular-nums">{{ $ligne['enRetard'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <flux:text class="mt-3 text-xs text-zinc-500">{{ __('« En cours » et « En retard » : situation d\'aujourd\'hui, quelle que soit la période choisie.') }}</flux:text>
                @endif
            </div>
        </div>
    @endif
</section>
