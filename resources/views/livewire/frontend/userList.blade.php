<div>
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Administration') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Utilisateurs & Accès') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
                <flux:icon.users class="size-5" />
            </div>
            <div>
                <flux:heading level="1">{{ __('Utilisateurs & Accès') }}</flux:heading>
                <flux:subheading>{{ __('Gérez les utilisateurs, leurs accès et leurs permissions dans le système.') }}</flux:subheading>
            </div>
        </div>

        {{-- Créer un compte = lui donner un profil : privileges.gerer requis (2026-09-23). --}}
        @can('gerer', App\Models\Privilege::class)
            <flux:button variant="primary" icon="plus" wire:click="ouvrirAjout">
                {{ __('Ajouter un utilisateur') }}
            </flux:button>
        @endcan
    </div>

    {{-- Barre de recherche + filtres (maquette "Utilisateurs & Accès",
         2026-09-21) — même carte blanche que courrierList.blade.php pour la
         recherche/filtres. --}}
    <div class="mt-6 rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:input wire:model.live.debounce.400ms="recherche" icon="magnifying-glass" :placeholder="__('Rechercher un utilisateur (nom, email)…')" />

        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- 2026-09-23, demande explicite de l'utilisateur : filtre par
                 Département de l'organigramme (inclut tout le sous-arbre,
                 voir OrganizationUnit::idsServicesReelsSousArbre()), plus
                 par service brut. --}}
            <flux:select wire:model.live="departementFiltreId" :label="__('Département')">
                <flux:select.option value="">{{ ('Tout les  departement') }}</flux:select.option>
                @foreach ($this->departementsFiltrables as $departement)
                    <flux:select.option value="{{ $departement->id }}">{{ $departement->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="profilFiltreId" :label="__('Profil')">
                <flux:select.option value="">{{ __('Tous les profils') }}</flux:select.option>
                @foreach ($this->profils as $profil)
                    <flux:select.option value="{{ $profil->id }}">{{ $profil->nom }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statutFiltre" :label="__('Statut')">
                <flux:select.option value="">{{ __('Tous les statuts') }}</flux:select.option>
                <flux:select.option value="actif">{{ __('Actif') }}</flux:select.option>
                <flux:select.option value="desactive">{{ __('Désactivé') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="confidentialiteFiltreId" :label="__('Confidentialité')">
                <flux:select.option value="">{{ __('Tous les niveaux') }}</flux:select.option>
                @foreach (range(1, \App\Models\User::niveauConfidentialiteMax()) as $niveau)
                    <flux:select.option value="{{ $niveau }}">{{ __('Niveau :n', ['n' => $niveau]) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="mt-4">
            <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="reinitialiserFiltres">{{ __('Réinitialiser') }}</flux:button>
        </div>
    </div>

    {{-- Table — même style de carte que le reste de l'app (Règle "un seul
         style de carte partout", voir notes internes). --}}
    {{-- Format de tableau commun (2026-10-29, « same format on all the tables
         of the project ») — celui de « Tous les courriers » (voir le
         commentaire de courrierList.blade.php) : en-têtes en petites capitales
         soulignés navy, lignes sur UNE seule ligne (avatar réduit, poste à côté
         du nom, texte trop long coupé et lisible en entier au survol), 16 px
         en haut et en bas de chaque cellule, bouton d'actions centré. --}}
    <div class="@container mt-6 overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead class="border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400">
                <tr>
                    <th class="cursor-pointer select-none whitespace-nowrap py-3 pl-4 pr-3 @min-[60rem]:pr-5" wire:click="trierPar('name')">
                        {{ __('Nom') }}
                        @if ($colonneTri === 'name')
                            <flux:icon :icon="$sensTri === 'asc' ? 'chevron-up' : 'chevron-down'" class="inline size-3.5" />
                        @endif
                    </th>
                    <th class="cursor-pointer select-none whitespace-nowrap py-3 pr-3 @min-[60rem]:pr-5" wire:click="trierPar('email')">
                        {{ __('Email') }}
                        @if ($colonneTri === 'email')
                            <flux:icon :icon="$sensTri === 'asc' ? 'chevron-up' : 'chevron-down'" class="inline size-3.5" />
                        @endif
                    </th>
                    <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Département') }}</th>
                    <th class="w-px py-3 pr-3 @min-[60rem]:pr-5">{{ __('Profil') }}</th>
                    <th class="w-px py-3 pr-3 @min-[60rem]:pr-5">{{ __('Confidentialité') }}</th>
                    <th class="w-px py-3 pr-3 @min-[60rem]:pr-5">{{ __('Statut') }}</th>
                    <th class="w-px cursor-pointer select-none whitespace-nowrap py-3 pr-3 @min-[60rem]:pr-5" wire:click="trierPar('derniere_connexion_le')">
                        {{ __('Dernière connexion') }}
                        @if ($colonneTri === 'derniere_connexion_le')
                            <flux:icon :icon="$sensTri === 'asc' ? 'chevron-up' : 'chevron-down'" class="inline size-3.5" />
                        @endif
                    </th>
                    <th class="relative w-px py-3 pr-4"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->utilisateurs as $utilisateur)
                    @php
                        $nomLigne = $utilisateur->poste ? $utilisateur->name.' — '.$utilisateur->poste : $utilisateur->name;
                        $departementLigne = $this->departementLabelDe($utilisateur) ?? '—';
                    @endphp
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                        <td class="w-[36%] min-w-36 py-4 pl-4 pr-3 @min-[60rem]:pr-5">
                            <div class="flex w-0 min-w-full items-center gap-2">
                                <flux:avatar size="xs" circle :initials="$utilisateur->initials()" :src="$utilisateur->photoUrl()" />
                                <div class="min-w-0 truncate" title="{{ $nomLigne }}">
                                    <span class="font-medium">{{ $utilisateur->name }}</span>
                                    @if ($utilisateur->poste)
                                        <span class="text-zinc-500">· {{ $utilisateur->poste }}</span>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="w-[34%] min-w-28 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">
                            <div class="w-0 min-w-full truncate" title="{{ $utilisateur->email }}">{{ $utilisateur->email }}</div>
                        </td>
                        {{-- 2026-09-23, demande explicite de l'utilisateur :
                             le Département de l'organigramme — via un
                             rattachement de travail direct (organigramme,
                             onglet "Utilisateurs") en priorité, sinon via le
                             pont users.service_id (voir
                             UserList::departementLabelDe()) — "—" si ni
                             l'un ni l'autre n'existe (honnête, jamais une
                             valeur fabriquée). --}}
                        <td class="w-[30%] min-w-24 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">
                            <div class="w-0 min-w-full truncate" title="{{ $departementLigne }}">{{ $departementLigne }}</div>
                        </td>
                        <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5">
                            @if ($utilisateur->profil)
                                <span class="inline-flex items-center rounded-md bg-brand-accent-pale px-2 py-0.5 text-xs font-semibold text-brand-accent">{{ $utilisateur->profil->nom }}</span>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5">
                            {{-- Niveau NUMÉRIQUE nu (2026-09-21, "numbers ... not
                                 confidential or whatever") — dégradé de couleur
                                 par plage plutôt qu'un cas par valeur exacte,
                                 pour rester correct sur les 5 niveaux. --}}
                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold {{ match (true) {
                                $utilisateur->niveau_confidentialite >= 4 => 'bg-brand-danger/10 text-brand-danger',
                                $utilisateur->niveau_confidentialite >= 2 => 'bg-brand-warning/10 text-brand-warning',
                                default => 'bg-zinc-100 text-zinc-600',
                            } }}">{{ __('Niveau :n', ['n' => $utilisateur->niveau_confidentialite]) }}</span>
                        </td>
                        <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5">
                            <span class="inline-flex items-center gap-1.5 text-xs font-medium {{ $utilisateur->actif ? 'text-brand-success' : 'text-zinc-500' }}">
                                <span class="size-2 rounded-full {{ $utilisateur->actif ? 'bg-brand-success' : 'bg-zinc-400' }}"></span>
                                {{ $utilisateur->actif ? __('Actif') : __('Désactivé') }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">
                            {{ $utilisateur->derniere_connexion_le?->format('d/m/Y H:i') ?? __('Jamais connecté') }}
                        </td>
                        <td class="py-4 pr-4 text-center">
                            {{-- Compte protégé (administrateur) sans privileges.gerer : aucune action (2026-09-23). --}}
                            @if ($this->peutGererCompte($utilisateur))
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" square aria-label="{{ __('Actions') }}" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="ouvrirEdition({{ $utilisateur->id }})">{{ __('Modifier') }}</flux:menu.item>
                                        @can('gerer', App\Models\Privilege::class)
                                            <flux:menu.item icon="shield-check" wire:click="ouvrirPermissions({{ $utilisateur->id }})">{{ __('Gérer les permissions') }}</flux:menu.item>
                                        @endcan
                                        <flux:menu.item icon="{{ $utilisateur->actif ? 'no-symbol' : 'check-circle' }}" wire:click="basculerActif({{ $utilisateur->id }})">
                                            {{ $utilisateur->actif ? __('Désactiver') : __('Activer') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="key" wire:click="reinitialiserMotDePasse({{ $utilisateur->id }})">{{ __('Réinitialiser le mot de passe') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="p-6 text-center text-zinc-500">{{ __('Aucun utilisateur ne correspond à ces critères.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->utilisateurs->links() }}
    </div>

    {{-- ===================== Modale "Ajouter un utilisateur" ===================== --}}
    <flux:modal name="user-ajout" class="w-full max-w-3xl">
        <form wire:submit="ajouter" class="space-y-6">
            <div class="flex items-center gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-accent-pale text-brand-accent">
                    <flux:icon.user-plus class="size-5" />
                </div>
                <div>
                    <flux:heading level="2">{{ __('Ajouter un utilisateur') }}</flux:heading>
                    <flux:subheading>{{ __('Créez un nouvel utilisateur et définissez ses accès au système.') }}</flux:subheading>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div class="space-y-4">
                    <flux:heading level="3">{{ __('Informations personnelles') }}</flux:heading>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model.live.debounce.300ms="ajoutNom" :label="__('Nom')" required />
                        <flux:input wire:model.live.debounce.300ms="ajoutPrenom" :label="__('Prénom')" required />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input type="email" wire:model.live.debounce.300ms="ajoutEmail" :label="__('Email')" required />
                        <flux:input wire:model="ajoutTelephone" :label="__('Téléphone')" />
                    </div>
                    <flux:input wire:model="ajoutPoste" :label="__('Poste')" :placeholder="__('Ex. Responsable service courrier')" />

                    <flux:heading level="3">{{ __('Département et profil') }}</flux:heading>
                    {{-- 2026-09-23, demande explicite de l'utilisateur ("we
                         assign them in a department first before service") —
                         remplace le sélecteur plat unique par la cascade
                         Site → Département → Service/Unité (Module
                         "Organisation" v2, même patron que
                         RegistrationForm/ShowCourrier). 2026-10-26 — une
                         entité est OBLIGATOIRE (« chaque user doit être dans
                         une direction, département, service ») : le niveau le
                         plus fin choisi est retenu, jusqu'à la Direction
                         Générale (Site) ; chaque sélecteur garde une option
                         "Aucun" réelle et cliquable, pas seulement un
                         placeholder (piège flux:select "freeze" déjà
                         rencontré). --}}
                    <div class="grid gap-4 sm:grid-cols-4">
                        <flux:select wire:model.live="ajoutSiteSelectionneId" :label="__('Site')">
                            <flux:select.option value="">{{ __('— Aucun (racine) —') }}</flux:select.option>
                            @foreach ($this->sitesDisponibles as $site)
                                <flux:select.option value="{{ $site->id }}">{{ $site->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        {{-- 2026-10-09 — niveau Direction, OPTIONNEL comme le reste. --}}
                        @if ($this->ajoutDirectionsDisponibles->isNotEmpty())
                            <flux:select wire:model.live="ajoutDirectionSelectionneeId" :label="__('Direction')">
                                <flux:select.option value="">{{ __('— Aucune (direct) —') }}</flux:select.option>
                                @foreach ($this->ajoutDirectionsDisponibles as $direction)
                                    <flux:select.option value="{{ $direction->id }}">{{ $direction->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                        @if ($this->ajoutDepartementsDisponibles->isNotEmpty())
                            <flux:select wire:model.live="ajoutDepartementSelectionneId" :label="__('Département / Service')">
                                <flux:select.option value="">{{ __('— Aucun (rattaché au niveau au-dessus) —') }}</flux:select.option>
                                @foreach ($this->ajoutDepartementsDisponibles as $departement)
                                    <flux:select.option value="{{ $departement->id }}">{{ $departement->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                        @if ($ajoutDepartementSelectionneId && $this->ajoutUnitesDisponibles->isNotEmpty())
                            <flux:select wire:model.live="ajoutUniteSelectionneeId" :label="__('Service / Unité')">
                                <flux:select.option value="">{{ __('— Aucun —') }}</flux:select.option>
                                @foreach ($this->ajoutUnitesDisponibles as $unite)
                                    <flux:select.option value="{{ $unite->id }}">{{ $unite->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                    </div>
                    <flux:error name="ajoutEntite" />
                    <flux:select wire:model.live="ajoutProfilId" :label="__('Profil')" required>
                        <flux:select.option value="">{{ __('Sélectionner un profil') }}</flux:select.option>
                        @foreach ($this->profils as $profil)
                            <flux:select.option value="{{ $profil->id }}">{{ $profil->nom }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="ajoutNiveauConfidentialite" :label="__('Niveau de confidentialité')" :description="__('Définit le niveau d\'accès aux informations confidentielles.')">
                        @foreach (range(1, \App\Models\User::niveauConfidentialiteMax()) as $niveau)
                            <flux:select.option value="{{ $niveau }}">{{ __('Niveau :n', ['n' => $niveau]) }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:switch wire:model.live="ajoutActif" :label="__('Activer le compte')" :description="__('L\'utilisateur pourra se connecter dès la création.')" />
                </div>

                <div class="space-y-4">
                    <div>
                        <flux:text class="text-xs uppercase text-zinc-500">{{ __('Photo') }}</flux:text>
                        <div class="mt-2 flex items-center gap-3">
                            <flux:avatar size="lg" circle :initials="$ajoutNom !== '' ? \Illuminate\Support\Str::substr($ajoutNom, 0, 2) : '?'" />
                            <div>
                                <input type="file" wire:model="ajoutPhoto" accept=".jpg,.jpeg,.png" class="hidden" id="ajout-photo" />
                                <label for="ajout-photo" class="cursor-pointer text-sm font-medium text-brand-accent hover:underline">{{ __('Choisir une photo') }}</label>
                                <div class="text-xs text-zinc-500">{{ __('Format recommandé : JPG, PNG') }}<br>{{ __('Taille max : 2 Mo') }}</div>
                                <div wire:loading wire:target="ajoutPhoto" class="text-xs text-zinc-500">{{ __('Envoi en cours…') }}</div>
                                <flux:error name="ajoutPhoto" />
                            </div>
                        </div>
                    </div>

                    {{-- "Récapitulatif" — reflète les champs en cours de
                         saisie en direct (contrairement à "Aperçu du
                         compte" de la modale Modifier, qui montre l'état
                         déjà ENREGISTRÉ), même principe que la maquette. --}}
                    <div class="rounded-xl border border-brand-border p-4 dark:border-zinc-700">
                        <flux:heading level="4" class="mb-3">{{ __('Récapitulatif') }}</flux:heading>
                        <dl class="space-y-2.5 text-sm">
                            <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Nom') }}</dt><dd class="truncate font-medium">{{ trim("{$ajoutNom} {$ajoutPrenom}") ?: '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Email') }}</dt><dd class="truncate font-medium">{{ $ajoutEmail ?: '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Département') }}</dt><dd class="truncate font-medium">{{ $ajoutUniteSelectionneeId ? $this->ajoutUnitesDisponibles->firstWhere('id', $ajoutUniteSelectionneeId)?->name : ($ajoutDepartementSelectionneId ? $this->ajoutDepartementsDisponibles->firstWhere('id', $ajoutDepartementSelectionneId)?->name : '—') }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Profil') }}</dt><dd class="truncate font-medium">{{ $ajoutProfilId ? $this->profils->firstWhere('id', $ajoutProfilId)?->nom : '—' }}</dd></div>
                            <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Niveau de confidentialité') }}</dt><dd class="font-medium">{{ __('Niveau :n', ['n' => $ajoutNiveauConfidentialite]) }}</dd></div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-zinc-500">{{ __('Statut') }}</dt>
                                <dd>
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium {{ $ajoutActif ? 'text-brand-success' : 'text-zinc-500' }}">
                                        <span class="size-2 rounded-full {{ $ajoutActif ? 'bg-brand-success' : 'bg-zinc-400' }}"></span>
                                        {{ $ajoutActif ? __('Actif') : __('Désactivé') }}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <flux:callout variant="info" icon="information-circle">
                        {{ __('L\'utilisateur recevra un email pour définir son mot de passe après la création de son compte.') }}
                    </flux:callout>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="user-plus">{{ __('Créer l\'utilisateur') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ===================== Modale "Modifier l'utilisateur" ===================== --}}
    {{-- Restructurée le 2026-09-21 pour vraiment ressembler à la maquette
         ("there are not the same design ... like see this user modal")
         après une première passe à onglets soulignés en haut : la maquette
         utilise une liste d'onglets VERTICALE à gauche + un panneau
         "Aperçu du compte" PERSISTANT à droite (visible sur tous les
         onglets, pas seulement "Informations générales"), pas des onglets
         horizontaux sans panneau de contexte. --}}
    <flux:modal name="user-edition" class="w-full max-w-5xl">
        @if ($this->utilisateurEnEdition)
            @php $utilisateur = $this->utilisateurEnEdition; @endphp
            <div x-data="{ onglet: 'general' }" class="space-y-6">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-accent-pale text-brand-accent">
                        <flux:icon.user class="size-5" />
                    </div>
                    <div>
                        <flux:heading level="2">{{ __('Modifier l\'utilisateur') }}</flux:heading>
                        <flux:subheading>{{ __('Mettez à jour les informations et les paramètres d\'accès de l\'utilisateur.') }}</flux:subheading>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[180px_minmax(0,1fr)_240px]">
                    {{-- Liste d'onglets verticale --}}
                    {{-- Onglets "Rôles & Permissions" et "Périmètre d'accès" +
                         champs Profil/Niveau : privileges.gerer requis EN PLUS
                         de utilisateurs.gerer (2026-09-23, vérifié aussi côté
                         serveur dans UserList). --}}
                    @php $peutGererAcces = auth()->user()->can('gerer', App\Models\Privilege::class); @endphp
                    <nav class="space-y-1">
                        @foreach (array_filter([
                            ['cle' => 'general', 'label' => __('Informations générales'), 'icon' => 'identification'],
                            ['cle' => 'service', 'label' => __('Service & Profil'), 'icon' => 'building-office-2'],
                            $peutGererAcces ? ['cle' => 'permissions', 'label' => __('Rôles & Permissions'), 'icon' => 'shield-check'] : null,
                            ['cle' => 'confidentialite', 'label' => __('Niveau de confidentialité'), 'icon' => 'lock-closed'],
                            ['cle' => 'compte', 'label' => __('Paramètres de compte'), 'icon' => 'cog-6-tooth'],
                            ['cle' => 'destinataires', 'label' => __('Destinataires de transfert'), 'icon' => 'paper-airplane'],
                            $peutGererAcces ? ['cle' => 'perimetre', 'label' => __('Périmètre d\'accès'), 'icon' => 'map'] : null,
                        ]) as $tab)
                            <button type="button" x-on:click="onglet = '{{ $tab['cle'] }}'" :class="onglet === '{{ $tab['cle'] }}' ? 'bg-brand-accent-pale text-brand-accent' : 'text-brand-text-secondary hover:bg-zinc-50 dark:hover:bg-zinc-800/60'" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors">
                                <flux:icon :icon="$tab['icon']" class="size-4 shrink-0" />
                                <span class="truncate">{{ $tab['label'] }}</span>
                            </button>
                        @endforeach
                    </nav>

                    {{-- Contenu de l'onglet actif --}}
                    <div>
                        {{-- "Informations générales"/"Service & Profil"/
                             "Niveau de confidentialité" fusionnés en UN seul
                             panneau continu (2026-09-21, "what about service
                             et profil under profile" — dans la maquette ces
                             3 sections vivent ensemble sous "Informations
                             générales", pas derrière des onglets séparés
                             qui se masquent l'un l'autre). Les 3 entrées de
                             la liste d'onglets restent visibles telles
                             quelles (fidèle à la maquette) mais pointent
                             toutes vers ce même panneau. --}}
                        <div x-show="['general', 'service', 'confidentialite'].includes(onglet)" x-cloak class="space-y-6">
                            <div class="space-y-4">
                                <flux:heading level="3">{{ __('Informations générales') }}</flux:heading>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <flux:input wire:model="editionNom" :label="__('Nom')" required />
                                    <flux:input wire:model="editionPrenom" :label="__('Prénom')" required />
                                </div>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <flux:input type="email" wire:model="editionEmail" :label="__('Email')" required />
                                    <flux:input wire:model="editionTelephone" :label="__('Téléphone')" />
                                </div>
                                <flux:input wire:model="editionPoste" :label="__('Poste')" />
                            </div>

                            <div class="space-y-2">
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Photo de profil') }}</flux:text>
                                <div class="flex items-center gap-3">
                                    <flux:avatar size="lg" circle :initials="$utilisateur->initials()" :src="$utilisateur->photoUrl()" />
                                    <div>
                                        <input type="file" wire:model="editionPhoto" accept=".jpg,.jpeg,.png" class="hidden" id="edition-photo" />
                                        <label for="edition-photo" class="cursor-pointer text-sm font-medium text-brand-accent hover:underline">{{ __('Changer la photo') }}</label>
                                        <div class="text-xs text-zinc-500">{{ __('Format recommandé : JPG, PNG') }}<br>{{ __('Taille max : 2 Mo') }}</div>
                                        <flux:error name="editionPhoto" />
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <flux:heading level="3">{{ __('Département et profil') }}</flux:heading>
                                {{-- 2026-09-23, même cascade que la modale
                                     "Ajouter" ci-dessus — entité obligatoire
                                     depuis le 2026-10-26, même raison. --}}
                                <div class="grid gap-4 sm:grid-cols-4">
                                    <flux:select wire:model.live="editionSiteSelectionneId" :label="__('Site')" placeholder="{{ __('— Aucun (racine) —') }}">
                                        <flux:select.option value="">{{ __('— Aucun (racine) —') }}</flux:select.option>
                                        @foreach ($this->sitesDisponibles as $site)
                                            <flux:select.option value="{{ $site->id }}">{{ $site->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    {{-- 2026-10-09 — niveau Direction, OPTIONNEL comme le reste. --}}
                                    @if ($this->editionDirectionsDisponibles->isNotEmpty())
                                        <flux:select wire:model.live="editionDirectionSelectionneeId" :label="__('Direction')" placeholder="{{ __('— Aucune (direct) —') }}">
                                            <flux:select.option value="">{{ __('— Aucune (direct) —') }}</flux:select.option>
                                            @foreach ($this->editionDirectionsDisponibles as $direction)
                                                <flux:select.option value="{{ $direction->id }}">{{ $direction->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @endif
                                    @if ($this->editionDepartementsDisponibles->isNotEmpty())
                                        <flux:select wire:model.live="editionDepartementSelectionneId" :label="__('Département / Service')" placeholder="{{ __('— Aucun (rattaché au niveau au-dessus) —') }}">
                                            <flux:select.option value="">{{ __('— Aucun (rattaché au niveau au-dessus) —') }}</flux:select.option>
                                            @foreach ($this->editionDepartementsDisponibles as $departement)
                                                <flux:select.option value="{{ $departement->id }}">{{ $departement->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @endif
                                    @if ($editionDepartementSelectionneId && $this->editionUnitesDisponibles->isNotEmpty())
                                        <flux:select wire:model="editionUniteSelectionneeId" :label="__('Service / Unité')" placeholder="{{ __('— Aucun —') }}">
                                            <flux:select.option value="">{{ __('— Aucun —') }}</flux:select.option>
                                            @foreach ($this->editionUnitesDisponibles as $unite)
                                                <flux:select.option value="{{ $unite->id }}">{{ $unite->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @endif
                                </div>
                                <flux:error name="editionEntite" />
                                {{-- 2026-09-24 — service réel actuellement enregistré
                                     (utilisé pour l'affectation des courriers), affiché
                                     tel quel : un service pas encore représenté dans
                                     l'organigramme n'apparaît dans aucun sélecteur
                                     ci-dessus et paraissait sinon absent. --}}
                                @if ($serviceActuel = $this->utilisateurEnEdition?->service)
                                    <flux:text class="text-sm">
                                        {{ __('Service actuel : :nom (:code)', ['nom' => $serviceActuel->nom, 'code' => $serviceActuel->code]) }}
                                        @unless (isset($this->departementLabelParServiceId[$serviceActuel->id]))
                                            — {{ __('hors organigramme, conservé tant que vous ne choisissez pas de département.') }}
                                        @endunless
                                    </flux:text>
                                @endif
                                <flux:select wire:model="editionProfilId" :label="__('Profil')" required :disabled="! $peutGererAcces">
                                    @foreach ($this->profils as $profil)
                                        <flux:select.option value="{{ $profil->id }}">{{ $profil->nom }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>

                            <div class="space-y-2">
                                <flux:select wire:model="editionNiveauConfidentialite" :label="__('Niveau de confidentialité')" :description="__('Définit le niveau d\'accès aux informations confidentielles.')" :disabled="! $peutGererAcces">
                                    @foreach (range(1, \App\Models\User::niveauConfidentialiteMax()) as $niveau)
                                        <flux:select.option value="{{ $niveau }}">{{ __('Niveau :n', ['n' => $niveau]) }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        </div>

                        {{-- Rôles & Permissions — pas de two-box ici, un simple
                             résumé + bouton vers la modale dédiée (voir plus
                             bas), cohérent avec la maquette. --}}
                        @if ($peutGererAcces)
                        <div x-show="onglet === 'permissions'" x-cloak class="space-y-4">
                            <flux:text class="text-zinc-500">{{ __('Sélectionnez les modules auxquels l\'utilisateur aura accès.') }}</flux:text>
                            <button type="button" wire:click="ouvrirPermissions({{ $utilisateur->id }})" class="flex w-full items-center justify-between rounded-xl border border-brand-border p-4 text-left hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/60">
                                <div class="flex items-center gap-3">
                                    <flux:icon.shield-check class="size-5 text-brand-accent" />
                                    <div>
                                        <div class="font-medium">{{ __('Configurer les permissions') }}</div>
                                        <div class="text-xs text-zinc-500">{{ __('Choisissez un ou plusieurs modules pour définir les accès de cet utilisateur.') }}</div>
                                    </div>
                                </div>
                                <flux:icon.chevron-right class="size-4 text-zinc-400" />
                            </button>
                        </div>
                        @endif

                        {{-- Paramètres de compte --}}
                        <div x-show="onglet === 'compte'" x-cloak class="space-y-4">
                            <flux:switch wire:model="editionActif" :label="__('Compte activé')" :description="__('Un compte désactivé ne peut plus se connecter.')" />
                            <flux:separator />
                            <div>
                                <flux:text class="font-medium">{{ __('Mot de passe') }}</flux:text>
                                <flux:text class="text-zinc-500">{{ __('Envoie un lien de réinitialisation à l\'adresse email de l\'utilisateur.') }}</flux:text>
                                <flux:button size="sm" class="mt-2" icon="key" wire:click="reinitialiserMotDePasse({{ $utilisateur->id }})">{{ __('Réinitialiser') }}</flux:button>
                            </div>
                        </div>

                        {{-- Destinataires de transfert — deux boîtes,
                             reprises telles quelles depuis l'ancienne page
                             (voir DECISIONS.md "Destinataires de transfert"). --}}
                        <div x-show="onglet === 'destinataires'" x-cloak>
                            <flux:text class="mb-3 text-zinc-500">{{ __('Personnes que cet utilisateur peut choisir dans sa modale "Transférer à".') }}</flux:text>
                            <div class="grid gap-4 sm:grid-cols-[1fr_auto_1fr]">
                                <div class="rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                                    <flux:input size="sm" wire:model.live.debounce.300ms="rechercheDestinatairesDisponibles" icon="magnifying-glass" :placeholder="__('Rechercher…')" />
                                    <flux:checkbox.group wire:model.live="selectionDestinatairesDisponibles">
                                        <ul class="mt-2 max-h-48 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                                            @forelse ($this->destinatairesDisponibles as $candidat)
                                                <li class="flex items-center justify-between gap-2 py-1.5">
                                                    <flux:checkbox value="{{ $candidat->id }}" :label="$candidat->name" />
                                                    <flux:button size="sm" variant="ghost" icon="arrow-right" wire:click="ajouterDestinataire({{ $candidat->id }})" />
                                                </li>
                                            @empty
                                                <li class="py-2 text-sm text-zinc-400">{{ __('Aucun utilisateur.') }}</li>
                                            @endforelse
                                        </ul>
                                    </flux:checkbox.group>
                                </div>
                                <div class="flex flex-row items-center justify-center gap-2 sm:flex-col">
                                    <flux:button size="sm" icon="arrow-right" :variant="$selectionDestinatairesDisponibles ? 'primary' : 'ghost'" :disabled="! $selectionDestinatairesDisponibles" wire:click="ajouterSelectionDestinataires" />
                                    <flux:button size="sm" icon="arrow-left" :variant="$selectionDestinatairesAssignes ? 'primary' : 'ghost'" :disabled="! $selectionDestinatairesAssignes" wire:click="retirerSelectionDestinataires" />
                                </div>
                                <div class="rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                                    <flux:input size="sm" wire:model.live.debounce.300ms="rechercheDestinatairesAssignes" icon="magnifying-glass" :placeholder="__('Rechercher…')" />
                                    <flux:checkbox.group wire:model.live="selectionDestinatairesAssignes">
                                        <ul class="mt-2 max-h-48 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                                            @forelse ($this->destinatairesDeLutilisateurSelectionne as $destinataire)
                                                <li class="flex items-center justify-between gap-2 py-1.5">
                                                    <flux:checkbox value="{{ $destinataire->id }}" :label="$destinataire->name" />
                                                    <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="retirerDestinataire({{ $destinataire->id }})" />
                                                </li>
                                            @empty
                                                <li class="py-2 text-sm text-zinc-400">{{ __('Aucun destinataire autorisé.') }}</li>
                                            @endforelse
                                        </ul>
                                    </flux:checkbox.group>
                                </div>
                            </div>

                            {{-- Services — un pli confidentiel est parfois
                                 adressé à tout un service ("Direction
                                 Générale", "RH"), pas seulement à une
                                 personne nommée (2026-10-07). --}}
                            <flux:separator class="my-6" />
                            <flux:text class="mb-3 text-zinc-500">{{ __('Services que cet utilisateur peut choisir comme destinataire (pli confidentiel adressé au responsable du service).') }}</flux:text>
                            <div class="grid gap-4 sm:grid-cols-[1fr_auto_1fr]">
                                <div class="rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                                    <flux:input size="sm" wire:model.live.debounce.300ms="rechercheServicesDisponibles" icon="magnifying-glass" :placeholder="__('Rechercher…')" />
                                    <flux:checkbox.group wire:model.live="selectionServicesDisponibles">
                                        <ul class="mt-2 max-h-48 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                                            @forelse ($this->servicesDisponibles as $service)
                                                <li class="flex items-center justify-between gap-2 py-1.5">
                                                    <flux:checkbox value="{{ $service->id }}" :label="$service->nom" />
                                                    <flux:button size="sm" variant="ghost" icon="arrow-right" wire:click="ajouterServiceDestinataire({{ $service->id }})" />
                                                </li>
                                            @empty
                                                <li class="py-2 text-sm text-zinc-400">{{ __('Aucun service.') }}</li>
                                            @endforelse
                                        </ul>
                                    </flux:checkbox.group>
                                </div>
                                <div class="flex flex-row items-center justify-center gap-2 sm:flex-col">
                                    <flux:button size="sm" icon="arrow-right" :variant="$selectionServicesDisponibles ? 'primary' : 'ghost'" :disabled="! $selectionServicesDisponibles" wire:click="ajouterSelectionServices" />
                                    <flux:button size="sm" icon="arrow-left" :variant="$selectionServicesAssignes ? 'primary' : 'ghost'" :disabled="! $selectionServicesAssignes" wire:click="retirerSelectionServices" />
                                </div>
                                <div class="rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                                    <flux:input size="sm" wire:model.live.debounce.300ms="rechercheServicesAssignes" icon="magnifying-glass" :placeholder="__('Rechercher…')" />
                                    <flux:checkbox.group wire:model.live="selectionServicesAssignes">
                                        <ul class="mt-2 max-h-48 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                                            @forelse ($this->servicesDeLutilisateurSelectionne as $service)
                                                <li class="flex items-center justify-between gap-2 py-1.5">
                                                    <flux:checkbox value="{{ $service->id }}" :label="$service->nom" />
                                                    <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="retirerServiceDestinataire({{ $service->id }})" />
                                                </li>
                                            @empty
                                                <li class="py-2 text-sm text-zinc-400">{{ __('Aucun service autorisé.') }}</li>
                                            @endforelse
                                        </ul>
                                    </flux:checkbox.group>
                                </div>
                            </div>
                        </div>

                        {{-- Périmètre d'accès (Module "Organisation" v2, spec
                             §19) — même patron "deux boîtes" que ci-dessus,
                             sur des entités de l'organigramme plutôt que des
                             utilisateurs. Opt-in : aucune entité assignée =
                             pas de restriction (voir
                             Courrier::estDansLePerimetreDe()). --}}
                        @if ($peutGererAcces)
                        <div x-show="onglet === 'perimetre'" x-cloak>
                            <flux:text class="mb-3 text-zinc-500">{{ __('Entités de l\'organigramme dont cet utilisateur peut voir tous les courriers, en plus de son périmètre habituel. Aucune entité assignée = aucune restriction supplémentaire.') }}</flux:text>
                            <div class="grid gap-4 sm:grid-cols-[1fr_auto_1fr]">
                                <div class="rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                                    <flux:input size="sm" wire:model.live.debounce.300ms="recherchePerimetreDisponibles" icon="magnifying-glass" :placeholder="__('Rechercher…')" />
                                    <flux:checkbox.group wire:model.live="selectionPerimetreDisponibles">
                                        <ul class="mt-2 max-h-48 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                                            @forelse ($this->perimetreDisponible as $unite)
                                                <li class="flex items-center justify-between gap-2 py-1.5">
                                                    <flux:checkbox value="{{ $unite['id'] }}" :label="$unite['chemin']" />
                                                    <flux:button size="sm" variant="ghost" icon="arrow-right" wire:click="ajouterPerimetre({{ $unite['id'] }})" />
                                                </li>
                                            @empty
                                                <li class="py-2 text-sm text-zinc-400">{{ __('Aucune entité.') }}</li>
                                            @endforelse
                                        </ul>
                                    </flux:checkbox.group>
                                </div>
                                <div class="flex flex-row items-center justify-center gap-2 sm:flex-col">
                                    <flux:button size="sm" icon="arrow-right" :variant="$selectionPerimetreDisponibles ? 'primary' : 'ghost'" :disabled="! $selectionPerimetreDisponibles" wire:click="ajouterSelectionPerimetre" />
                                    <flux:button size="sm" icon="arrow-left" :variant="$selectionPerimetreAssignes ? 'primary' : 'ghost'" :disabled="! $selectionPerimetreAssignes" wire:click="retirerSelectionPerimetre" />
                                </div>
                                <div class="rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                                    <flux:input size="sm" wire:model.live.debounce.300ms="recherchePerimetreAssignes" icon="magnifying-glass" :placeholder="__('Rechercher…')" />
                                    <flux:checkbox.group wire:model.live="selectionPerimetreAssignes">
                                        <ul class="mt-2 max-h-48 divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
                                            @forelse ($this->perimetreDeLutilisateurSelectionne as $unite)
                                                <li class="flex items-center justify-between gap-2 py-1.5">
                                                    <flux:checkbox value="{{ $unite['id'] }}" :label="$unite['chemin']" />
                                                    <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="retirerPerimetre({{ $unite['id'] }})" />
                                                </li>
                                            @empty
                                                <li class="py-2 text-sm text-zinc-400">{{ __('Aucune entité assignée — pas de restriction.') }}</li>
                                            @endforelse
                                        </ul>
                                    </flux:checkbox.group>
                                </div>
                            </div>
                        </div>
                        @endif
                    </div>

                    {{-- "Aperçu du compte" — persistant, visible sur TOUS les
                         onglets (pas seulement "Informations générales"),
                         comme dans la maquette. Reflète l'état ENREGISTRÉ de
                         l'utilisateur, pas les champs en cours de saisie
                         (contrairement au "Récapitulatif" live de la modale
                         "Ajouter un utilisateur" plus haut). --}}
                    <div class="space-y-4">
                        <div class="rounded-xl border border-brand-border p-4 dark:border-zinc-700">
                            <div class="flex items-center gap-3">
                                <flux:avatar circle :initials="$utilisateur->initials()" :src="$utilisateur->photoUrl()" />
                                <div class="min-w-0">
                                    <div class="truncate font-medium">{{ $utilisateur->name }}</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $utilisateur->profil?->nom }}</div>
                                </div>
                            </div>
                            <span class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium {{ $utilisateur->actif ? 'text-brand-success' : 'text-zinc-500' }}">
                                <span class="size-2 rounded-full {{ $utilisateur->actif ? 'bg-brand-success' : 'bg-zinc-400' }}"></span>
                                {{ $utilisateur->actif ? __('Actif') : __('Désactivé') }}
                            </span>

                            <dl class="mt-4 space-y-3 text-sm">
                                <div class="flex items-start gap-2">
                                    <flux:icon.envelope class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                    <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ __('Email') }}</dt><dd class="truncate">{{ $utilisateur->email }}</dd></div>
                                </div>
                                @if ($utilisateur->telephone)
                                    <div class="flex items-start gap-2">
                                        <flux:icon.phone class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                        <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ __('Téléphone') }}</dt><dd>{{ $utilisateur->telephone }}</dd></div>
                                    </div>
                                @endif
                                <div class="flex items-start gap-2">
                                    <flux:icon.building-office-2 class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                    <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ __('Département') }}</dt><dd class="truncate">{{ $this->departementLabelDe($utilisateur) ?? '—' }}</dd></div>
                                </div>
                                <div class="flex items-start gap-2">
                                    <flux:icon.identification class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                    <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ __('Profil') }}</dt><dd class="truncate">{{ $utilisateur->profil?->nom ?? '—' }}</dd></div>
                                </div>
                                <div class="flex items-start gap-2">
                                    <flux:icon.lock-closed class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                    <div class="min-w-0">
                                        <dt class="text-xs text-zinc-500">{{ __('Niveau de confidentialité') }}</dt>
                                        <dd><span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match (true) {
                                            $utilisateur->niveau_confidentialite >= 4 => 'bg-brand-danger/10 text-brand-danger',
                                            $utilisateur->niveau_confidentialite >= 2 => 'bg-brand-warning/10 text-brand-warning',
                                            default => 'bg-zinc-100 text-zinc-600',
                                        } }}">{{ __('Niveau :n', ['n' => $utilisateur->niveau_confidentialite]) }}</span></dd>
                                    </div>
                                </div>
                            </dl>
                        </div>

                        <flux:callout variant="warning" icon="exclamation-triangle" class="text-sm">
                            {{ __('La modification de l\'utilisateur peut impacter ses accès aux modules et aux données sensibles.') }}
                        </flux:callout>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" icon="check" wire:click="enregistrerEdition">{{ __('Enregistrer les modifications') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- ===================== Modale "Gestion des permissions" ===================== --}}
    <flux:modal name="user-permissions" class="w-full max-w-5xl">
        @if ($this->utilisateurEnEdition)
            @php $utilisateur = $this->utilisateurEnEdition; @endphp
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-accent-pale text-brand-accent">
                        <flux:icon.shield-check class="size-5" />
                    </div>
                    <div>
                        <flux:heading level="2">{{ __('Gestion des permissions') }}</flux:heading>
                        <flux:subheading>{{ __('Définissez les permissions de :nom selon son rôle et son service.', ['nom' => $utilisateur->name]) }}</flux:subheading>
                    </div>
                </div>

                <div class="grid gap-4 lg:grid-cols-[220px_minmax(0,1fr)_240px]">
                    {{-- Modules --}}
                    <div class="space-y-1 lg:max-h-104 lg:overflow-y-auto">
                        @foreach ($this->modules as $module)
                            <button type="button" wire:click="$set('modulePermissionSelectionne', '{{ $module['cle'] }}')" class="flex w-full items-center justify-between gap-2 rounded-lg p-2.5 text-left text-sm {{ $modulePermissionSelectionne === $module['cle'] ? 'bg-brand-accent-pale text-brand-accent' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/60' }}">
                                <span class="flex items-center gap-2">
                                    <flux:icon :icon="$module['icon']" class="size-4" />
                                    {{ $module['label'] }}
                                </span>
                                <span class="text-xs text-zinc-400">{{ $module['total'] }}</span>
                            </button>
                        @endforeach
                    </div>

                    {{-- Permissions du module sélectionné --}}
                    <div class="space-y-3">
                        <flux:input size="sm" wire:model.live.debounce.300ms="recherchePermission" icon="magnifying-glass" :placeholder="__('Rechercher une permission…')" />
                        <div class="max-h-88 space-y-2 overflow-y-auto">
                            @forelse ($this->permissionsDuModule as $permission)
                                @php $coche = $utilisateur->privilegesDirectes->pluck('id')->contains($permission->id); @endphp
                                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-brand-border p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/60">
                                    <input type="checkbox" class="mt-1" wire:click="basculerPermission({{ $permission->id }})" @checked($coche) />
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-2">
                                            <span class="font-medium">{{ $permission->nom }}</span>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs {{ match ($permission->type) {
                                                'lecture' => 'bg-brand-accent/10 text-brand-accent',
                                                'administratif' => 'bg-brand-danger/10 text-brand-danger',
                                                default => 'bg-brand-warning/10 text-brand-warning',
                                            } }}">
                                                {{ match ($permission->type) { 'lecture' => __('Lecture'), 'administratif' => __('Administratif'), default => __('Écriture') } }}
                                            </span>
                                        </span>
                                        @if ($permission->description)
                                            <span class="mt-0.5 block text-xs text-zinc-500">{{ $permission->description }}</span>
                                        @endif
                                    </span>
                                </label>
                            @empty
                                <flux:text class="p-4 text-center text-zinc-500">{{ __('Aucune permission dans ce module.') }}</flux:text>
                            @endforelse
                        </div>
                    </div>

                    {{-- Récapitulatif --}}
                    <div class="space-y-4">
                        <div class="flex items-center gap-3 rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                            <flux:avatar size="sm" circle :initials="$utilisateur->initials()" :src="$utilisateur->photoUrl()" />
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium">{{ $utilisateur->name }}</div>
                                <div class="truncate text-xs text-zinc-500">{{ $utilisateur->profil?->nom }}</div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                            <flux:heading level="4" class="mb-2">{{ __('Résumé des permissions') }}</flux:heading>
                            <dl class="space-y-1.5 text-sm">
                                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Modules actifs') }}</dt><dd class="font-medium">{{ $this->resumePermissions['modulesActifs'] }}/{{ $this->resumePermissions['modulesTotal'] }}</dd></div>
                                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Permissions de lecture') }}</dt><dd class="font-medium">{{ $this->resumePermissions['lecture'] }}</dd></div>
                                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Permissions d\'écriture') }}</dt><dd class="font-medium">{{ $this->resumePermissions['ecriture'] }}</dd></div>
                                <div class="flex justify-between"><dt class="text-zinc-500">{{ __('Permissions administratives') }}</dt><dd class="font-medium">{{ $this->resumePermissions['administratif'] }}</dd></div>
                            </dl>
                        </div>

                        <div class="space-y-2">
                            <flux:heading level="4">{{ __('Actions rapides') }}</flux:heading>
                            <flux:button size="sm" class="w-full" variant="outline" icon="check" wire:click="toutSelectionnerModule">{{ __('Tout sélectionner') }}</flux:button>
                            <flux:button size="sm" class="w-full" variant="outline" icon="x-mark" wire:click="toutDeselectionnerModule">{{ __('Tout désélectionner') }}</flux:button>
                        </div>
                    </div>
                </div>

                <flux:callout variant="info" icon="information-circle" class="text-sm">
                    {{ __('Les permissions sont appliquées immédiatement après enregistrement.') }}
                </flux:callout>

                <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Fermer') }}</flux:button></flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
