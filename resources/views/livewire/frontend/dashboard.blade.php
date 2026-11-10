{{-- Plus de plafond max-w-6xl (2026-09-18, demande explicite de
     l'utilisateur — "use the complete space on that dashboard main") : le
     tableau de bord doit occuper toute la largeur disponible de la zone
     de contenu principal, pas seulement une colonne centrale étroite. --}}
<section class="w-full">
    {{-- Bannière de bienvenue en Light Blue (2026-09-17, système de
         couleurs GEC, --color-brand-accent-pale = #EFF6FF). --}}
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-brand-accent-pale p-4">
        <div class="flex items-center gap-3">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
                <flux:icon.envelope class="size-5" />
            </div>
            <div>
                <flux:heading level="1">{{ __('Bonjour :nom', ['nom' => explode(' ', auth()->user()->name)[0]]) }}</flux:heading>
                <flux:subheading>
                    {{ auth()->user()->profil?->nom ?? __('—') }} — {{ __('Bienvenue sur votre espace GEC') }}
                </flux:subheading>
            </div>
        </div>
        <flux:text class="text-zinc-500">{{ now()->translatedFormat('l j F Y') }}</flux:text>
    </div>

    {{-- Convention de mise en page du panneau (voir notes internes,
         appliquée ici le 2026-09-18 sur demande explicite de
         l'utilisateur avec capture d'écran — "not the same thing the
         panel should be side by side with the kpi too but the welcome
         message should be full width") : SEULE la bannière de bienvenue
         ci-dessus reste hors grille — KPI, actions rapides ET "Derniers
         courriers enregistrés" partagent désormais la même colonne de
         gauche, avec le panneau latéral (Tâches du jour/Notifications/
         Calendrier) comme sibling sur toute cette hauteur. --}}
    {{-- Colonne de droite en largeur FIXE (320px), pas 1/3 de la page
         (2026-09-18, comparaison avec la maquette GPT du 2026-09-16 —
         "look at your design/style layout ... it should resemble exactly",
         scope confirmé par l'utilisateur : uniquement la largeur du
         panneau, pas les cartes KPI ni la sidebar) : même motif que
         editForm/workflowQueue/showCourrier (`lg:grid-cols-[minmax(0,1fr)_360px]`),
         un peu plus étroit ici (320px) pour rester proche de la maquette. --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="space-y-6">
    {{-- 4 cartes de statistiques — même périmètre de visibilité que
         CourrierList::resultats() pour chaque profil (voir
         Dashboard::courriersVisibles()) : ce ne sont pas des chiffres
         globaux à l'échelle de l'entreprise, seulement ce que CET
         utilisateur pourrait ouvrir individuellement. Couleurs = système
         de couleurs GEC (2026-09-17, voir DECISIONS.md "GEC Master Color
         System") : brand-accent (entrant), brand-success (sortant),
         brand-warning (en attente), brand-urgent — alias de brand-danger
         (rouge), clarifié avec l'utilisateur le 2026-09-17 car "Courriers
         urgents" n'a pas de couleur dédiée dans le système fourni. Cartes
         bg-white + shadow-sm sur fond de page --color-brand-surface
         (#F5F8FC, distinct du blanc des cartes). --}}
    {{-- Cartes KPI compactées (2026-09-18, demande explicite de
         l'utilisateur — "the kpis reduce it") : même traitement déjà
         appliqué aux cartes KPI de courrierList.blade.php — icône plus
         petite (size-9, rounded-lg au lieu de rounded-full), libellé +
         chiffre regroupés sur une même ligne à côté de l'icône plutôt que
         chiffre en dessous, carte moins haute (p-3, rounded-xl). --}}
    {{-- 2026-09-23 : 6 cartes (ajout "En retard" + "Délai moyen de
         traitement", PRD §3.10) — 2 rangées de 3 plutôt qu'une rangée de 6,
         trop étroite à côté du panneau latéral de 320px. --}}
    {{-- 2026-09-23, demande explicite de l'utilisateur ("on tableau de
         board all kpi and card there should be permission") : CHAQUE carte
         a désormais sa propre clé (dashboard.courrier_entrant, ..._sortant,
         .en_attente, .urgents, .en_retard, .delai_moyen — plus d'ancienne
         dashboard.statistiques partagée). Chaque computed retourne `null`
         sans son privilège ; la rangée entière ne s'affiche que si au moins
         une carte l'est. --}}
    @if ($this->courrierEntrantAujourdhui !== null || $this->courrierSortantAujourdhui !== null || $this->enAttenteDeTraitement !== null || $this->courriersUrgents !== null || $this->courriersEnRetard !== null || $this->courriersNonTransferes !== null || $this->delaiMoyen !== null)
    {{-- Squelette (2026-09-24, "loading animation on each table, cards,
         panels refreshing") — même nombre de cartes que ci-dessous. --}}
    @php
        $nombreCartesKpi = collect([
            $this->courrierEntrantAujourdhui, $this->courrierSortantAujourdhui, $this->enAttenteDeTraitement,
            $this->courriersUrgents, $this->courriersEnRetard, $this->courriersNonTransferes, $this->delaiMoyen,
        ])->whereNotNull()->count();
    @endphp
    <div x-data="squeletteMinimum()">
        <div wire:loading.class.remove="hidden" x-ref="sentinelle" class="hidden" aria-hidden="true"></div>
        <div x-show="visible" x-cloak class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @for ($i = 0; $i < $nombreCartesKpi; $i++)
                <x-skeleton.card />
            @endfor
        </div>
        <div x-show="!visible" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @if ($this->courrierEntrantAujourdhui !== null)
        <div class="rounded-xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2.5">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-accent/10 text-brand-accent">
                    <flux:icon.inbox-arrow-down class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:text class="truncate text-xs text-zinc-500">{{ __('Courrier entrant') }}</flux:text>
                    <div class="text-xl font-semibold leading-tight">{{ $this->courrierEntrantAujourdhui['total'] }}</div>
                </div>
                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300" />
            </div>
            <flux:text class="mt-2 flex items-center gap-1 text-xs">
                @if ($this->courrierEntrantAujourdhui['delta'] > 0)
                    <flux:icon.arrow-trending-up class="size-3.5 text-brand-success" />
                    <span class="truncate text-brand-success">{{ __(':n de plus qu\'hier', ['n' => $this->courrierEntrantAujourdhui['delta']]) }}</span>
                @elseif ($this->courrierEntrantAujourdhui['delta'] < 0)
                    <flux:icon.arrow-trending-down class="size-3.5 text-zinc-400" />
                    <span class="truncate text-zinc-500">{{ __(':n de moins qu\'hier', ['n' => abs($this->courrierEntrantAujourdhui['delta'])]) }}</span>
                @else
                    <span class="truncate text-zinc-500">{{ __('Comme hier') }}</span>
                @endif
            </flux:text>
        </div>
        @endif

        @if ($this->courrierSortantAujourdhui !== null)
        <div class="rounded-xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2.5">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-success/10 text-brand-success">
                    <flux:icon.paper-airplane class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:text class="truncate text-xs text-zinc-500">{{ __('Courrier sortant') }}</flux:text>
                    <div class="text-xl font-semibold leading-tight">{{ $this->courrierSortantAujourdhui['total'] }}</div>
                </div>
                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300" />
            </div>
            <flux:text class="mt-2 flex items-center gap-1 text-xs">
                @if ($this->courrierSortantAujourdhui['delta'] > 0)
                    <flux:icon.arrow-trending-up class="size-3.5 text-brand-success" />
                    <span class="truncate text-brand-success">{{ __(':n de plus qu\'hier', ['n' => $this->courrierSortantAujourdhui['delta']]) }}</span>
                @elseif ($this->courrierSortantAujourdhui['delta'] < 0)
                    <flux:icon.arrow-trending-down class="size-3.5 text-zinc-400" />
                    <span class="truncate text-zinc-500">{{ __(':n de moins qu\'hier', ['n' => abs($this->courrierSortantAujourdhui['delta'])]) }}</span>
                @else
                    <span class="truncate text-zinc-500">{{ __('Comme hier') }}</span>
                @endif
            </flux:text>
        </div>
        @endif

        @if ($this->enAttenteDeTraitement !== null)
        <div class="rounded-xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2.5">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-warning/10 text-brand-warning">
                    <flux:icon.clock class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:text class="truncate text-xs text-zinc-500">{{ __('En attente de traitement') }}</flux:text>
                    <div class="text-xl font-semibold leading-tight">{{ $this->enAttenteDeTraitement }}</div>
                </div>
                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300" />
            </div>
            <flux:text class="mt-2 truncate text-xs text-zinc-500">{{ __('courrier(s)') }}</flux:text>
        </div>
        @endif

        @if ($this->courriersUrgents !== null)
        <div class="rounded-xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2.5">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-urgent/10 text-brand-urgent">
                    <flux:icon.exclamation-triangle class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:text class="truncate text-xs text-zinc-500">{{ __('Courriers urgents') }}</flux:text>
                    <div class="text-xl font-semibold leading-tight">{{ $this->courriersUrgents }}</div>
                </div>
                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300" />
            </div>
            <flux:text class="mt-2 truncate text-xs text-zinc-500">{{ __('courrier(s)') }}</flux:text>
        </div>
        @endif

        {{-- Module 5/10 — date limite SLA dépassée (Dashboard::courriersEnRetard()). --}}
        @if ($this->courriersEnRetard !== null)
        <div class="rounded-xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2.5">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-urgent/10 text-brand-urgent">
                    <flux:icon.bell-alert class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:text class="truncate text-xs text-zinc-500">{{ __('En retard') }}</flux:text>
                    <div class="text-xl font-semibold leading-tight">{{ $this->courriersEnRetard }}</div>
                </div>
                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300" />
            </div>
            <flux:text class="mt-2 truncate text-xs text-zinc-500">{{ __('date limite dépassée') }}</flux:text>
        </div>
        @endif

        {{-- Module 10 (2026-10-09) — jamais affecté/transféré depuis
             l'enregistrement (Dashboard::courriersNonTransferes()). --}}
        @if ($this->courriersNonTransferes !== null)
        <div class="rounded-xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center gap-2.5">
                {{-- indigo-100/indigo-600 (2026-10-12, calage sur la maquette
                     de référence fournie par l'utilisateur) : seule carte KPI
                     à utiliser une teinte Tailwind par défaut plutôt qu'un
                     token --color-brand-* — bg-brand-navy/10 rendait un gris
                     délavé peu lisible sur fond blanc (contrairement aux
                     autres cartes, --color-brand-navy est pensé pour un fond
                     SOMBRE, voir sidebar) ; aucune couleur de statut existante
                     (succès/attention/danger/info) n'a de sens ici, donc pas
                     d'alias possible dans le système actuel (voir DECISIONS.md
                     "GEC Master Color System" pour la règle habituelle
                     "alias plutôt que nouvelle teinte" — exception assumée). --}}
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-indigo-600">
                    <flux:icon.inbox-stack class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:text class="truncate text-xs text-zinc-500">{{ __('Non transférés') }}</flux:text>
                    <div class="text-xl font-semibold leading-tight">{{ $this->courriersNonTransferes }}</div>
                </div>
                <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300" />
            </div>
            <flux:text class="mt-2 truncate text-xs text-zinc-500">{{ __('jamais affectés/transférés') }}</flux:text>
        </div>
        @endif

        {{-- Module 10 — pré-calculé par RefreshDashboardStatsJob ; masqué
             pour un profil sans périmètre de service (Dashboard::delaiMoyen()). --}}
        @if ($this->delaiMoyen !== null)
            {{-- col-span-full (2026-10-12, calage sur la maquette de
                 référence) : rangée récapitulative pleine largeur sous la
                 grille 2x3 des 6 cartes KPI, plutôt qu'une 7ème cellule
                 étroite — mise en page distincte volontaire ("the delai
                 moyen card is the summary row"). --}}
            <div class="col-span-full flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-2.5">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-accent-medium/10 text-brand-accent-medium">
                        <flux:icon.chart-bar class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <flux:text class="truncate text-xs text-zinc-500">{{ __('Délai moyen de traitement') }}</flux:text>
                        <div class="text-xl font-semibold leading-tight">
                            {{ $this->delaiMoyen['jours'] !== null ? trans_choice(':n jour|:n jours', $this->delaiMoyen['jours'], ['n' => $this->delaiMoyen['jours']]) : '—' }}
                        </div>
                    </div>
                </div>
                <flux:text class="shrink-0 text-xs text-zinc-500">
                    {{ $this->delaiMoyen['en_calcul'] ? __('Calcul en cours…') : __('courriers clôturés, 90 derniers jours') }}
                </flux:text>
            </div>
        @endif
        </div>
    </div>
    @endif

        {{-- dashboard.derniers_courriers (2026-09-23). --}}
        @if ($this->derniersCourriers !== null)
        <div>
            <div class="flex items-center justify-between">
                <flux:heading level="2">{{ __('Derniers courriers enregistrés') }}</flux:heading>
                @can('rechercher', App\Models\Courrier::class)
                    <flux:link :href="route('courriers.rechercher')" wire:navigate>{{ __('Voir tous') }} →</flux:link>
                @endcan
            </div>

            @if ($this->derniersCourriers->isEmpty())
                <flux:text class="mt-4 text-zinc-500">{{ __('Aucun courrier pour l\'instant.') }}</flux:text>
            @else
                <div x-data="squeletteMinimum()" class="@container mt-4 overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div wire:loading.class.remove="hidden" x-ref="sentinelle" class="hidden" aria-hidden="true"></div>
                    <table class="w-full text-sm">
                        {{-- Format de tableau commun (2026-10-29, « same format on all
                             the tables of the project ») — celui de « Tous les
                             courriers » (voir le commentaire de courrierList.blade.php) :
                             l'en-tête n'a plus de fond (le fond « soft » demandé le
                             2026-10-12 pour cette seule table est remplacé par le trait
                             navy commun), lignes sur UNE seule ligne
                             (« Sens » à côté du type, texte trop long coupé, lisible
                             en entier au survol), 16 px en haut et en bas de chaque
                             cellule. Carte étroite (< 44rem, ex. écran de 1366 px) :
                             la colonne « Type » est masquée, « Date de réception »
                             reste visible. --}}
                        <thead class="border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400">
                            <tr>
                                <th class="w-px whitespace-nowrap py-3 pr-3 @min-[60rem]:pr-5 pl-4">{{ __('N° Référence') }}</th>
                                <th class="py-3 pr-3 @min-[60rem]:pr-5 @max-[44rem]:hidden">{{ __('Type') }}</th>
                                <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Expéditeur') }}</th>
                                <th class="py-3 pr-3 @min-[60rem]:pr-5 @max-[60rem]:hidden">{{ __('Destinataire') }}</th>
                                <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Objet') }}</th>
                                <th class="w-px py-3 pr-3 @min-[60rem]:pr-5">{{ __('Date de réception') }}</th>
                                <th class="w-px py-3 pr-4">{{ __('Statut') }}</th>
                            </tr>
                        </thead>
                        {{-- Visibilité pilotée par l'état `visible` de
                             squeletteMinimum() (x-data ci-dessus), durée
                             minimale garantie — voir le commentaire
                             équivalent dans courrierList.blade.php
                             (2026-09-24). --}}
                        <tbody x-show="visible" x-cloak class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            <x-skeleton.table-rows :cols="6" :rows="5" />
                        </tbody>
                        <tbody x-show="!visible" class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->derniersCourriers as $courrier)
                                @php
                                    $typeLigne = $courrier->type_document.' · '.($courrier->sens === 'entrant' ? __('Entrant') : __('Sortant'));
                                    $correspondantLigne = $courrier->sens === 'entrant'
                                        ? ($courrier->expediteur_organisation ?: $courrier->expediteur_nom ?: __('—'))
                                        : ($courrier->destinataire ?: __('—'));
                                    $destinataireLigne = $courrier->destinataire ?: ($courrier->service?->nom ?? __('—'));
                                @endphp
                                <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800" onclick="window.location='{{ route('courriers.show', $courrier->id) }}'">
                                    <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5 pl-4 font-medium">
                                        <span class="block max-w-36 truncate" title="{{ $courrier->numero_reference }}"><flux:link :href="route('courriers.show', $courrier->id)" wire:navigate class="text-brand-accent">{{ $courrier->numero_reference }}</flux:link></span>
                                    </td>
                                    <td class="w-[20%] min-w-20 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500 @max-[44rem]:hidden">
                                        <div class="w-0 min-w-full truncate" title="{{ $typeLigne }}">{{ $typeLigne }}</div>
                                    </td>
                                    <td class="w-[30%] min-w-20 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500 @max-[44rem]:w-[38%]">
                                        <div class="w-0 min-w-full truncate" title="{{ $correspondantLigne }}">{{ $correspondantLigne }}</div>
                                    </td>
                                    <td class="w-[30%] min-w-20 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500 @max-[44rem]:w-[38%]">
                                        <div class="w-0 min-w-full truncate" title="{{ $destinataireLigne }}">{{ $destinataireLigne }}</div>
                                    </td>
                                    <td class="w-[50%] min-w-24 py-4 pr-3 @min-[60rem]:pr-5 @max-[44rem]:w-[62%]">
                                        <div class="w-0 min-w-full truncate" title="{{ $courrier->objet }}">{{ $courrier->objet }}</div>
                                    </td>
                                    <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">{{ $courrier->date_mouvement->format('d/m/Y') }}</td>
                                    <td class="py-4 pr-4">
                                        {{-- Chronomètre à côté du statut (2026-09-28, "when the
                                             date is comming soon the colours should change") ;
                                             compte à rebours masqué ici aussi (2026-10-02, "remove on
                                             the dashboard too"), ne garde que les badges d'état.
                                             2026-10-29 — sur la même ligne que le statut, comme
                                             « Tous les courriers ». --}}
                                        <div class="flex items-center gap-1 whitespace-nowrap @max-[44rem]:flex-col @max-[44rem]:items-start">
                                            <x-statut-badge :statut="$courrier->statut" class="rounded-md! px-2! font-semibold!" />
                                            <x-chronometre :courrier="$courrier" :masquer-temps="true" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @endif
        </div>

        <div class="sticky top-20 self-start space-y-6">
            {{-- Module 10 — "Tâches du jour" : réel (pas "bientôt
                 disponible"), voir Dashboard::tachesDuJour() — aperçu de la
                 file d'attente du jour, gardé derrière le privilège
                 dashboard.taches_du_jour (Collaborateur par défaut,
                 Responsable de service au cas par cas). --}}
            @if ($this->tachesDuJour !== null)
                <div x-data="squeletteMinimum()" class="overflow-hidden rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div wire:loading.class.remove="hidden" x-ref="sentinelle" class="hidden" aria-hidden="true"></div>
                    <div class="flex items-center gap-2 border-b border-brand-border px-4 py-3 dark:border-zinc-700">
                        <flux:icon.check-circle class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Tâches du jour') }}</flux:heading>
                        @if ($this->totalTachesDuJour > 0)
                            <span class="ml-auto inline-flex min-w-6 items-center justify-center rounded-full bg-brand-accent px-2 py-0.5 text-xs font-semibold text-white">{{ $this->totalTachesDuJour }}</span>
                        @endif
                    </div>
                    {{-- Squelette (2026-09-24) — ce panneau n'a aujourd'hui
                         aucun déclencheur de rafraîchissement en direct
                         (Dashboard n'a ni #[On(...)] ni action publique),
                         mais reste cohérent avec le reste de la page et
                         couvre un futur ajout de rafraîchissement live. --}}
                    <ul x-show="visible" x-cloak class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <x-skeleton.list-items :items="3" />
                    </ul>
                    <div x-show="!visible">
                    @if ($this->tachesDuJour->isEmpty())
                        <div class="flex items-center gap-2 p-4 text-sm text-zinc-500">
                            <flux:icon.face-smile class="size-4" />
                            {{ __('Rien à traiter pour l\'instant.') }}
                        </div>
                    @else
                        {{-- 2026-09-24 ("customize the tâche du jour") : chaque
                             tâche dit QUOI faire (action attendue selon le
                             statut) et POUR QUAND (échéance SLA réelle —
                             date_limite), triée par urgence (voir
                             Dashboard::tachesDuJour()). Point de couleur =
                             priorité. Lien <a> simple : flux:link imposait son
                             propre affichage en ligne et cassait la mise en
                             page de la ligne (case réduite à une barre). --}}
                        <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($this->tachesDuJour as $courrier)
                                @php
                                    $action = match ($courrier->statut) {
                                        'en_attente_de_transfert' => __('À transférer'),
                                        'en_cours_de_transfert' => __('À accepter'),
                                        'enregistre' => __('À affecter'),
                                        'affecte' => __('À démarrer'),
                                        'en_traitement' => __('À traiter'),
                                        'en_validation' => __('À valider'),
                                        'en_attente_information' => __('En attente d\'information'),
                                        default => \App\Models\Courrier::libelleStatut($courrier->statut),
                                    };
                                    $classePriorite = match ($courrier->priorite) {
                                        'urgente' => 'bg-brand-danger',
                                        'haute' => 'bg-brand-warning',
                                        default => 'bg-zinc-300 dark:bg-zinc-600',
                                    };
                                @endphp
                                <li wire:key="tache-{{ $courrier->id }}">
                                    <a href="{{ route('courriers.show', ['courrierId' => $courrier->id, 'onglet' => 'circuit']) }}" wire:navigate class="flex items-start gap-3 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                        <span class="mt-1.5 size-2 shrink-0 rounded-full {{ $classePriorite }}" title="{{ __('Priorité') }} : {{ $courrier->priorite }}"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-center justify-between gap-2">
                                                <span class="truncate text-sm font-semibold text-brand-accent">{{ $courrier->numero_reference }}</span>
                                                {{-- Chronomètre en direct (2026-09-24) à la place du badge
                                                     J-N. Compte à rebours masqué (2026-10-02, "remove on
                                                     the dashboard too") — ne garde que les badges d'état. --}}
                                                <x-chronometre :courrier="$courrier" :masquer-temps="true" />

                                            </span>
                                            <span class="mt-0.5 block truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $courrier->objet }}</span>
                                            <span class="mt-1.5 flex items-center gap-1 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                                <flux:icon.arrow-right-circle class="size-3.5 text-brand-accent" />
                                                {{ $action }}
                                                @if ($courrier->service)
                                                    <span class="font-normal text-zinc-500">· {{ $courrier->service->code }}</span>
                                                @endif
                                            </span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="border-t border-brand-border p-2 text-center dark:border-zinc-700">
                            <flux:link :href="route('courriers.rechercher', ['statut' => \App\Livewire\Backend\CourrierList::STATUT_ACTIFS])" wire:navigate>
                                @if ($this->totalTachesDuJour > $this->tachesDuJour->count())
                                    {{ __('Voir les :n tâches', ['n' => $this->totalTachesDuJour]) }} →
                                @else
                                    {{ __('Voir tout') }} →
                                @endif
                            </flux:link>
                        </div>
                    @endif
                    </div>
                </div>
            @endif

            {{-- Style de carte aligné sur "Tâches du jour"/"Calendrier"
                 ci-dessous (2026-09-18, "the notification panel design
                 doesn't match the image" — la maquette montre une carte
                 blanche avec bandeau d'en-tête, pas une zone en pointillés).
                 dashboard.notifications (2026-09-23) gouverne la carte ;
                 depuis le 2026-10-05 (Module 7) elle affiche un vrai aperçu
                 des notifications de l'utilisateur plutôt que le texte figé
                 "Bientôt disponible." — même format compact que la cloche
                 de l'en-tête (notification-bell.blade.php). --}}
            @if ($this->peutVoirNotifications)
                {{-- 2026-10-09 — enveloppe générique (libelle/lien/icone/couleur)
                     partagée par TOUTES les classes de notification, même
                     constat que notification-bell.blade.php/notifications-index.blade.php
                     (audit "list me all"). --}}
                @php
                    $classesParCouleurCarte = [
                        'danger' => 'bg-brand-danger',
                        'warning' => 'bg-brand-warning',
                        'success' => 'bg-brand-success',
                        'navy' => 'bg-brand-navy',
                        'blue' => 'bg-brand-accent',
                        'neutre' => 'bg-brand-disabled-text',
                    ];
                @endphp
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center gap-2 border-b border-brand-border px-4 py-3 dark:border-zinc-700">
                        <flux:icon.bell class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Notifications') }}</flux:heading>
                    </div>
                    @forelse ($this->notificationsRecentes as $notification)
                        {{-- 2026-10-12, retour utilisateur direct avec capture
                             d'écran ("change the design", carte "Notifications")
                             — deux correctifs :
                             1. VRAI bug : le libellé n'avait AUCUNE couleur de
                                texte explicite (juste "truncate text-sm") — un
                                <a href> sans couleur posée dessus hérite du
                                bleu/violet de lien PAR DÉFAUT du navigateur
                                (visité = teinte brun-orangé, exactement ce que
                                montrait la capture), puisque ni Tailwind ni
                                <flux:text> ne la neutralisent automatiquement
                                ici. notification-bell.blade.php/notifications-index.blade.php
                                n'ont jamais ce problème : Flux (menu.item) ou
                                un @class explicite y posent déjà une couleur.
                             2. Repère lu/non lu renforcé (border-s-4), même
                                patron que notification-bell.blade.php (plus tôt
                                le même jour) — pour que les 3 surfaces de
                                notification (cloche, page complète, cette
                                carte) restent cohérentes. --}}
                        <a
                            href="{{ $notification->data['lien'] }}"
                            wire:navigate
                            @class([
                                'flex items-center gap-2 border-b border-s-4 border-brand-border px-4 py-2.5 last:border-b-0 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800',
                                'border-s-brand-accent bg-brand-accent-pale/40 dark:bg-brand-accent/10' => ! $notification->read_at,
                                'border-s-transparent' => $notification->read_at,
                            ])
                        >
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-full text-white {{ $classesParCouleurCarte[$notification->data['couleur'] ?? 'neutre'] ?? $classesParCouleurCarte['neutre'] }}">
                                <flux:icon :icon="$notification->data['icone'] ?? 'bell'" class="size-3.5" />
                            </div>
                            <flux:text @class(['truncate text-sm text-brand-text-primary', 'font-semibold' => ! $notification->read_at])>
                                {{ $notification->data['libelle'] }}
                            </flux:text>
                            @if (! $notification->read_at)
                                <span class="ms-auto size-2 shrink-0 rounded-full bg-brand-accent"></span>
                            @endif
                        </a>
                    @empty
                        <flux:text class="p-4 text-sm text-zinc-500">{{ __('Aucune notification pour l\'instant.') }}</flux:text>
                    @endforelse
                    @if ($this->notificationsRecentes->isNotEmpty())
                        <a href="{{ route('notifications.index') }}" wire:navigate class="block border-t border-brand-border px-4 py-2 text-center text-xs text-brand-accent hover:underline dark:border-zinc-700">
                            {{ __('Voir toutes les notifications') }}
                        </a>
                    @endif
                </div>
            @endif

        </div>
    </div>
</section>
