{{-- Nouvelle maquette "Tous les courriers" fournie par l'utilisateur
     (2026-09-18) — remplace ENTIÈREMENT l'ancienne mise en page ("replace
     ALL with it"). Clarifié avec l'utilisateur (AskUserQuestion) sur deux
     points où la maquette ne correspondait à aucune donnée réelle :
     (1) les 4 cartes ("Total/En traitement/Terminés/En erreur") sont
     adaptées aux statuts RÉELS du schéma (voir CourrierList::statistiques()) ;
     (2) le filtre "Fonctionnalité" de la maquette ne correspond à aucun
     champ existant — remplacé par "Type de document", un filtre réel déjà
     construit. Boutons "Importer"/"Exporter" : aucune fonctionnalité
     réelle derrière à ce jour — désactivés plutôt qu'omis ou fabriqués
     (même principe que les entrées "Bientôt" de la sidebar). --}}
{{-- x-data partagé pour TOUTE la section filtres (bouton "Filtres" du
     bandeau supérieur ET lien "Filtres avancés" à côté de la recherche
     doivent piloter le MÊME panneau replié/déplié en dessous — deux scopes
     Alpine séparés, essayé d'abord, ne communiquaient jamais entre eux :
     cliquer sur "Filtres" en haut ne dépliait rien).
     apercuMobile (2026-10-27) : sous 1024 px, le panneau « Aperçu » est
     caché et s'ouvre en plein écran au toucher d'une ligne. --}}
<section
    class="w-full"
    x-data="{ apercuMobile: false, avance: @js($typeDocument !== '' || $statut !== '' || $priorite !== '' || $periode !== '' || $objet !== '' || $expediteur !== '' || $contenu !== '' || $confidentialite !== '' || $serviceId !== null || $dateDebut !== '' || $dateFin !== '' || $sens !== '') }"
