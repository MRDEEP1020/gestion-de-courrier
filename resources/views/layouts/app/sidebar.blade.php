<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>
{{-- bg-brand-surface (2026-09-17, système de couleurs GEC complet fourni
         par l'utilisateur — remplace bg-white du 2026-09-17 matin) : le
         schéma fourni par l'utilisateur est explicite — sidebar Navy | fond
         de page #F5F8FC | carte BLANCHE flottant par-dessus. --color-brand-
         surface vaut maintenant #F5F8FC (voir app.css), donc le fond de
         page et les cartes blanches (bg-white + border + shadow-sm, déjà en
         place) redeviennent visuellement distincts, comme dans le schéma. --}}

<body class="min-h-screen bg-brand-surface dark:bg-zinc-800">
    <x-chargement-page />
    {{-- bg-brand-navy (2026-09-17, système de couleurs GEC —
             --color-brand-navy = #0B1F3A, "Sidebar background, brand
             identity"). class="dark" scope la sidebar en mode sombre UNIQUEMENT pour
             elle (voir @custom-variant dark ci-dessus dans app.css, qui
             matche tout ancêtre .dark, pas seulement <html>) : réutilise
             tous les utilitaires dark: déjà écrits sur les items de
             sidebar (texte blanc/80, survol...) sans les redéfinir un par
             un — la sidebar a son propre thème sombre en permanence, que
             le reste de l'app soit en clair ou en sombre. --}}
    {{-- @persist('app-sidebar') (2026-09-18, demande explicite de
             l'utilisateur : "the sidebar shouldn't refresh as the main
             refreshes or even when it refreshes it should still stay open
             if we open it") — la sidebar entière est désormais un bloc
             persistant Livewire : à chaque wire:navigate, ce nœud DOM
             précis n'est JAMAIS remplacé par la version nouvellement
             rendue, juste déplacé tel quel dans la nouvelle page (voir
             @persist('toast') plus bas, même mécanisme, déjà en place).
             Conséquence directe : tout état ouvert/fermé cliqué par
             l'utilisateur (via <ui-disclosure>) survit à chaque navigation,
             plus jamais recalculé/écrasé par le rendu serveur suivant — ce
             qui règle aussi le "ferme le menu à chaque navigation" constaté
             par l'utilisateur (les tentatives précédentes de recalculer
             "expanded" par route, ou de forcer un wire:key différent pour
             rouvrir le bon groupe, se battaient contre le morphing
             wire:navigate au lieu de simplement ne plus être remorphées).
             Contrepartie connue et traitée : `:current` (surlignage du lien
             actif) est lui aussi gelé au tout premier rendu une fois
             persistant — resynchronisé côté client après chaque navigation
             par le script en bas de ce fichier (écoute
             "livewire:navigated", compare href à l'URL courante, bascule
             l'attribut data-current dont dépendent déjà tous les styles
             Flux "actif" ci-dessous — aucune classe Flux interne à
             deviner). --}}
    @persist('app-sidebar')
    {{-- w-72 (2026-10-08, demande explicite de l'utilisateur, capture
             d'écran montrant "Référentiels (règles de..." tronqué) — Flux
             donne w-64 (256px) par défaut via un sélecteur :where() (zéro
             spécificité, pensé pour être surchargé) ; w-72 (288px) laisse
             assez de place aux libellés les plus longs sans élargir de
             façon disproportionnée. scrollbar-gutter-stable (même
             demande, retour utilisateur : "the scrollbar hide some of the
             texts") — `sticky` ajoute overflow-y-auto sur ce même élément
             padded (voir vendor/livewire/flux/.../sidebar/index.blade.php) ;
             sans scrollbar-gutter, la barre de défilement du navigateur
             s'affiche EN SURIMPRESSION du texte au lieu de lui laisser sa
             propre place, coupant les derniers caractères des libellés
             longs ("Référentiels (règles de...", "Paramètres syst[è]me").
             `stable` réserve la largeur de la scrollbar en permanence
             (plutôt qu'au moment où elle apparaît), pour que le texte ne
             se décale pas non plus selon le contenu visible. --}}
    {{-- relative isolate (2026-10-09, habillage décoratif NSIA — voir
             x-sidebar-artwork) : `relative` fait de <flux:sidebar> le
             conteneur de positionnement pour le calque décoratif absolute
             ci-dessous ; `isolate` crée une NOUVELLE pile d'empilement CSS
             sur cet élément (isolation: isolate), pour que le z-index
             NÉGATIF du calque décoratif reste contenu STRICTEMENT à
             l'intérieur de la sidebar (jamais sous le fond de <body> ou
             d'un autre élément de page). Rappel d'ordre de peinture CSS
             (stacking order), qui est la raison du z-index négatif plutôt
             que 0 : dans une pile d'empilement, les enfants positionnés en
             z-index négatif se peignent AVANT (donc en dessous) le contenu
             statique en flux normal (en-tête, nav, bouton déconnexion) —
             un z-index 0/auto sur un enfant *positionné* se peindrait au
             contraire PAR-DESSUS ce même contenu statique. bg-gradient-to-b
             (nouveaux tokens --color-brand-sidebar-bg-from/-to, voir
             app.css) remplace bg-brand-navy UNIQUEMENT ici — dégradé dédié
             plus sombre fourni par l'utilisateur, sans toucher à
             --color-brand-navy utilisé ailleurs dans l'app. --}}
    <flux:sidebar sticky collapsible="mobile"
        class="dark relative isolate w-72 border-e border-white/10 bg-linear-to-b from-brand-sidebar-bg-from to-brand-sidebar-bg-to">
        <x-sidebar-artwork />

        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        {{-- Sidebar reconstruite intégralement pour correspondre EXACTEMENT
                 à la maquette image fournie par l'utilisateur (2026-09-18,
                 demande explicite : "redo it with that image exactly don't
                 keep things no redo completely even the sidebar drop the
                 current to create this new from the image") — les liens
                 "bonus" gardés lors d'une itération précédente (Mes
                 courriers, Courrier confidentiel, Courriers enregistrés,
                 absents de cette image) sont RETIRÉS de la sidebar pour
                 correspondre exactement ; leurs pages/routes existent
                 toujours et restent accessibles directement (rien n'est
                 supprimé côté application, seulement ce lien de sidebar).
                 Groupes sans page réelle derrière à ce jour (Dossiers &
                 Archives, Organisation, Automatisation, Workflows, SLA &
                 Alertes, Sécurité & Audit, Statistiques & Rapports,
                 Notifications, Aide) : marqués "Bientôt" plutôt qu'omis ou
                 pointés vers une page inexistante (clarifié avec
                 l'utilisateur, AskUserQuestion). --}}
        {{-- Menus pilotés par privilège (2026-09-23, demande explicite
                 de l'utilisateur : "all in sidebar should be permission even
                 the submenu and menu", voir DECISIONS.md "Menus pilotés par
                 privilège") : CHAQUE entrée — y compris les entrées "Bientôt"
                 — est gouvernée par un privilège, et CHAQUE groupe n'apparaît
                 que si au moins une de ses entrées est visible (plus de titre
                 de groupe vide). Seuls "Paramètres" (son propre compte : mot de
                 passe, 2FA) et "Déconnexion" restent toujours visibles —
                 les retirer enfermerait l'utilisateur. Calculé une fois ici
                 (la sidebar est @persist : un changement de privilège se voit
                 au prochain chargement complet de page). --}}
        @php
            $u = auth()->user();
            $menu = [
                'tableau_de_bord' => $u->hasPrivilege('dashboard.voir'),
                // 2026-10-30 — « Mes courriers » au menu ; « Tous les courriers »
                // a sa propre clé de menu (l'Agent garde la recherche sans l'entrée).
                'courriers_mes' => $u->can('voirMesCourriers', App\Models\Courrier::class),
                'courriers_tous' => $u->can('rechercher', App\Models\Courrier::class) && $u->hasPrivilege('courriers.menu_tous_les_courriers'),
                'courriers_calendrier' => $u->can('calendrier', App\Models\Courrier::class),
                'courriers_nouveau' => $u->can('create', App\Models\Courrier::class),
                'courriers_numeriser' => $u->can('numeriser', App\Models\Courrier::class),
                'courriers_affectations' =>
                    $u->hasPrivilege('courriers.affecter_tout') || $u->hasPrivilege('courriers.affecter_service'),
                'courriers_traitement' =>
                    $u->hasPrivilege('courriers.traiter_tout') || $u->hasPrivilege('courriers.traiter_affecte'),
                'dossiers_tous' => $u->can('viewAny', App\Models\DossierClassement::class),
                'dossiers_creer' => $u->can('create', App\Models\DossierClassement::class),
                'admin_utilisateurs' => $u->can('gererUtilisateurs', App\Models\Privilege::class),
                'admin_profils' => $u->can('gerer', App\Models\Privilege::class),
                'admin_organisation' => $u->can('viewAny', App\Models\OrganizationUnit::class),
                'admin_regles' => $u->can('viewAny', App\Models\RegleClassement::class),
                'admin_automatisation' => $u->hasPrivilege('administration.automatisation'),
                'admin_workflows' => $u->hasPrivilege('administration.workflows'),
                'admin_sla' => $u->hasPrivilege('administration.sla'),
                'admin_dossier_surveille' => $u->hasPrivilege('administration.dossier_surveille'),
                'admin_audit' => $u->hasPrivilege('administration.audit'),
                'stats_dashboard' => $u->hasPrivilege('statistiques.consulter'),
                'stats_rapports' => $u->hasPrivilege('statistiques.rapports'),
                'notifications' => $u->hasPrivilege('general.notifications'),
                'aide' => $u->hasPrivilege('general.aide'),
            ];
            // "Archives" est un nœud de la page Dossiers : il faut aussi y avoir accès.
            $menu['dossiers_archives'] = $menu['dossiers_tous'] && $u->hasPrivilege('dossiers_classement.archives');
            $groupe = fn(string $prefixe) => collect($menu)
                ->filter(fn($visible, $cle) => str_starts_with($cle, $prefixe) && $visible)
                ->isNotEmpty();

            // Module 7 (2026-10-05) — compteur affiché UNE FOIS au tout premier
            // rendu (même piège que :current ci-dessus : ce bloc est
            // @persist, jamais réévalué côté serveur après le premier chargement
            // complet) ; resynchronisé ensuite à chaque wire:navigate par le
            // script en bas de ce fichier, à partir de la cloche de l'en-tête
// (elle, hors du bloc persistant, donc toujours fraîche).
$compteurNotificationsNonLues = $menu['notifications'] ? $u->unreadNotifications()->count() : 0;
        @endphp
        <flux:sidebar.nav>
            @if ($menu['tableau_de_bord'])
                <flux:sidebar.group :heading="__('Général')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')"
                        :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Tableau de bord') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            @endif

            {{-- ui-disclosure-group exclusive (primitive Flux native,
                     vendor/livewire/flux/dist/flux-lite.min.js — "A('disclosure-group', ...)")
                     — demande explicite de l'utilisateur : un seul groupe
                     replié/déplié ouvert à la fois (ouvrir Administration
                     ferme Courriers, etc.), jamais exposé par un composant
                     <flux:...> dédié mais utilisable directement comme
                     élément personnalisé déjà enregistré globalement.
                     "expanded" ci-dessous ne compte plus que pour le TOUT
                     premier rendu de cette page persistante (voir
                     @persist('app-sidebar') plus haut) — ouvre le bon
                     groupe au premier chargement selon la page visitée,
                     puis n'est plus jamais réévalué : l'état ouvert/fermé
                     réel est ensuite entièrement piloté par les clics de
                     l'utilisateur et survit à toute navigation ultérieure. --}}
            <ui-disclosure-group exclusive>
                @if ($groupe('courriers_'))
                    <flux:sidebar.group :heading="__('Courriers')" expandable
                        :expanded="request()->routeIs(['courriers.mes-courriers', 'courriers.rechercher', 'courriers.nouveau', 'courriers.confidentiel', 'courriers.numeriser-nouveau', 'courriers.calendrier']) && request()->query('sens') === null"
                        icon="envelope" class="grid">
                        @if ($menu['courriers_mes'])
                            <flux:sidebar.item icon="inbox-stack" :href="route('courriers.mes-courriers')"
                                :current="request()->routeIs('courriers.mes-courriers')" wire:navigate>
                                {{ __('Mes courriers') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($menu['courriers_tous'])
                            <flux:sidebar.item icon="list-bullet" :href="route('courriers.rechercher')"
                                :current="request()->routeIs('courriers.rechercher') && request()->query('sens') === null"
                                wire:navigate>
                                {{ __('Tous les courriers') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($menu['courriers_calendrier'])
                            <flux:sidebar.item icon="calendar-days" :href="route('courriers.calendrier')"
                                :current="request()->routeIs('courriers.calendrier')" wire:navigate>
                                {{ __('Calendrier') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($menu['courriers_nouveau'])
                            <flux:sidebar.item icon="document-plus" :href="route('courriers.nouveau')"
                                :current="request()->routeIs('courriers.nouveau')" wire:navigate>
                                {{ __('Enregistrer un courrier') }}
                            </flux:sidebar.item>
                        @endif
                        {{-- Flux réel confirmé : scan d'abord (voir DECISIONS.md "Flux scan-first").
                         Privilège propre courriers.numeriser depuis le 2026-09-23. --}}
                        @if ($menu['courriers_numeriser'])
                            <flux:sidebar.item icon="camera" :href="route('courriers.numeriser-nouveau')"
                                :current="request()->routeIs('courriers.numeriser-nouveau')" wire:navigate>
                                {{ __('Numérisation & OCR') }}
                            </flux:sidebar.item>
                        @endif
                        {{-- "Transferts" (file d'attente WorkflowQueue) retiré le
                         2026-09-24 à la demande de l'utilisateur : doublon de
                         "Tous les courriers", qui a désormais le filtre
                         "En cours (tous)". --}}
                        {{-- "Affectations"/"Traitement & Réponse" (maquette) :
                         aucune page dédiée distincte — l'affectation et le
                         traitement se font aujourd'hui depuis la fiche d'un
                         courrier (ShowCourrier), pas depuis une liste
                         séparée. Marqués "Bientôt", affichés seulement à qui
                         a déjà le privilège d'affecter / de traiter. --}}
                        @if ($menu['courriers_affectations'])
                            <x-sidebar-item-a-venir icon="user-plus">{{ __('Affectations') }}</x-sidebar-item-a-venir>
                        @endif
                        @if ($menu['courriers_traitement'])
                            <x-sidebar-item-a-venir
                                icon="chat-bubble-left-right">{{ __('Traitement & Réponse') }}</x-sidebar-item-a-venir>
                        @endif
                    </flux:sidebar.group>
                @endif

                {{-- Module 3/9 — "Dossiers & Archives" construits le
                     2026-09-21 (voir DECISIONS.md) : un seul lien réel vers
                     la page, l'arborescence et les nœuds virtuels
                     (Courriers généraux/Archives/Dossier surveillé) sont
                     gérés DANS la page elle-même. "Créer un dossier" n'est
                     plus une page à part : raccourci vers la racine avec la
                     modale de création pré-ouverte (?creer=1, lu par
                     DossierClassementList::mount()). --}}
                {{-- "Créer un dossier" et "Archives" s'ouvrent dans la page
                     Dossiers : ils exigent donc aussi l'accès à la page. --}}
                @if ($menu['dossiers_tous'])
                    <flux:sidebar.group :heading="__('Dossiers & Archives')" expandable
                        :expanded="request()->routeIs('dossiers-classement.index')" icon="folder" class="grid">
                        <flux:sidebar.item icon="folder-open" :href="route('dossiers-classement.index')"
                            :current="request()->routeIs('dossiers-classement.index') && request()->query('noeud') === null && request()->query('creer') === null"
                            wire:navigate>
                            {{ __('Tous les dossiers') }}
                        </flux:sidebar.item>
                        @if ($menu['dossiers_creer'])
                            <flux:sidebar.item icon="folder-plus"
                                :href="route('dossiers-classement.index', ['creer' => 1])" :current="false"
                                wire:navigate>
                                {{ __('Créer un dossier') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($menu['dossiers_archives'])
                            <flux:sidebar.item icon="archive-box"
                                :href="route('dossiers-classement.index', ['noeud' => 'archives'])"
                                :current="request()->routeIs('dossiers-classement.index') && request()->query('noeud') === 'archives'"
                                wire:navigate>
                                {{ __('Archives') }}
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif

                {{-- "Recherche avancée" (entrée de sidebar autonome) retirée
                     le 2026-10-08 : pointait vers EXACTEMENT la même route
                     (courriers.rechercher) que "Tous les courriers"
                     ci-dessus — doublon pur, jamais une page/vue distincte,
                     même raisonnement que le retrait de "Transferts". Les
                     filtres "avancés" (expéditeur, contenu OCR, type,
                     confidentialité, service, dates, statut, sens) vivent
                     DANS la page "Tous les courriers" elle-même, repliés
                     derrière leur propre bouton. --}}

                {{-- Groupe affiché dès qu'UNE entrée l'est — auparavant la
                     condition oubliait "Organisation" (un utilisateur avec
                     seulement organisation.view ne voyait pas le groupe). --}}
                @if ($groupe('admin_'))
                    {{-- "Privilèges" retiré du menu le 2026-09-21 : la page
                         "Utilisateurs & Accès" reconstruite depuis la
                         maquette fournie par l'utilisateur absorbe
                         l'assignation par utilisateur ; le catalogue de
                         privilèges lui-même est désormais fixe (plus de
                         création/suppression de définition), voir
                         le journal de développement. --}}
                    <flux:sidebar.group :heading="__('Configuration')" expandable
                        :expanded="request()->routeIs(['admin.utilisateurs', 'admin.profils', 'admin.regles', 'admin.organisation', 'admin.parametres', 'admin.dossier-surveille', 'admin.securite-audit'])"
                        icon="cog-6-tooth" class="grid">
                        {{-- utilisateurs.gerer / privileges.gerer séparés le 2026-09-23. --}}
                        @if ($menu['admin_utilisateurs'])
                            <flux:sidebar.item icon="users" :href="route('admin.utilisateurs')"
                                :current="request()->routeIs('admin.utilisateurs')" wire:navigate>
                                {{ __('Utilisateurs & Accès') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($menu['admin_profils'])
                            <flux:sidebar.item icon="identification" :href="route('admin.profils')"
                                :current="request()->routeIs('admin.profils')" wire:navigate>
                                {{ __('Profils') }}
                            </flux:sidebar.item>
                        @endif
                        {{-- "Organisation" v2 (2026-09-22, spec technique
                             complète fournie par l'utilisateur) — remplace la
                             page plate "Services" : vraie hiérarchie
                             dynamique Company/Site/Department/Service/
                             Sub-service (App\Models\OrganizationUnit). --}}
                        @if ($menu['admin_organisation'])
                            <flux:sidebar.item icon="building-office-2" :href="route('admin.organisation')"
                                :current="request()->routeIs('admin.organisation')" wire:navigate>
                                {{ __('Organisation') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($menu['admin_regles'])
                            <flux:sidebar.item icon="adjustments-horizontal" :href="route('admin.regles')"
                                :current="request()->routeIs('admin.regles')" wire:navigate>
                                {{ __('Référentiels') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($menu['admin_automatisation'])
                            <x-sidebar-item-a-venir icon="bolt">{{ __('Automatisation') }}</x-sidebar-item-a-venir>
                        @endif
                        @if ($menu['admin_workflows'])
                            <x-sidebar-item-a-venir
                                icon="arrow-path-rounded-square">{{ __('Workflows') }}</x-sidebar-item-a-venir>
                        @endif
                        {{-- "SLA & Alertes" → "Paramètres système" (2026-09-23,
                             voir DECISIONS.md "Paramètres système
                             configurables") : plus un placeholder — page
                             réelle (numéro de référence, délais SLA), même
                             privilège administration.sla qu'avant, mêmes
                             profils par défaut. --}}
                        @if ($menu['admin_sla'])
                            <flux:sidebar.item icon="adjustments-horizontal" :href="route('admin.parametres')"
                                :current="request()->routeIs('admin.parametres')" wire:navigate>
                                {{ __('Paramètres système') }}
                            </flux:sidebar.item>
                        @endif
                        {{-- Configuration du dossier surveillé (2026-09-24, demande
                             explicite de l'utilisateur) — déplacée depuis la page
                             Numérisation, qui ne fait plus qu'importer. --}}
                        @if ($menu['admin_dossier_surveille'])
                            <flux:sidebar.item icon="folder-open" :href="route('admin.dossier-surveille')"
                                :current="request()->routeIs('admin.dossier-surveille')" wire:navigate>
                                {{ __('Dossier surveillé') }}
                            </flux:sidebar.item>
                        @endif
                        {{-- 2026-10-30 — page réelle (conformité de l'archivage,
                             Module 9), plus « Bientôt ». --}}
                        @if ($menu['admin_audit'])
                            <flux:sidebar.item icon="shield-check" :href="route('admin.securite-audit')"
                                :current="request()->routeIs('admin.securite-audit')" wire:navigate>
                                {{ __('Sécurité & Audit') }}
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif

                {{-- "Statistiques & Rapports" (maquette) : distinct du
                     tableau de bord (Module 10, déjà construit, voir
                     "Général" ci-dessus) — une page de RAPPORTS séparée,
                     avec ses propres filtres/export, n'existe pas encore
                     (explicitement hors périmètre phase 1, voir PRD.md
                     "Tableaux de bord avec filtres avancés/export
                     personnalisé"). 2026-10-30 — « Dashboard statistiques »
                     construit (indicateurs essentiels, filtres simples, sans
                     export) ; « Rapports » reste à venir. --}}
                @if ($groupe('stats_'))
                    <flux:sidebar.group :heading="__('Statistiques & Rapports')" icon="chart-bar" class="grid">
                        {{-- 2026-10-30 — page réelle (Statistiques), plus « Bientôt ». --}}
                        @if ($menu['stats_dashboard'])
                            <flux:sidebar.item icon="chart-bar" :href="route('statistiques.index')"
                                :current="request()->routeIs('statistiques.index')" wire:navigate>
                                {{ __('Statistiques') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($menu['stats_rapports'])
                            <x-sidebar-item-a-venir
                                icon="document-chart-bar">{{ __('Rapports') }}</x-sidebar-item-a-venir>
                        @endif
                    </flux:sidebar.group>
                @endif

                {{-- "Notifications" (nouvelle spécification 2026-09-18) : groupe
                     de premier niveau à part entière plutôt que seulement la
                     cloche de la navbar existante (voir plus bas). Centre de
                     notifications réel depuis le 2026-10-05 (Module 7) — la
                     cloche de l'en-tête donne un aperçu rapide, cette page
                     liste tout l'historique avec pagination. --}}
                @if ($menu['notifications'])
                    <flux:sidebar.group :heading="__('Notifications')" icon="bell" class="grid">
                        <flux:sidebar.item icon="bell" :href="route('notifications.index')"
                            :current="request()->routeIs('notifications.index')" wire:navigate
                            :badge="(string) $compteurNotificationsNonLues" badge-color="red"
                            badge:id="sidebar-notifications-badge"
                            badge:class="badge-notif-pop {{ $compteurNotificationsNonLues > 0 ? '' : 'hidden' }}">
                            {{ __('Notifications') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
                <flux:sidebar.group>
                    <flux:sidebar.item icon="cog" :href="route('profile.edit')"
                        :current="request()->routeIs('profile.edit')" wire:navigate>
                        {{ __('Paramètres') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

            </ui-disclosure-group>

            {{-- "Aide" (nouvelle spécification 2026-09-18) : groupe de
                     premier niveau distinct de "Paramètres", décoratif dans
                     la navbar desktop existante (voir plus bas), aucune page
                     d'aide/documentation ne lui est encore rattachée. --}}
            {{-- 2026-11-11 (« hide the pages that are grisées on the sidebar ») :
                 groupe retiré tant qu'aucune page d'aide n'existe (il ne
                 contenait que l'entrée grisée « Aide / Documentation » et
                 restait comme un titre vide). La clé $menu['aide'] (privilège
                 general.aide) est gardée pour le jour où la page existe. --}}

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:sidebar.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full"
                    data-test="logout-button-sidebar">
                    {{ __('Déconnexion') }}
                </flux:sidebar.item>
            </form>
        </flux:sidebar.nav>

        <flux:spacer />

        {{-- Slogan de marque (habillage NSIA, voir x-sidebar-artwork) — dans
             le flux APRÈS le spacer : en bas de la sidebar quand la nav est
             courte, repoussé sous "Déconnexion" (jamais par-dessus) quand un
             groupe déplié l'allonge. --}}
        <div aria-hidden="true" class="pointer-events-none px-1 pb-1">
            <p class="text-xs leading-snug font-light italic" style="color: var(--color-brand-blue)">
                Protéger aujourd'hui,<br>bâtir demain.
            </p>
            <div class="mt-2 h-px w-10" style="background-color: var(--color-brand-blue); opacity: 0.6"></div>
        </div>
    </flux:sidebar>
    @endpersist

    {{-- Resynchronise le lien actif de la sidebar après chaque
             wire:navigate — nécessaire UNIQUEMENT parce que la sidebar est
             désormais @persist (voir plus haut) : le nœud DOM n'étant
             jamais remplacé, son attribut data-current (déjà celui dont
             dépendent tous les styles "actif" de Flux — voir
             vendor/livewire/flux/stubs/.../sidebar/item.blade.php) resterait
             sinon figé sur la page où la sidebar a été rendue pour la toute
             première fois. Comparaison sur pathname+search (pas seulement
             pathname) : "Tous les courriers" et "Recherche avancée"
             pointent vers la même route sans paramètre, "Tous les
             courriers" seul doit rester actif une fois un filtre `sens`
             ajouté dans l'URL. --}}
    <script>
        function synchroniserLienSidebarActif() {
            document.querySelectorAll('[data-flux-sidebar-item]').forEach((lien) => {
                const href = lien.getAttribute('href');

                if (!href) {
                    return;
                }

                const cible = new URL(href, window.location.origin);
                const actif = cible.pathname === window.location.pathname &&
                    cible.search === window.location.search;

                lien.toggleAttribute('data-current', actif);
            });
        }

        document.addEventListener('livewire:navigated', synchroniserLienSidebarActif);

        // Même raison que ci-dessus (sidebar persistante, jamais recalculée
        // côté serveur après le 1er chargement) — resynchronise le badge
        // du menu notifs de la sidebar à partir du compteur toujours
        // frais de la cloche de l'en-tête (resources/views/livewire/
        // frontend/notification-bell.blade.php, HORS du bloc persistant).
        // ATTENTION (2026-10-05, voir notes internes) — tout
        // CE SCRIPT part tel quel dans le HTML envoyé au navigateur, MÊME
        // pour un compte sans le privilège associé à ce menu : jamais y
        // écrire le nom exact (capitalisé) de ce menu en clair, sous peine
        // de casser les tests "un tel privilège absent => ce libellé
        // absent de la page" qui cherchent ce nom littéralement.
        function synchroniserBadgeNotifsSidebar() {
            const cloche = document.querySelector('[data-notifications-non-lues]');
            const badge = document.getElementById('sidebar-notifications-badge');

            if (!cloche || !badge) {
                return;
            }

            const nombre = parseInt(cloche.dataset.notificationsNonLues, 10) || 0;

            badge.textContent = nombre > 9 ? '9+' : String(nombre);
            badge.classList.toggle('hidden', nombre === 0);
        }

        document.addEventListener('livewire:navigated', synchroniserBadgeNotifsSidebar);
        // Dispatché par les composants de notifs (voir app/Livewire/Backend/)
        // juste après un "marquer comme lu" — la cloche de l'en-tête s'est
        // déjà rafraîchie à ce moment-là (listener Livewire côté serveur),
        // il ne reste qu'à relire sa nouvelle valeur une fois le DOM mis à
        // jour (microtask, d'où le setTimeout à délai nul plutôt qu'un
        // appel synchrone trop tôt).
        window.addEventListener('notifications-mises-a-jour', () => setTimeout(synchroniserBadgeNotifsSidebar));
    </script>

    <!-- Mobile User Menu -->
    <flux:header sticky class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile :initials="auth()->user()->initials()" :avatar="auth()->user()->photoUrl()" icon-trailing="chevron-down" />

            <flux:menu>
                @include('partials.user-menu-items')
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{-- Barre de navigation desktop — alignée sur la maquette globale
             fournie par l'utilisateur (2026-09-18, section 7 "Global
             header") : recherche rapide + raccourci Ctrl/Cmd+K RÉEL (pas
             seulement une indication visuelle décorative), date/heure du
             jour (donnée réelle, calculée côté serveur au dernier
             chargement de page — pas une horloge JS qui avance en direct,
             non demandé), notifications (toujours vide — Module 7
             n'existe pas encore, voir DECISIONS.md, jamais de compteur
             inventé), aide (décorative, aucune page derrière à ce jour),
             profil avec le nom ET le profil/rôle affiché dessous (donnée
             réelle, auth()->user()->profil->nom) — <flux:profile> ne
             supporte pas nativement un sous-texte, reconstruit à la main
             ici avec le même déclencheur de dropdown. --}}
    <flux:header sticky
        class="hidden items-center gap-3 border-b border-zinc-200 bg-white px-6 py-3 lg:flex dark:border-zinc-700 dark:bg-zinc-900">
        @can('rechercher', App\Models\Courrier::class)
            <form action="{{ route('courriers.rechercher') }}" method="GET" class="max-w-md flex-1"
                x-data="{}" x-on:keydown.window.cmd.k.prevent="$refs.champRecherche.focus()"
                x-on:keydown.window.ctrl.k.prevent="$refs.champRecherche.focus()">
                <flux:input x-ref="champRecherche" type="search" name="q" icon="magnifying-glass" kbd="Ctrl+K"
                    :placeholder="__('Rechercher un courrier, une référence, un expéditeur…')" />
            </form>
        @endcan

        <flux:spacer />

        <div class="hidden items-center gap-2 text-zinc-500 xl:flex dark:text-zinc-400">
            <flux:icon.calendar class="size-4" />
            <flux:text class="text-sm">{{ now()->translatedFormat('l j F Y') }} · {{ now()->format('H:i') }}
            </flux:text>
        </div>

        {{-- Mêmes privilèges que les menus Notifications/Aide de la sidebar (2026-09-23).
                 Centre de notifications réel depuis le 2026-10-05 (Module 7) — ce composant
                 vit volontairement HORS du bloc @persist('app-sidebar') ci-dessus : il est donc
                 remonté à chaque wire:navigate, ce qui rafraîchit le compteur/la liste à chaque
                 changement de page sans scrutation (jamais de wire:poll, Règle n°2). --}}
        @if (auth()->user()->hasPrivilege('general.notifications'))
            <livewire:backend.notification-bell />
        @endif

        @if (auth()->user()->hasPrivilege('general.aide'))
            <flux:button variant="ghost" icon="question-mark-circle" size="sm" square :aria-label="__('Aide')" />
        @endif

        <flux:dropdown position="bottom" align="end">
            <button type="button"
                class="group flex items-center gap-2 rounded-lg p-1 hover:bg-zinc-800/5 dark:hover:bg-white/15">
                <flux:avatar size="sm" circle :initials="auth()->user()->initials()" :src="auth()->user()->photoUrl()" />
                <div class="hidden text-start sm:block">
                    <div class="text-sm font-medium text-zinc-800 dark:text-white">{{ auth()->user()->name }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ auth()->user()->profil?->nom ?? __('Utilisateur') }}</div>
                </div>
                <flux:icon.chevron-down class="size-4 text-zinc-400" />
            </button>

            <flux:menu>
                @include('partials.user-menu-items')
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
</body>

</html>
