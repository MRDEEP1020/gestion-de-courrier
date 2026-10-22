{{-- Vue "Calendrier" des échéances SLA (2026-10-05, référence visuelle
     Outlook fournie par l'utilisateur) : chrome de navigation calqué sur la
     capture (mini-calendrier, Aujourd'hui/flèches, colonnes de jour), mais
     SANS grille horaire — date_limite est une DATE, pas de composante heure
     (voir commentaire d'en-tête de CourrierCalendar.php). Les courriers
     apparaissent en puces "toute la journée" sous l'en-tête de chaque
     colonne, comme Outlook le fait déjà pour ses événements sans heure. --}}
<section class="w-full">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item href="{{ route('courriers.rechercher') }}" wire:navigate>{{ __('Courriers') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Calendrier') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading level="1" class="mt-3">{{ __('Calendrier des échéances') }}</flux:heading>
    <flux:subheading>{{ __('Courriers par échéance SLA (date_limite), semaine de travail Lun-Ven.') }}</flux:subheading>

    <div class="mt-4 grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
        {{-- Mini-calendrier : navigation par mois, clic sur un jour pour y déplacer la semaine affichée. --}}
        <div class="shrink-0">
            <div class="flex items-center justify-between">
                <flux:text class="font-semibold capitalize">{{ $this->libelleMoisMini() }}</flux:text>
                <div class="flex items-center gap-1">
                    <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moisPrecedent" :aria-label="__('Mois précédent')" />
                    <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moisSuivant" :aria-label="__('Mois suivant')" />
                </div>
            </div>

            <div class="mt-2 grid grid-cols-7 gap-y-1 text-center text-xs text-zinc-400">
                @foreach (['L', 'M', 'M', 'J', 'V', 'S', 'D'] as $initiale)
                    <span>{{ $initiale }}</span>
                @endforeach
            </div>

            @foreach ($this->joursMoisMini() as $semaine)
                <div class="grid grid-cols-7 gap-y-1 text-center text-sm">
                    @foreach ($semaine as $jour)
                        <button
                            type="button"
                            wire:click="selectionnerJour('{{ $jour['date'] }}')"
                            @class([
                                'mx-auto flex size-7 items-center justify-center rounded-full',
                                'text-zinc-300 dark:text-zinc-600' => $jour['horsMois'],
                                'text-black dark:text-white' => ! $jour['horsMois'] && ! $jour['estDansSemaineAffichee'],
                                'bg-brand-accent text-white' => $jour['estAujourdhui'],
                                'bg-brand-accent-pale text-brand-accent' => $jour['estDansSemaineAffichee'] && ! $jour['estAujourdhui'],
                            ])
                        >
                            {{ $jour['numero'] }}
                        </button>
                    @endforeach
                </div>
            @endforeach
        </div>

        {{-- Semaine de travail : en-tête de navigation + 5 colonnes Lun-Ven. --}}
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-3">
                <flux:button variant="outline" wire:click="allerAujourdhui">{{ __("Aujourd'hui") }}</flux:button>
                <div class="flex items-center gap-1">
                    <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="semainePrecedente" :aria-label="__('Semaine précédente')" />
                    <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="semaineSuivante" :aria-label="__('Semaine suivante')" />
                </div>
                <flux:heading level="2" class="capitalize">{{ $this->libellePeriode() }}</flux:heading>
            </div>

            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                {{-- En-têtes de jour (numéro + libellé) : seule partie qui reste
                     hors de la zone qui défile. --}}
                <div class="grid grid-cols-[3rem_repeat(5,1fr)] gap-px bg-zinc-200 dark:bg-zinc-700">
                    <div class="bg-white dark:bg-zinc-900"></div>
                    @foreach ($this->joursSemaine() as $jour)
                        <div class="bg-white p-2 dark:bg-zinc-900">
                            <div class="flex items-baseline gap-1.5 {{ $jour['estAujourdhui'] ? 'text-brand-accent' : '' }}">
                                <span class="text-lg font-semibold">{{ $jour['numero'] }}</span>
                                <span class="text-xs text-zinc-400">{{ $jour['libelle'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Grille horaire (2026-10-05, "THE SHOULD BE TIME LIKE FOR
                     OUTLOOK") — le bandeau "toute la journée" (courriers sans
                     heure, date_limite seule) VIT DÉSORMAIS DANS la même zone
                     qui défile que les heures ("WHY THERE UP IT SHOULD BE
                     INSIDE THE TIMELINE", 2026-10-05) : épinglé en haut via
                     `sticky` au lieu d'être dans un bloc séparé au-dessus,
                     comme Outlook le fait réellement (bandeau collé en haut
                     de la grille horaire, pas une boîte à part). Un courrier
                     AVEC une heure réelle (chrono_fin_le) est positionné dans
                     la grille en dessous à sa vraie heure. --}}
                <div
                    class="relative max-h-[70vh] overflow-y-auto border-t border-zinc-200 dark:border-zinc-700"
                    x-data
                    x-init="
                        const majLigne = () => {
                            const n = new Date();
                            const base = $refs.bandeauJournee ? $refs.bandeauJournee.offsetHeight : 0;
                            const offset = base + (n.getHours() + n.getMinutes() / 60) * 64;
                            if ($refs.ligneMaintenant) { $refs.ligneMaintenant.style.top = offset + 'px'; }
                        };
                        majLigne();
                        const baseScroll = $refs.bandeauJournee ? $refs.bandeauJournee.offsetHeight : 0;
                        $el.scrollTop = Math.max(0, baseScroll + (new Date().getHours() - 2) * 64);
                        setInterval(majLigne, 30000);
                    "
                >
                    <div x-ref="bandeauJournee" class="sticky top-0 z-30 grid grid-cols-[3rem_repeat(5,1fr)] gap-px bg-zinc-200 dark:bg-zinc-700">
                        <div class="bg-white dark:bg-zinc-900"></div>
                        @foreach ($this->joursSemaine() as $jour)
                            @php $donneesJour = $this->courriersParJour()[$jour['date']]; @endphp
                            <div class="space-y-1 bg-white p-2 dark:bg-zinc-900">
                                @foreach ($donneesJour['puces'] as $puce)
                                    @php
                                        $titre = $puce['numero_reference'];
                                        if ($puce['affecte_nom']) {
                                            $titre .= ' — '.__('Affecté à :nom', ['nom' => $puce['affecte_nom']]);
                                        }
                                        if ($puce['affecte_par_nom'] && $puce['affecte_par_nom'] !== $puce['affecte_nom']) {
                                            $titre .= ' ('.__('par :nom', ['nom' => $puce['affecte_par_nom']]).')';
                                        }
                                    @endphp
                                    <a
                                        href="{{ route('courriers.show', $puce['id']) }}"
                                        wire:navigate
                                        title="{{ $titre }}"
                                        @class([
                                            'flex items-start gap-1.5 rounded px-2 py-1 text-xs',
                                            'bg-brand-success-light text-brand-success-dark' => $puce['statut_sla'] === \App\Services\SlaCalculatorService::A_TEMPS,
                                            'bg-brand-warning-light text-brand-warning-dark' => $puce['statut_sla'] === \App\Services\SlaCalculatorService::A_RISQUE,
                                            'bg-brand-danger-light text-brand-danger-dark' => $puce['statut_sla'] === \App\Services\SlaCalculatorService::EN_RETARD,
                                        ])
                                    >
                                        <div class="min-w-0 flex-1">
                                            <span class="line-clamp-2 font-semibold">{{ $puce['objet'] ?: $puce['numero_reference'] }}</span>
                                            @if ($puce['expediteur'])
                                                <span class="mt-0.5 block truncate opacity-80">{{ $puce['expediteur'] }}</span>
                                            @endif
                                        </div>
                                        {{-- Avatars empilés : "affecté par" derrière, "affecté à" devant
                                             (2026-10-05, "the persone who affected it to them should also
                                             appear on the collaborateur side too") — masqué si c'est la
                                             même personne (auto-affectation), pour ne pas doubler l'avatar. --}}
                                        @if ($puce['affecte_initiales'])
                                            <div class="flex shrink-0 -space-x-1.5">
                                                @if ($puce['affecte_par_initiales'] && $puce['affecte_par_nom'] !== $puce['affecte_nom'])
                                                    <flux:avatar size="sm" circle :initials="$puce['affecte_par_initiales']" class="size-4! border border-white text-[9px]! dark:border-zinc-900" />
                                                @endif
                                                <flux:avatar size="sm" circle :initials="$puce['affecte_initiales']" class="size-4! border border-white text-[9px]! dark:border-zinc-900" />
                                            </div>
                                        @endif
                                    </a>
                                @endforeach

                                @if ($donneesJour['surplus'] > 0)
                                    <a
                                        href="{{ route('courriers.rechercher', ['dateDebut' => $jour['date'], 'dateFin' => $jour['date']]) }}"
                                        wire:navigate
                                        class="block px-1.5 text-xs text-zinc-400 hover:text-brand-accent"
                                    >
                                        {{ __('+:n autres', ['n' => $donneesJour['surplus']]) }}
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Contexte de positionnement local, isolé du bandeau
                         sticky ci-dessus : son propre "top: 0" correspond au
                         début de 00:00, quelle que soit la hauteur (variable)
                         du bandeau. --}}
                    <div class="relative">
                        <div class="grid grid-cols-[3rem_repeat(5,1fr)] gap-px bg-zinc-200 dark:bg-zinc-700">
                            @foreach (range(0, 23) as $heure)
                                <div class="h-16 bg-white px-1 pt-0.5 text-right text-[10px] text-zinc-400 dark:bg-zinc-900">
                                    {{ sprintf('%02d:00', $heure) }}
                                </div>
                                @foreach ($this->joursSemaine() as $jour)
                                    <div class="h-16 border-t border-dashed border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900"></div>
                                @endforeach
                            @endforeach
                        </div>

                        {{-- Confinée à la colonne d'AUJOURD'HUI uniquement (2026-10-05,
                             "CHECK THE OVERLAP" — s'étendait sur toute la largeur de la
                             semaine et traversait des événements d'autres jours). --}}
                        @php $indexAujourdhui = collect($this->joursSemaine())->search(fn ($j) => $j['estAujourdhui']); @endphp
                        @if ($indexAujourdhui !== false)
                            <div
                                x-ref="ligneMaintenant"
                                class="pointer-events-none absolute z-10"
                                style="top: 0; left: calc(3rem + ({{ $indexAujourdhui }} / 5) * (100% - 3rem)); width: calc((100% - 3rem) / 5);"
                            >
                                <span class="absolute -top-1 -left-1.5 size-2.5 rounded-full bg-brand-accent"></span>
                                <div class="border-t-2 border-brand-accent"></div>
                            </div>
                        @endif

                        {{-- Courriers à heure fixe (chrono_fin_le) : placés dans la
                             bonne cellule heure/jour via grid-row + grid-column,
                             décalés verticalement dans l'heure par la minute. --}}
                        <div class="pointer-events-none absolute inset-x-0 top-0 grid grid-cols-[3rem_repeat(5,1fr)] grid-rows-[repeat(24,4rem)]">
                            @foreach ($this->joursSemaine() as $i => $jour)
                                @foreach ($this->courriersParJour()[$jour['date']]['minutees'] as $evenement)
                                    @php
                                        $titreEvenement = $evenement['heure_libelle'].' — '.$evenement['numero_reference'];
                                        if ($evenement['affecte_nom']) {
                                            $titreEvenement .= ' — '.__('Affecté à :nom', ['nom' => $evenement['affecte_nom']]);
                                        }
                                    @endphp
                                    <a
                                        href="{{ route('courriers.show', $evenement['id']) }}"
                                        wire:navigate
                                        title="{{ $titreEvenement }}"
                                        style="grid-column: {{ $i + 2 }}; grid-row: {{ $evenement['heure'] + 1 }}; margin-top: {{ $evenement['decalage_minute_px'] }}px;"
                                        @class([
                                            'pointer-events-auto z-20 mx-0.5 flex h-12 flex-col justify-center gap-0.5 overflow-hidden rounded-md border-l-4 px-2 py-1 text-[10px] leading-tight',
                                            'bg-brand-success-light border-brand-success-dark text-brand-success-dark' => $evenement['statut_sla'] === \App\Services\SlaCalculatorService::A_TEMPS,
                                            'bg-brand-warning-light border-brand-warning-dark text-brand-warning-dark' => $evenement['statut_sla'] === \App\Services\SlaCalculatorService::A_RISQUE,
                                            'bg-brand-danger-light border-brand-danger-dark text-brand-danger-dark' => $evenement['statut_sla'] === \App\Services\SlaCalculatorService::EN_RETARD,
                                        ])
                                    >
                                        {{-- Pas d'heure dans le titre (2026-10-05, "LOOK HOW THE
                                             TIME BOXE IS STRUCTURED") — Outlook ne répète jamais
                                             l'heure dans la carte, la position dans la grille la
                                             porte déjà ; l'heure reste disponible au survol (title
                                             de l'<a>, voir $titreEvenement ci-dessus). --}}
                                        <span class="truncate font-semibold">{{ $evenement['objet'] ?: $evenement['numero_reference'] }}</span>
                                        @if ($evenement['expediteur'] || $evenement['affecte_nom'])
                                            <span class="truncate opacity-80">{{ $evenement['expediteur'] ?: $evenement['affecte_nom'] }}</span>
                                        @endif
                                    </a>
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