>
    {{-- document-preview.js définit window.DocumentPreview, utilisé par le
         panneau "Aperçu du courrier" plus bas — jamais chargé sur cette
         page jusqu'ici (copié depuis registrationForm.blade.php sans son
         propre @vite), d'où "Cannot read properties of undefined (reading
         'obtenir')" constaté par l'utilisateur en conditions réelles. --}}
    @assets
        @vite('resources/js/document-preview.js')
    @endassets

    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Courriers') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Tous les courriers') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
                <flux:icon.envelope class="size-5" />
            </div>
            <div>
                <flux:heading level="1">{{ __('Tous les courriers') }}</flux:heading>
                <flux:subheading>{{ __('Consultez, gérez et suivez tous les courriers de votre organisation.') }}</flux:subheading>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('create', App\Models\Courrier::class)
                <flux:button variant="primary" icon="plus" :href="route('courriers.nouveau')" wire:navigate>
                    {{ __('Nouveau courrier') }}
                </flux:button>
            @endcan
            {{-- "Importer"/"Exporter" (maquette) : aucune fonctionnalité
                 réelle derrière ces boutons à ce jour — désactivés avec une
                 infobulle plutôt qu'omis (même principe que la sidebar).
                 2026-10-08 — retour utilisateur direct : désormais masqués
                 sans leur privilège (courriers.importer/courriers.exporter),
                 même convention que "Répondre"/"Créer une note" sur
                 ShowCourrier — un privilège contrôle la visibilité, pas
                 seulement l'état activé. --}}
            @can('importer', App\Models\Courrier::class)
                <flux:tooltip content="{{ __('Bientôt disponible') }}">
                    <flux:button variant="outline" icon="arrow-down-tray" disabled>{{ __('Importer') }}</flux:button>
                </flux:tooltip>
            @endcan
            @can('exporter', App\Models\Courrier::class)
                <flux:tooltip content="{{ __('Bientôt disponible') }}">
                    <flux:button variant="outline" icon="arrow-up-tray" disabled>{{ __('Exporter') }}</flux:button>
                </flux:tooltip>
            @endcan
            <flux:button variant="outline" icon="adjustments-horizontal" x-on:click="avance = !avance">
                {{ __('Filtres') }}
            </flux:button>
        </div>
    </div>

    {{-- Panneau "Aperçu du courrier" ouvert : TOUT le contenu de gauche
         (cartes statistiques, recherche/filtres, tableau) passe en 2
         colonnes face au panneau, qui démarre donc juste sous la ligne de
         boutons et court sur toute la hauteur — demande explicite de
         l'utilisateur ("i want the panel to start under the buttons and it
         should be side by side with the research form and the table and
         the kpi"), pas seulement à côté du tableau comme avant. --}}
    <div class="mt-6 grid gap-6 {{ $this->courrierApercu ? 'lg:grid-cols-[minmax(0,1fr)_380px]' : '' }}">
        <div class="space-y-6">
    {{-- 4 cartes — voir CourrierList::statistiques() pour le détail des
         statuts regroupés sous chaque libellé. Format compact (icône +
         libellé/chiffre groupés sur une seule ligne, tendance en dessous)
         — demande explicite de l'utilisateur ("reduce the kpi and arange
         them to well present those data inside it") : depuis que ces
         cartes partagent la colonne de gauche avec le panneau "Aperçu"
         (voir le journal de développement, entrée précédente), l'ancien format
         (icône+libellé sur une ligne, gros chiffre en dessous, 2 lignes de
         hauteur) tenait moins bien dans cette largeur réduite. --}}
    {{-- 2026-09-23, demande explicite de l'utilisateur ("same thing for all
         the pages, each card should be a permission") : CHAQUE carte a
         désormais sa propre clé (courriers.voir_carte_total/en_traitement/
         termines/en_erreur — plus d'ancienne courriers.voir_statistiques
         partagée). $this->statistiques ne contient que les clés autorisées
         (voir CourrierList::statistiques()) — la rangée entière ne
         s'affiche que si au moins une carte l'est. --}}
    @if ($this->statistiques !== [])
    {{-- Squelette (2026-09-24, "loading animation on each table, cards,
         panels refreshing not the loading sp[inner]") — même nombre de
         cartes que $this->statistiques (déjà connu du rendu précédent
         pendant une requête en cours, wire:loading ne détruit rien), pour
         ne jamais afficher un nombre de squelettes différent du nombre de
         cartes réelles qui les remplaceront. Durée minimale d'affichage
         (2026-09-24, "am not seeing animation... in page") via
         squeletteMinimum() (resources/js/app.js) — une requête locale peut
         se terminer trop vite pour que le balayage du shimmer soit
         perceptible ; la sentinelle (jamais affichée, voir son
         aria-hidden) sert uniquement de signal fiable à Alpine, indépendant
         de tout modificateur de display. --}}
    <div x-data="squeletteMinimum()">
        <div wire:loading.class.remove="hidden" x-ref="sentinelle" class="hidden" aria-hidden="true"></div>
        <div x-show="visible" x-cloak class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @for ($i = 0; $i < count($this->statistiques); $i++)
                <x-skeleton.card />
            @endfor
        </div>
        <div x-show="!visible" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['cle' => 'total', 'label' => __('Total courriers'), 'icon' => 'inbox', 'couleur' => 'blue'],
            ['cle' => 'enTraitement', 'label' => __('En traitement'), 'icon' => 'arrow-path', 'couleur' => 'warning'],
            ['cle' => 'termines', 'label' => __('Terminés'), 'icon' => 'check-circle', 'couleur' => 'success'],
            ['cle' => 'enErreur', 'label' => __('En erreur'), 'icon' => 'exclamation-triangle', 'couleur' => 'danger'],
        ] as $carte)
            @if (isset($this->statistiques[$carte['cle']]))
                @php $stat = $this->statistiques[$carte['cle']]; @endphp
                <div class="rounded-xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center gap-2.5">
                        {{-- rounded-full (2026-10-12, calage sur la même
                             maquette de référence que le tableau de bord —
                             "do the same thing on each page not the
                             dashboard alone") : même traitement que les
                             cartes KPI de Dashboard (icône en cercle,
                             chevron à droite). --}}
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-{{ $carte['couleur'] }} text-white">
                            <flux:icon :icon="$carte['icon']" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <flux:text class="truncate text-xs text-zinc-500">{{ $carte['label'] }}</flux:text>
                            <div class="text-xl font-semibold leading-tight">{{ $stat['total'] }}</div>
                        </div>
                        <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-300" />
                    </div>
                    <flux:text class="mt-2 flex items-center gap-1 text-xs">
                        @if ($stat['variation'] === null)
                            <span class="truncate text-zinc-400">{{ __('Pas de donnée le mois précédent') }}</span>
                        @elseif ($stat['variation'] > 0)
                            <flux:icon.arrow-trending-up class="size-3.5 shrink-0 text-brand-success" />
                            <span class="truncate text-brand-success">+{{ $stat['variation'] }}% {{ __('vs mois précédent') }}</span>
                        @elseif ($stat['variation'] < 0)
                            <flux:icon.arrow-trending-down class="size-3.5 shrink-0 text-brand-danger" />
                            <span class="truncate text-brand-danger">{{ $stat['variation'] }}% {{ __('vs mois précédent') }}</span>
                        @else
                            <span class="truncate text-zinc-400">{{ __('Stable vs mois précédent') }}</span>
                        @endif
                    </flux:text>
                </div>
            @endif
        @endforeach
        </div>
    </div>
    @endif

    {{-- Résultat d'une recherche lancée depuis la barre de navigation
         (?q=..., voir DECISIONS.md "Navigation (navbar + sidebar)") —
         distincte des filtres avancés ci-dessous. --}}
    @if ($q !== '')
        <flux:callout icon="magnifying-glass">
            <flux:callout.text>{{ __('Résultats pour « :q »', ['q' => $q]) }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" variant="ghost" wire:click="$set('q', '')">{{ __('Effacer') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center gap-3">
            <div class="flex-1">
                <flux:input wire:model.live.debounce.400ms="numero" icon="magnifying-glass" :placeholder="__('Rechercher un courrier, un expéditeur, une référence…')" />
            </div>
            <flux:button variant="ghost" size="sm" icon="adjustments-horizontal" x-on:click="avance = !avance">
                {{ __('Filtres avancés') }}
            </flux:button>
        </div>

        {{-- 2026-09-23 (demande explicite de l'utilisateur : "mask the
             research inputs only leave the long one and bring the tables
             up") : SEULE la barre de recherche reste visible ; TOUS les
             filtres (y compris ces 4 listes, auparavant toujours affichées)
             sont repliés derrière "Filtres" / "Filtres avancés" — le
             tableau remonte d'autant. Déplié automatiquement si un filtre
             est déjà actif (x-data de la section). --}}
        <div x-show="avance" x-cloak>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:select wire:model.live="typeDocument" :label="__('Fonctionnalité')">
                <flux:select.option value="">{{ __('Toutes') }}</flux:select.option>
                @foreach (\App\Livewire\Backend\Forms\CourrierForm::typesDocument() as $type)
                    <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statut" :label="__('Statut')">
                <flux:select.option value="">{{ __('Tous les statuts') }}</flux:select.option>
                <flux:select.option value="{{ \App\Livewire\Backend\CourrierList::STATUT_ACTIFS }}">{{ __('En cours (tous)') }}</flux:select.option>
                @foreach (array_keys(\App\Models\Courrier::LIBELLES_STATUT) as $valeurStatut)
                    <flux:select.option value="{{ $valeurStatut }}">{{ \App\Models\Courrier::libelleStatut($valeurStatut) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="priorite" :label="__('Priorité')">
                <flux:select.option value="">{{ __('Toutes priorités') }}</flux:select.option>
                <flux:select.option value="basse">{{ __('Basse') }}</flux:select.option>
                <flux:select.option value="normale">{{ __('Normale') }}</flux:select.option>
                <flux:select.option value="haute">{{ __('Haute') }}</flux:select.option>
                <flux:select.option value="urgente">{{ __('Urgente') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="periode" :label="__('Période')">
                <flux:select.option value="">{{ __('Toute période') }}</flux:select.option>
                <flux:select.option value="aujourd_hui">{{ __('Aujourd\'hui') }}</flux:select.option>
                <flux:select.option value="cette_semaine">{{ __('Cette semaine') }}</flux:select.option>
                <flux:select.option value="ce_mois">{{ __('Ce mois') }}</flux:select.option>
                <flux:select.option value="cette_annee">{{ __('Cette année') }}</flux:select.option>
            </flux:select>
        </div>

        {{-- Recherche avancée repliée par défaut (demande explicite de
             l'utilisateur, 2026-09-14) — dépliée automatiquement si un filtre
             avancé est déjà actif (ex. lien partagé/mis en favori). --}}
        <div>
            <flux:separator class="my-4" />
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:input wire:model.live.debounce.400ms="objet" :label="__('Objet')" />
                <flux:input wire:model.live.debounce.400ms="expediteur" :label="__('Expéditeur (nom ou organisation)')" />
                {{-- courriers.voir_texte_ocr (2026-09-23), aussi ignoré côté serveur. --}}
                @if ($this->peutRechercherContenu)
                    <flux:input
                        wire:model.live.debounce.400ms="contenu"
                        :label="__('Contenu du document (texte scanné)')"
                        :description="__('Un mot ou un montant vu sur le document, même si vous ne vous souvenez plus de l\'expéditeur.')"
                        placeholder="{{ __('ex. capital, un montant, un nom cité...') }}"
                    />
                @endif

                <flux:select wire:model.live="confidentialite" :label="__('Confidentialité')" placeholder="{{ __('Tous les niveaux') }}">
                    @foreach (range(1, \App\Models\User::niveauConfidentialiteMax()) as $niveau)
                        <flux:select.option value="{{ $niveau }}">{{ __('Niveau :n', ['n' => $niveau]) }}</flux:select.option>
                    @endforeach
                </flux:select>
               @can('service', App\Models\Service::class)
                <flux:select wire:model.live="serviceId" :label="__('Service')" placeholder="{{ __('Tous les services') }}">
                    @foreach ($this->services as $service)
                        <flux:select.option value="{{ $service->id }}">{{ $service->nom }}</flux:select.option>
                    @endforeach
                </flux:select>
                @endcan


                <flux:input type="date" wire:model.live="dateDebut" :label="__('Date de réception — du')" />
                <flux:input type="date" wire:model.live="dateFin" :label="__('Date de réception — au')" />

                <flux:select wire:model.live="sens" :label="__('Sens')" placeholder="{{ __('Entrant et sortant') }}">
                    <flux:select.option value="entrant">{{ __('Entrant') }}</flux:select.option>
                    <flux:select.option value="sortant">{{ __('Sortant') }}</flux:select.option>
                </flux:select>
            </div>
        </div>

        <div class="mt-4">
            <flux:button variant="ghost" size="sm" wire:click="reinitialiser">{{ __('Réinitialiser les filtres') }}</flux:button>
        </div>
        </div>
    </div>

    {{-- Transfert en masse (2026-09-22, demande explicite de l'utilisateur :
         "no button to transfer courier in bulk why") — bouton visible pour
         n'importe quel profil ayant au moins un privilège de transfert
         (jamais un rôle en dur, voir CourrierList::peutTransfererAuMoinsUnCourrier()) ;
         l'autorisation réelle reste vérifiée courrier par courrier côté
         serveur, ceci n'évite qu'un bouton qui échouerait silencieusement
         pour qui n'a aucun privilège de transfert. Même modale que
         MesCourriers, reciblée sur cette page. --}}
    {{-- Barre d'actions de sélection (2026-09-23, demande explicite de
         l'utilisateur : "make the two button transfer and classe appear
         only when we have selected") — n'apparaît qu'avec au moins un
         courrier coché (cases en wire:model.live, donc à jour à chaque
         clic) ; chaque bouton reste en plus soumis à son propre droit. --}}
    @if (! empty($selectionnes) && ($this->peutTransfererAuMoinsUnCourrier || $this->peutClasserAuMoinsUnCourrier))
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-brand-border bg-brand-accent-pale px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="mr-auto text-sm font-medium text-brand-accent">{{ __(':n courrier(s) sélectionné(s)', ['n' => count($selectionnes)]) }}</flux:text>
            @if ($this->peutTransfererAuMoinsUnCourrier)
                <flux:modal.trigger name="transferer-selection-liste-modal">
                    <flux:button size="sm" variant="primary" icon="arrow-up-tray">{{ __('Transférer la sélection') }}</flux:button>
                </flux:modal.trigger>
            @endif
            @if ($this->peutClasserAuMoinsUnCourrier)
                <flux:modal.trigger name="classer-selection-liste-modal">
                    <flux:button size="sm" variant="outline" icon="folder">{{ __('Classer la sélection') }}</flux:button>
                </flux:modal.trigger>
            @endif
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('selectionnes', [])">{{ __('Désélectionner') }}</flux:button>
        </div>
    @endif

    @if ($this->peutTransfererAuMoinsUnCourrier)
        {{-- 2026-10-23 (voir DECISIONS.md "Transfert direct au service et
             acceptation") — transfert groupé vers UN service, plus vers une
             personne : seuls les courriers "En attente de transfert" que
             l'utilisateur peut transférer sont réellement envoyés. --}}
        <flux:modal name="transferer-selection-liste-modal" class="w-full max-w-md">
            <form wire:submit="transfererSelection" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Transférer au service') }}</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">{{ __(':n courrier(s) sélectionné(s). Le responsable du service devra les accepter avant que vous lui remettiez les originaux.', ['n' => count($selectionnes)]) }}</flux:text>
                </div>

                @include('partials.cascade-service')

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Annuler') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Confirmer le transfert') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Module 3/9 — classement en masse (2026-09-22, demande explicite de
         l'utilisateur, "les trois" points d'entrée) : bouton visible dès que
         l'utilisateur a accès à au moins un dossier de classement (pas de
         privilège fixe, voir CourrierList::peutClasserAuMoinsUnCourrier()) ;
         l'autorisation réelle reste vérifiée courrier par courrier ET
         dossier par dossier côté serveur. --}}
    @if ($this->peutClasserAuMoinsUnCourrier)
        <flux:modal name="classer-selection-liste-modal" class="w-full max-w-sm">
            <form wire:submit="classerSelection" class="space-y-4">
                <flux:heading size="lg">{{ __('Classer dans un dossier') }}</flux:heading>
                <flux:text class="text-zinc-500">{{ __(':n courrier(s) sélectionné(s).', ['n' => count($selectionnes)]) }}</flux:text>

                {{-- 2026-10-12 — mêmes correctifs que showCourrier.blade.php
                     "Classer dans un dossier" (@error redondant avec
                     <flux:with-field> ; placeholder désactivé peu fiable
                     après un morph Livewire — voir son commentaire détaillé
                     pour le mécanisme exact). --}}
                <flux:select wire:model="dossierChoisi" :label="__('Dossier')">
                    <flux:select.option value="">{{ __('— Choisir un dossier —') }}</flux:select.option>
                    @foreach ($this->dossiersAccessibles as $dossier)
                        <flux:select.option value="{{ $dossier->id }}">{{ $dossier->nom }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Annuler') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Confirmer le classement') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <div>
        <flux:heading level="2" class="text-base">{{ __('Liste des courriers') }} ({{ $this->resultats->total() }})</flux:heading>

        <div class="mt-3">
            @if ($this->resultats->isEmpty())
                <flux:text class="text-zinc-500">{{ __('Aucun courrier ne correspond à ces critères.') }}</flux:text>
            @else
                <div x-data="squeletteMinimum()" class="@container overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div wire:loading.class.remove="hidden" x-ref="sentinelle" class="hidden" aria-hidden="true"></div>
                    {{-- Format de tableau (2026-10-27, « can the table follow this
                         format », capture d'un tableau de référence fournie) :
                         en-têtes en petites capitales sans fond, soulignés d'un
                         trait navy (colonne « # » retirée le 2026-10-29,
                         « remove the # numbers from the tables ») ; lignes sur UNE
                         seule ligne (hauteur relevée le 2026-10-28, « the space between the
                         rows is too narrow », puis le 2026-10-29, « add some space again » :
                         16 px en haut et en bas de chaque cellule) — un texte trop long est coupé (…) et lisible
                         en entier au survol (title) et dans le panneau « Aperçu ».
                         Les colonnes de texte (largeurs en % totalisant 100 %, contenu en
                         w-0 min-w-full qui ne force jamais leur largeur) absorbent
                         la place restante ; leur largeur minimale fait défiler le
                         tableau dans sa carte plutôt que de les écraser ; les autres
                         colonnes (w-px) se réduisent à leur contenu. Carte étroite
                         (panneau « Aperçu » ouvert à côté, @container) : « Date de
                         réception » (libellé du 2026-10-29) masquée — la date reste lisible dans le
                         panneau, et le chronomètre passe sous le statut. --}}
                    <table class="w-full text-sm">
                        <thead class="border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400">
                            <tr>
                                <th class="w-4 py-3 pl-4 pr-3" wire:click.stop>
                                    <input
                                        type="checkbox"
                                        class="rounded border-zinc-300"
                                        wire:click="basculerSelectionPage"
                                        @checked($this->resultats->isNotEmpty() && $this->resultats->pluck('id')->diff($selectionnes)->isEmpty())
                                    />
                                </th>
                                <th class="w-px whitespace-nowrap py-3 pr-3 @min-[60rem]:pr-5" wire:click.stop="trierPar('numero_reference')">
                                    <button type="button" class="inline-flex items-center gap-1 uppercase tracking-wide hover:text-zinc-800 dark:hover:text-zinc-300">
                                        {{ __('N° Courrier') }}
                                        <flux:icon.chevron-up-down class="size-3.5" />
                                    </button>
                                </th>
                                {{-- 2026-10-28 (« arrange it well ») — expéditeur et
                                     destinataire de nouveau dans deux colonnes (une seule
                                     information par colonne, 20 px entre colonnes en carte large, 12 px sinon). Carte
                                     étroite (< 60rem, panneau « Aperçu » ouvert) : la colonne
                                     Destinataire est masquée, le panneau l'affiche. --}}
                                <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Expéditeur') }}</th>
                                <th class="py-3 pr-3 @min-[60rem]:pr-5 @max-[60rem]:hidden">{{ __('Destinataire') }}</th>
                                <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Objet') }}</th>
                                @if ($this->contenu !== '' && $this->peutRechercherContenu)
                                    <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Extrait du document') }}</th>
                                @endif
                                <th class="w-px whitespace-nowrap py-3 pr-3 @min-[60rem]:pr-5 @max-[44rem]:hidden">{{ __('Date de réception') }}</th>
                                <th class="w-px py-3 pr-3 @min-[60rem]:pr-5">{{ __('Statut') }}</th>
                                {{-- Libellé masqué visuellement (bouton « … » explicite) : libère
                                     la largeur nécessaire aux autres colonnes, reste lu par les
                                     lecteurs d'écran (2026-10-27). --}}
                                <th class="relative w-px py-3 pr-4"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        {{-- Squelette (2026-09-24) — même nombre de colonnes
                             que l'en-tête ci-dessus (8, +1 si la colonne
                             "Extrait" est affichée), pour ne jamais
                             désaligner les bordures de colonnes pendant le
                             chargement. Visibilité pilotée par l'état
                             `visible` de squeletteMinimum() (x-data posé sur
                             le DIV englobant ci-dessus) — durée minimale
                             garantie, voir son commentaire dans
                             resources/js/app.js (2026-09-24, "am not seeing
                             animation... in page"). wire:loading.table-row-group
                             N'EST PAS un modificateur reconnu par Livewire
                             (bug réel constaté en capture d'écran le même
                             jour) — plus aucun wire:loading direct ici,
                             seule la sentinelle ci-dessus en porte un. --}}
                        <tbody x-show="visible" x-cloak class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            <x-skeleton.table-rows :cols="$this->contenu !== '' && $this->peutRechercherContenu ? 9 : 8" />
                        </tbody>
                        <tbody x-show="!visible" class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->resultats as $courrier)
                                <tr
                                    wire:key="ligne-{{ $courrier->id }}"
                                    wire:click="ouvrirApercu({{ $courrier->id }})"
                                    x-on:click="apercuMobile = true"
                                    class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                    @class(['bg-brand-accent/5' => $this->courrierApercu?->id === $courrier->id])
                                >
                                    @php
                                        $expediteurLigne = $courrier->expediteur_organisation ?: $courrier->expediteur_nom ?: __('—');
                                        $destinataireLigne = $courrier->destinataire ?: ($courrier->service?->nom ?? __('—'));
                                        // Largeurs des colonnes de texte : 100 % à elles seules (avec ou sans
                                        // la colonne Destinataire, masquée en carte étroite), les autres
                                        // colonnes (w-px) gardent leur contenu.
                                        $avecExtrait = $this->contenu !== '' && $this->peutRechercherContenu;
                                    @endphp
                                    <td class="py-4 pl-4 pr-3" wire:click.stop>
                                        <input type="checkbox" class="rounded border-zinc-300" wire:model.live="selectionnes" value="{{ $courrier->id }}" />
                                    </td>
                                    <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5 font-medium text-brand-accent">
                                        <span class="block max-w-36 truncate" title="{{ $courrier->numero_reference }}">{{ $courrier->numero_reference }}</span>
                                    </td>
                                    <td class="{{ $avecExtrait ? 'w-[18%] @max-[60rem]:w-[25%]' : 'w-[22%] @max-[60rem]:w-[30%]' }} min-w-20 py-4 pr-3 @min-[60rem]:pr-5">
                                        <div class="w-0 min-w-full truncate text-zinc-800 dark:text-zinc-200" title="{{ $expediteurLigne }}">{{ $expediteurLigne }}</div>
                                    </td>
                                    <td class="{{ $avecExtrait ? 'w-[10%]' : 'w-[12%]' }} min-w-16 py-4 pr-3 @min-[60rem]:pr-5 @max-[60rem]:hidden">
                                        <div class="w-0 min-w-full truncate text-zinc-600 dark:text-zinc-400" title="{{ $destinataireLigne }}">{{ $destinataireLigne }}</div>
                                    </td>
                                    <td class="{{ $avecExtrait ? 'w-[42%] @max-[60rem]:w-[45%]' : 'w-[66%] @max-[60rem]:w-[70%]' }} min-w-22 @min-[60rem]:min-w-32 py-4 pr-3 @min-[60rem]:pr-5">
                                        <div class="flex w-0 min-w-full items-center gap-1.5">
                                            <span class="min-w-0 truncate" title="{{ $courrier->objet }}">{{ $courrier->objet }}</span>
                                        @if ($courrier->confidentialite > 1)
                                            {{-- Navy, pas teal (2026-09-17, système de couleurs GEC —
                                                 "confidentiel" n'a pas de couleur dédiée dans le
                                                 système fourni, clarifié avec l'utilisateur : rejoint
                                                 Navy, une caractéristique structurelle/d'accès, pas un
                                                 statut de workflow). Span habillé directement (pas
                                                 flux:badge color=, qui n'accepte pas nos hex exacts).
                                                 Niveau NUMÉRIQUE (2026-09-21, "numbers ... not
                                                 confidential or whatever", puis "THE LABEL SHOULD
                                                 BE NIVEAU 1 OR LEVEL 1"). --}}
                                            <span class="inline-flex shrink-0 items-center whitespace-nowrap rounded-md bg-brand-navy px-1.5 py-0.5 text-xs font-semibold text-white">
                                                {{ __('Niveau :n', ['n' => $courrier->confidentialite]) }}
                                            </span>
                                        @endif
                                        </div>
                                    </td>
                                    @if ($this->contenu !== '' && $this->peutRechercherContenu)
                                        <td class="w-[30%] min-w-22 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500 italic"><div class="w-0 min-w-full truncate" title="{{ $this->extraitTexteOcr($courrier) }}">{{ $this->extraitTexteOcr($courrier) ?? __('—') }}</div></td>
                                    @endif
                                    <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5 text-zinc-600 @max-[44rem]:hidden">{{ $courrier->date_mouvement->format('d/m/Y') }}</td>
                                    <td class="py-4 pr-3 @min-[60rem]:pr-5">
                                        {{-- Chronomètre à côté du statut (2026-09-28, "when the date
                                             is comming soon the colours should change") — même badge
                                             que ShowCourrier/Dashboard, se fige automatiquement si
                                             aucune échéance n'existe (voir son @if interne).
                                             2026-10-27 — sur la même ligne que le statut (format
                                             de tableau compact), pastille resserrée. --}}
                                        <div class="flex items-center gap-1 whitespace-nowrap @max-[44rem]:flex-col @max-[44rem]:items-start">
                                            <x-statut-badge :statut="$courrier->statut" class="rounded-md! px-2! font-semibold!" />
                                            <x-chronometre :courrier="$courrier" :masquer-temps="true" />
                                        </div>
                                    </td>
                                    <td class="py-4 pr-4 text-center" wire:click.stop>
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" square :aria-label="__('Actions')" />
                                            <flux:menu>
                                                <flux:menu.item icon="eye" :href="route('courriers.show', $courrier->id)" wire:navigate>
                                                    {{ __('Voir') }}
                                                </flux:menu.item>
                                                @can('update', $courrier)
                                                    <flux:menu.item icon="pencil-square" :href="route('courriers.modifier', $courrier->id)" wire:navigate>
                                                        {{ __('Modifier') }}
                                                    </flux:menu.item>
                                                @endcan
                                            </flux:menu>
                                        </flux:dropdown>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $this->resultats->links() }}
                </div>
            @endif
        </div>
    </div>
        </div>
        {{-- ^ ferme "Liste des courriers" (K) puis la colonne de gauche
             "space-y-6" (B) — le panneau ci-dessous devient un vrai sibling
             de cette colonne dans la grille A, pas un enfant imbriqué. --}}

        @if ($this->courrierApercu)
            @php $courrier = $this->courrierApercu; @endphp
            {{-- Même mécanisme pdf.js/document-preview.js que le panneau
                 "Aperçu du document" du formulaire d'enregistrement
                 (registrationForm.blade.php) — réutilisé tel quel, pointant
                 vers courriers.document.apercu (déjà existant) au lieu de
                 brouillons.apercu. wire:key sur l'id du courrier : changer
                 de courrier doit recharger un NOUVEAU document, jamais
                 réutiliser l'instance pdf.js du précédent.
                 Petits écrans (2026-10-27, « should we hide the sidepanel…
                 on the responsive side ») : plus empilé tout en bas, sous le
                 tableau — feuille plein écran ouverte au toucher d'une ligne
                 (apercuMobile). Fermée = invisible + décalée hors écran, PAS
                 display:none : document-preview.js calcule l'échelle sur la
                 largeur de la boîte, qui doit rester mesurable. --}}
            <div
                wire:key="panneau-apercu-{{ $courrier->id }}"
                x-data="squeletteMinimum()"
                class="self-start rounded-2xl border border-brand-border bg-white shadow-sm max-lg:fixed max-lg:inset-0 max-lg:z-50 max-lg:overflow-y-auto max-lg:overscroll-contain max-lg:rounded-none max-lg:border-0 max-lg:transition max-lg:duration-200 dark:border-zinc-700 dark:bg-zinc-900"
                :class="apercuMobile ? 'max-lg:visible max-lg:translate-x-0' : 'max-lg:invisible max-lg:translate-x-full'"
            >
                {{-- Panneau désormais TOUJOURS affiché (demande explicite de
                     l'utilisateur, 2026-09-18 : "those cards panel should
                     always be shown not mask") — plus de bouton "Fermer"/X,
                     qui n'aurait plus de sens : il n'y a plus d'état "masqué"
                     vers lequel revenir. --}}
                <div wire:loading.class.remove="hidden" x-ref="sentinelle" class="hidden" aria-hidden="true"></div>
                <div class="flex items-center gap-2 border-b border-brand-border p-4 max-lg:sticky max-lg:top-0 max-lg:z-10 max-lg:bg-white dark:border-zinc-700 dark:max-lg:bg-zinc-900">
                    <flux:icon.information-circle class="size-4 text-brand-accent" />
                    <flux:heading level="3">{{ __('Aperçu du courrier') }}</flux:heading>
                    {{-- Petits écrans seulement : sur grand écran le panneau reste toujours affiché. --}}
                    <flux:button size="sm" variant="ghost" icon="x-mark" class="ml-auto lg:hidden" x-on:click="apercuMobile = false">{{ __('Fermer') }}</flux:button>
                </div>

                {{-- Squelette (2026-09-24) — changer de ligne
                     (ouvrirApercu) ou de filtre (qui peut changer le
                     courrier par défaut du panneau) redéclenche une requête
                     Livewire sur TOUT le composant ; ce panneau doit lui
                     aussi montrer un état de chargement pendant ce court
                     instant, pas rester figé sur l'ancien courrier. Durée
                     minimale garantie via squeletteMinimum() (2026-09-24,
                     "am not seeing animation... in page"). --}}
                <div x-show="visible" x-cloak>
                    <x-skeleton.panel />
                </div>

                <div x-show="!visible" class="p-4">
                    {{-- 2026-10-23 — contenu masqué tant que le service n'a pas
                         accepté le courrier (CourrierPolicy::voirContenu()). --}}
                    @if ($courrier->fichier_path && auth()->user()->cannot('voirContenu', $courrier))
                        <x-apercu.contenu-masque />
                                            <div class="mt-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            @can('update', $courrier)
                                <flux:button size="sm" variant="primary" icon="pencil-square" :href="route('courriers.modifier', $courrier->id)" wire:navigate>
                                    {{ __('Éditer') }}
                                </flux:button>
                            @endcan
                            {{-- "Supprimer" (2026-09-23) : courriers.supprimer
                                 (Administrateur par défaut), suppression
                                 LOGIQUE avec motif obligatoire tracé dans
                                 l'historique, jamais un courrier archivé
                                 (Règle n°5, CourrierPolicy::delete()). --}}
                            @can('delete', $courrier)
                                <flux:modal.trigger name="courrier-suppression">
                                    <flux:button size="sm" variant="danger" icon="trash">{{ __('Supprimer') }}</flux:button>
                                </flux:modal.trigger>
                            @endcan
                        </div>
                    </div>
                    @elseif ($courrier->fichier_path)
                        {{-- 2026-11-09 — aperçu sécurisé (pages filigranées), plus le fichier. --}}
                        <div
                            wire:ignore
                            x-data="{
                                erreur: null,
                                zoom: 100,
                                pageActuelle: 1,
                                numPages: 1,
                                url: @js(route('apercus.manifeste', ['courrier', $courrier->id])),
                                instance() {
                                    const apercu = window.DocumentPreview.obtenir(this.url + ':liste');
                                    apercu.boite = document.getElementById('apercu-liste-{{ $courrier->id }}-corps');
                                    apercu.conteneur = document.getElementById('apercu-liste-{{ $courrier->id }}-conteneur');

                                    return apercu;
                                },
                                async init() {
                                    try {
                                        const apercu = this.instance();
                                        // Cliquer-glisser (2026-09-24) — plutôt
                                        // que de devoir viser la barre de
                                        // défilement une fois zoomé au-delà
                                        // de 100 %.
                                        window.DocumentPreview.activerDeplacement(apercu.boite);
                                        // Molette pour zoomer (2026-09-24, retour utilisateur :
                                        // « why can[t] i zoom without the + sign ») — Ctrl/Cmd +
                                        // molette, même bornes que zoomIn()/zoomOut() ci-dessous.
                                        // ATTENTION — jamais de guillemet droit dans un commentaire
                                        // à l'intérieur d'un attribut x-data (délimité par des
                                        // guillemets droits) : ça referme l'attribut HTML en plein
                                        // milieu et fait fuir tout le reste comme texte visible sur
                                        // la page (bug réel trouvé le 2026-09-24, voir notes internes — guillemets français «» seulement).
                                        window.DocumentPreview.activerZoomMolette(apercu.boite, (pas) => this.zoomer(Math.min(Math.max(this.zoom + pas, 50), 200)));
                                        await apercu.charger(this.url, apercu.boite, apercu.conteneur);
                                        this.zoom = Math.round((apercu.echelle / apercu.echelleBase) * 100);
                                        this.pageActuelle = apercu.pageActuelle;
                                        this.numPages = apercu.numPages;
                                    } catch (e) {
                                        console.error('[aperçu courrier liste]', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                async zoomer(nouveauZoom) {
                                    this.zoom = nouveauZoom;

                                    try {
                                        await this.instance().zoomer(this.zoom);
                                    } catch (e) {
                                        console.error('[aperçu courrier liste] zoom', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                zoomIn() { this.zoomer(Math.min(this.zoom + 25, 200)); },
                                zoomOut() { this.zoomer(Math.max(this.zoom - 25, 50)); },
                                async pageSuivante() {
                                    try {
                                        const apercu = this.instance();
                                        await apercu.pageSuivante();
                                        this.pageActuelle = apercu.pageActuelle;
                                    } catch (e) {
                                        console.error('[aperçu courrier liste] page suivante', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                async pagePrecedente() {
                                    try {
                                        const apercu = this.instance();
                                        await apercu.pagePrecedente();
                                        this.pageActuelle = apercu.pageActuelle;
                                    } catch (e) {
                                        console.error('[aperçu courrier liste] page précédente', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                            }"
                            x-init="init()"
                        >
                            <x-apercu.barre-outils
                                variant="panneau"
                                :nom-fichier="basename($courrier->fichier_path)"
                                :agrandir-evenement="'apercu-liste-agrandi-'.$courrier->id"
                            />
                            <x-apercu.visionneuse id="apercu-liste-{{ $courrier->id }}" />
                        </div>

                        {{-- Modale "Agrandir" — même patron corrigé que ShowCourrier/
                             RegistrationForm (voir leur commentaire : hauteur imposée
                             EXPLICITEMENT sur <flux:modal> lui-même, variant="bare" ne
                             garantit aucune hauteur propre ; corps en flex-1 min-h-0).
                             Nom de modale indexé sur l'id du courrier : plusieurs
                             lignes de la liste partagent la même page, chacune doit
                             pouvoir ouvrir SA propre modale sans collision. Instance
                             pdf.js séparée du petit panneau ci-dessus. --}}
                        <div
                            wire:key="apercu-liste-agrandi-{{ $courrier->id }}"
                            wire:ignore
                            x-data="{
                                erreur: null,
                                zoom: 100,
                                url: @js(route('apercus.manifeste', ['courrier', $courrier->id])),
                                pageActuelle: 1,
                                numPages: 1,
                                instance() {
                                    const apercu = window.DocumentPreview.obtenir(this.url + ':liste-agrandi');
                                    apercu.boite = document.getElementById('apercu-liste-agrandi-{{ $courrier->id }}-corps');
                                    apercu.conteneur = document.getElementById('apercu-liste-agrandi-{{ $courrier->id }}-conteneur');

                                    return apercu;
                                },
                                async rendre() {
                                    try {
                                        const apercu = this.instance();
                                        window.DocumentPreview.activerDeplacement(apercu.boite);
                                        // Molette pour zoomer (2026-09-24, retour utilisateur :
                                        // « why can[t] i zoom without the + sign ») — Ctrl/Cmd +
                                        // molette, même bornes que zoomIn()/zoomOut() ci-dessous.
                                        // ATTENTION — jamais de guillemet droit dans un commentaire
                                        // à l'intérieur d'un attribut x-data (délimité par des
                                        // guillemets droits) : ça referme l'attribut HTML en plein
                                        // milieu et fait fuir tout le reste comme texte visible sur
                                        // la page (bug réel trouvé le 2026-09-24, voir notes internes — guillemets français «» seulement).
                                        window.DocumentPreview.activerZoomMolette(apercu.boite, (pas) => this.zoomer(Math.min(Math.max(this.zoom + pas, 50), 200)));
                                        await apercu.charger(this.url, apercu.boite, apercu.conteneur, true, true);
                                        this.zoom = Math.round((apercu.echelle / apercu.echelleBase) * 100);
                                        this.pageActuelle = apercu.pageActuelle;
                                        this.numPages = apercu.numPages;
                                    } catch (e) {
                                        console.error('[aperçu liste agrandi]', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                async zoomer(nouveauZoom) {
                                    this.zoom = nouveauZoom;

                                    try {
                                        await this.instance().zoomer(this.zoom);
                                    } catch (e) {
                                        console.error('[aperçu liste agrandi] zoom', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                zoomIn() { this.zoomer(Math.min(this.zoom + 25, 200)); },
                                zoomOut() { this.zoomer(Math.max(this.zoom - 25, 50)); },
                                async pageSuivante() {
                                    try {
                                        const apercu = this.instance();
                                        await apercu.pageSuivante();
                                        this.pageActuelle = apercu.pageActuelle;
                                    } catch (e) {
                                        console.error('[aperçu liste agrandi] page suivante', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                async pagePrecedente() {
                                    try {
                                        const apercu = this.instance();
                                        await apercu.pagePrecedente();
                                        this.pageActuelle = apercu.pageActuelle;
                                    } catch (e) {
                                        console.error('[aperçu liste agrandi] page précédente', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                            }"
                            x-on:modal-show.document="if ($event.detail.name === 'apercu-liste-agrandi-{{ $courrier->id }}') { rendre(); }"
                        >
                            {{-- 2026-09-24 (retour utilisateur : grand espace vide sous
                                 "Tous les courriers") — SANS "display: flex" forcé (`flex!`)
                                 ICI : ça écrasait la règle par défaut du navigateur qui cache
                                 un popover fermé (`display: none`), laissant la modale
                                 réserver ses 88vh de hauteur EN PERMANENCE dans le flux de la
                                 page, même fermée. La mise en page en colonne reste sur le DIV
                                 intérieur juste en dessous (flex h-full flex-col, sans !),
                                 qui n'a d'effet qu'une fois la modale réellement affichée. --}}
                            <flux:modal name="apercu-liste-agrandi-{{ $courrier->id }}" variant="bare" class="h-[88vh]! max-h-[88vh]! w-full! max-w-7xl! overflow-hidden! rounded-xl! bg-white! p-0! shadow-lg! dark:bg-zinc-900!">
                                <div class="flex h-full min-h-0 flex-col">
                                    <x-apercu.barre-outils
                                        variant="modale"
                                        :nom-fichier="basename($courrier->fichier_path)"
                                        :numero-reference="$courrier->numero_reference"
                                        :fermer-modale="'apercu-liste-agrandi-'.$courrier->id"
                                    />

                                    <div class="flex min-h-0 flex-1 flex-col min-[900px]:flex-row">
                                        <x-apercu.visionneuse id="apercu-liste-agrandi-{{ $courrier->id }}" grandir />
                                        <div class="h-64 shrink-0 min-[900px]:h-auto min-[900px]:w-90">
                                            <x-apercu.panneau-correspondance
                                                :objet="$courrier->objet"
                                                :numero-reference="$courrier->numero_reference"
                                                :expediteur-nom="$courrier->expediteur_nom"
                                                :expediteur-organisation="$courrier->expediteur_organisation"
                                                :expediteur-fonction="$courrier->expediteur_fonction"
                                                :expediteur-adresse="$courrier->expediteur_adresse"
                                                :expediteur-telephone="$courrier->expediteur_telephone"
                                                :expediteur-email="$courrier->expediteur_email"
                                                :destinataire="$courrier->destinataire"
                                                :date="$courrier->date_mouvement"
                                                :nom-fichier="basename($courrier->fichier_path)"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </flux:modal>
                        </div>
                    @else
                        <div class="flex h-40 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-brand-border bg-brand-surface-soft text-center">
                            <flux:icon.document class="size-8 text-brand-text-muted" />
                            <span class="text-sm text-brand-text-secondary">{{ __('Aucun document numérisé pour ce courrier.') }}</span>
                        </div>
                    @endif

                    <flux:separator class="my-4" text="{{ __('Informations du courrier') }}" />

                    <dl class="space-y-3 text-sm">
                        <div class="flex items-start gap-2">
                            <flux:icon.user class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <dt class="text-zinc-500">{{ __('Expéditeur') }}</dt>
                                <dd class="font-medium">{{ $courrier->expediteur_organisation ?: $courrier->expediteur_nom ?: __('—') }}</dd>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.users class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <dt class="text-zinc-500">{{ __('Destinataire') }}</dt>
                                <dd class="font-medium">{{ $courrier->destinataire ?: __('—') }}</dd>
                            </div>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Objet') }}</dt>
                            <dd class="font-medium">{{ $courrier->objet }}</dd>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.calendar class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <dt class="text-zinc-500">{{ __('Date de réception') }}</dt>
                                <dd class="font-medium">{{ $courrier->date_mouvement->format('d/m/Y') }}</dd>
                            </div>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Statut') }}</dt>
                            <dd class="mt-1"><x-statut-avec-echeance :courrier="$courrier" :masquer-temps="true" /></dd>
                        </div>
                        @if ($courrier->piecesJointes->isNotEmpty() && auth()->user()->can('voirPiecesJointes', $courrier))
                            <div class="flex items-start gap-2">
                                <flux:icon.paper-clip class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                <div>
                                    <dt class="text-zinc-500">{{ __('Pièce(s) jointe(s)') }}</dt>
                                    {{-- 2026-11-09 — aperçu sécurisé, plus aucun téléchargement. --}}
                                    @php $peutVoirContenuPj = auth()->user()->can('voirContenu', $courrier); @endphp
                                    @foreach ($courrier->piecesJointes as $pieceJointe)
                                        <dd class="font-medium">
                                            @if ($peutVoirContenuPj)
                                                <x-apercu.piece-jointe :piece-jointe="$pieceJointe" suffixe="liste" :reference="$courrier->numero_reference" />
                                            @else
                                                {{ $pieceJointe->nom_original }}
                                            @endif
                                        </dd>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </dl>



                    @can('delete', $courrier)
                        <flux:modal name="courrier-suppression" class="md:w-md">
                            <div class="space-y-4">
                                <flux:heading size="lg">{{ __('Supprimer le courrier :ref ?', ['ref' => $courrier->numero_reference]) }}</flux:heading>
                                <flux:text>{{ __('Le courrier disparaîtra des listes et des recherches. Il reste conservé en base avec tout son historique (suppression logique) ; le motif est enregistré dans l\'historique.') }}</flux:text>
                                <flux:textarea wire:model="motifSuppression" :label="__('Motif de la suppression')" rows="3" required />
                                <div class="flex justify-end gap-2">
                                    <flux:modal.close>
                                        <flux:button variant="ghost">{{ __('Annuler') }}</flux:button>
                                    </flux:modal.close>
                                    <flux:button variant="danger" icon="trash" wire:click="supprimerCourrier({{ $courrier->id }})">{{ __('Supprimer') }}</flux:button>
                                </div>
                            </div>
                        </flux:modal>
                    @endcan
                </div>
            </div>
        @endif
    </div>
</section>
