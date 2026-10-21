{{-- Module 3/9 — "Dossiers & Archives" (2026-09-21, reconstruit depuis une
     maquette fournie par l'utilisateur). Arbre de dossiers (gauche) +
     contenu du dossier sélectionné (centre) + "Détails du dossier" (droite,
     sticky — RÈGLE PERMANENTE, voir notes internes). --}}
<section class="w-full">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Dossiers & Archives') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Dossiers') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
                <flux:icon.folder class="size-5" />
            </div>
            <div>
                <flux:heading level="1">{{ __('Dossiers') }}</flux:heading>
                <flux:subheading>{{ __('Gérez vos dossiers virtuels pour organiser et retrouver facilement vos courriers.') }}</flux:subheading>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('create', App\Models\DossierClassement::class)
                <flux:button variant="primary" icon="plus" wire:click="ouvrirCreation">{{ __('Nouveau dossier') }}</flux:button>
            @endcan
            {{-- "Importer" (maquette) : aucune fonctionnalité réelle
                 derrière à ce jour — même principe que courrierList.blade.php
                 (désactivé avec infobulle, jamais fabriqué). --}}
            <flux:tooltip content="{{ __('Bientôt disponible') }}">
                <flux:button variant="outline" icon="arrow-down-tray" disabled>{{ __('Importer') }}</flux:button>
            </flux:tooltip>
        </div>
    </div>

    {{-- Grille à 3 colonnes englobant TOUT le contenu principal (arbre +
         table), pas seulement la table — convention permanente (voir notes internes). Les deux colonnes latérales sont
         sticky (notes internes). --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-[280px_minmax(0,1fr)_360px]">
        {{-- ===== Colonne gauche — arborescence ===== --}}
        <div class="sticky top-20 self-start space-y-3">
            <div class="rounded-2xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-2 flex items-center justify-between px-1">
                    <flux:text class="text-xs font-medium uppercase text-zinc-500">{{ __('Arborescence des dossiers') }}</flux:text>
                </div>

                <nav class="space-y-[2px]">
                    <button type="button" wire:click="selectionnerNoeud('')"
                        class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm font-medium {{ $dossierId === null && $noeud === '' ? 'bg-brand-accent-pale text-brand-accent' : 'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/60' }}">
                        <span class="flex items-center gap-2"><flux:icon.squares-2x2 class="size-4" />{{ __('Tous les dossiers') }}</span>
                        <span class="text-xs text-zinc-400">{{ $this->tousLesDossiersAccessibles->sum('courriers_count') }}</span>
                    </button>

                    <button type="button" wire:click="selectionnerNoeud('generaux')"
                        class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm {{ $noeud === 'generaux' ? 'bg-brand-accent-pale font-medium text-brand-accent' : 'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/60' }}">
                        <span class="flex items-center gap-2"><flux:icon.inbox class="size-4" />{{ __('Courriers généraux') }}</span>
                    </button>

                    <div class="my-2 border-t border-brand-border dark:border-zinc-700"></div>

                    @foreach ($this->arbre as $racine)
                        <x-dossier-tree-node :noeud="$racine" :dossier-id-selectionne="$dossierId" :tous-les-dossiers="$this->tousLesDossiersAccessibles" wire:key="dossier-racine-{{ $racine['dossier']->id }}" />
                    @endforeach

                    @if (count($this->arbre) === 0)
                        <flux:text class="px-2 py-3 text-xs text-zinc-400">{{ __('Aucun dossier pour l\'instant.') }}</flux:text>
                    @endif

                    <div class="my-2 border-t border-brand-border dark:border-zinc-700"></div>

                    {{-- dossiers_classement.archives (2026-09-23) — aussi vérifié côté serveur. --}}
                    @if (auth()->user()->hasPrivilege('dossiers_classement.archives'))
                        <button type="button" wire:click="selectionnerNoeud('archives')"
                            class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm {{ $noeud === 'archives' ? 'bg-brand-accent-pale font-medium text-brand-accent' : 'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/60' }}">
                            <span class="flex items-center gap-2"><flux:icon.archive-box class="size-4" />{{ __('Archives') }}</span>
                        </button>
                    @endif

                    {{-- "Dossier surveillé" — confirmé avec l'utilisateur :
                         même fonctionnalité déjà réelle (scan auto-importé),
                         simple lien, aucun état de ce composant. Même
                         privilège que la page cible (ScanPremier). --}}
                    @can('numeriser', App\Models\Courrier::class)
                        <flux:link href="{{ route('courriers.numeriser-nouveau') }}" wire:navigate class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/60">
                            <flux:icon.eye class="size-4" />{{ __('Dossier surveillé') }}
                        </flux:link>
                    @endcan
                </nav>
            </div>
        </div>

        {{-- ===== Colonne centrale — contenu du dossier sélectionné ===== --}}
        <div class="space-y-4">
            <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading level="2">{{ __('Contenu du dossier') }}</flux:heading>
                <flux:subheading>{{ __('Tous les courriers contenus dans le dossier sélectionné.') }}</flux:subheading>
            </div>

            <div x-data="squeletteMinimum()" class="overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div wire:loading.class.remove="hidden" x-ref="sentinelle" class="hidden" aria-hidden="true"></div>
                <table class="w-full text-sm">
                    <thead class="bg-brand-table-header text-left text-sm font-medium text-zinc-600 dark:bg-zinc-800">
                        <tr>
                            <th class="py-3 pl-4 pr-3">{{ __('N° Courrier') }}</th>
                            <th class="py-3 pr-3">{{ __('Objet') }}</th>
                            <th class="py-3 pr-3">{{ __('Expéditeur') }}</th>
                            <th class="py-3 pr-3">{{ __('Date de dépôt') }}</th>
                            <th class="py-3 pr-3">{{ __('Statut') }}</th>
                            <th class="py-3 pr-4">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    {{-- Visibilité pilotée par l'état `visible` de
                         squeletteMinimum() (x-data ci-dessus), durée
                         minimale garantie — voir le commentaire équivalent
                         dans courrierList.blade.php (2026-09-24). --}}
                    <tbody x-show="visible" x-cloak class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <x-skeleton.table-rows :cols="6" />
                    </tbody>
                    <tbody x-show="!visible" class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->courriersDuNoeud as $courrier)
                            <tr wire:key="courrier-{{ $courrier->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <td class="py-3 pl-4 pr-3 font-medium">{{ $courrier->numero_reference }}</td>
                                <td class="max-w-xs truncate py-3 pr-3">{{ $courrier->objet }}</td>
                                <td class="py-3 pr-3 text-zinc-600 dark:text-zinc-400">{{ $courrier->expediteur_nom ?: $courrier->expediteur_organisation ?: '—' }}</td>
                                <td class="py-3 pr-3 text-zinc-600 dark:text-zinc-400">{{ $courrier->date_mouvement?->format('d/m/Y') ?? '—' }}</td>
                                <td class="py-3 pr-3">
                                    {{-- Chronomètre à côté du statut (2026-09-28, "when the date
                                         is comming soon the colours should change") — empilé sur
                                         2 lignes (2026-10-02, positionnement revu). --}}
                                    <x-statut-avec-echeance :courrier="$courrier" />
                                </td>
                                <td class="py-3 pr-4">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" square :aria-label="__('Actions')" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" :href="route('courriers.show', $courrier->id)" wire:navigate>{{ __('Voir') }}</flux:menu.item>
                                            {{-- "Retirer du dossier" n'a de sens que sur un vrai
                                                 nœud dossier — pas sur "Tous les dossiers"/
                                                 "Courriers généraux"/"Archives". --}}
                                            @if ($dossierId !== null && auth()->user()->hasPrivilege('courriers.classer'))
                                                <flux:menu.item icon="folder-minus" variant="danger" wire:click="retirerCourrierDuDossier({{ $courrier->id }})">{{ __('Retirer du dossier') }}</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-10 text-center text-sm text-zinc-500">{{ __('Aucun courrier ne correspond à ces critères.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $this->courriersDuNoeud->links() }}</div>
        </div>

        {{-- ===== Colonne droite — "Détails du dossier" ===== --}}
        <div x-data="squeletteMinimum()" class="sticky top-20 self-start space-y-4">
            <div wire:loading.class.remove="hidden" x-ref="sentinelle" class="hidden" aria-hidden="true"></div>
            {{-- Squelette (2026-09-24) — sélectionner un autre nœud
                 (selectionnerDossier/selectionnerNoeud) redéclenche une
                 requête Livewire qui recharge ce panneau. Durée minimale
                 garantie via squeletteMinimum(), voir resources/js/app.js
                 (2026-09-24, "am not seeing animation... in page"). --}}
            <div x-show="visible" x-cloak class="rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <x-skeleton.panel :lignes="4" />
            </div>
            <div x-show="!visible" class="space-y-4">
            @if ($this->dossierSelectionne)
                @php($dossier = $this->dossierSelectionne)
                <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-3">
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-accent-pale text-brand-accent">
                                <flux:icon.folder class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ $dossier->nom }}</div>
                                <div class="text-xs text-zinc-500">{{ __(':n courrier(s)', ['n' => $dossier->courriers()->count()]) }}</div>
                            </div>
                        </div>
                        @can('update', $dossier)
                            <flux:button variant="ghost" size="sm" icon="pencil-square" square :aria-label="__('Modifier')" wire:click="ouvrirRenommage({{ $dossier->id }})" />
                        @endcan
                    </div>

                    @if ($dossier->description)
                        <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">{{ $dossier->description }}</flux:text>
                    @endif

                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex items-start gap-2">
                            <flux:icon.user class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0">
                                <dt class="text-xs text-zinc-500">{{ __('Responsable') }}</dt>
                                <dd class="truncate">{{ $dossier->responsable?->name ?? '—' }}</dd>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.calendar class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0">
                                <dt class="text-xs text-zinc-500">{{ __('Date de création') }}</dt>
                                <dd>{{ $dossier->created_at->format('d/m/Y H:i') }}</dd>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.clock class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0">
                                <dt class="text-xs text-zinc-500">{{ __('Dernière modification') }}</dt>
                                <dd>{{ $dossier->updated_at->format('d/m/Y H:i') }}</dd>
                            </div>
                        </div>
                        @if ($dossier->reference_localisation_physique)
                            <div class="flex items-start gap-2">
                                <flux:icon.map-pin class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                <div class="min-w-0">
                                    <dt class="text-xs text-zinc-500">{{ __('Localisation physique') }}</dt>
                                    <dd>{{ $dossier->reference_localisation_physique }}</dd>
                                </div>
                            </div>
                        @endif
                        <div class="flex items-start gap-2">
                            <flux:icon.signal class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0">
                                <dt class="text-xs text-zinc-500">{{ __('Statut') }}</dt>
                                <dd><x-statut-badge :statut="$dossier->statut" /></dd>
                            </div>
                        </div>
                    </dl>
                </div>

                <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:text class="mb-2 text-xs font-medium uppercase text-zinc-500">{{ __('Actions rapides') }}</flux:text>
                    <div class="space-y-1">
                        {{-- Module 3/9 — "Ajouter des courriers" (2026-09-22,
                             demande explicite de l'utilisateur, "les trois"
                             points d'entrée) : toujours actionnable dès qu'un
                             vrai dossier est ouvert, $dossier n'existe ici que
                             si dossierSelectionne l'a déjà réautorisé via
                             view() (voir le computed correspondant). --}}
                        {{-- courriers.classer (2026-09-23). --}}
                        @if (auth()->user()->hasPrivilege('courriers.classer'))
                            <button type="button" wire:click="ouvrirAjoutCourriers" class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                <span class="flex items-center gap-2"><flux:icon.document-plus class="size-4 text-zinc-400" />{{ __('Ajouter des courriers') }}</span>
                                <flux:icon.chevron-right class="size-4 text-zinc-400" />
                            </button>
                        @endif
                        @can('create', [App\Models\DossierClassement::class, $dossier])
                            <button type="button" wire:click="ouvrirCreation({{ $dossier->id }})" class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                <span class="flex items-center gap-2"><flux:icon.folder-plus class="size-4 text-zinc-400" />{{ __('Ajouter un sous-dossier') }}</span>
                                <flux:icon.chevron-right class="size-4 text-zinc-400" />
                            </button>
                        @endcan
                        @can('deplacer', $dossier)
                            <button type="button" wire:click="ouvrirDeplacement({{ $dossier->id }})" class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                <span class="flex items-center gap-2"><flux:icon.arrows-right-left class="size-4 text-zinc-400" />{{ __('Déplacer le dossier') }}</span>
                                <flux:icon.chevron-right class="size-4 text-zinc-400" />
                            </button>
                        @endcan
                        @can('partager', $dossier)
                            <button type="button" wire:click="ouvrirPartage({{ $dossier->id }})" class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                <span class="flex items-center gap-2"><flux:icon.share class="size-4 text-zinc-400" />{{ __('Partager le dossier') }}</span>
                                <flux:icon.chevron-right class="size-4 text-zinc-400" />
                            </button>
                        @endcan
                        @can('delete', $dossier)
                            <button type="button" wire:click="ouvrirSuppression({{ $dossier->id }})" class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-sm text-brand-danger hover:bg-brand-danger-hover-bg">
                                <span class="flex items-center gap-2"><flux:icon.trash class="size-4" />{{ __('Supprimer le dossier') }}</span>
                                <flux:icon.chevron-right class="size-4" />
                            </button>
                        @endcan
                    </div>
                </div>
            @else
                <div class="rounded-2xl border border-brand-border bg-white p-6 text-center shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:icon.folder class="mx-auto size-8 text-zinc-300" />
                    <flux:text class="mt-2 text-sm text-zinc-500">{{ __('Sélectionnez un dossier pour voir ses détails.') }}</flux:text>
                </div>
            @endif
            </div>
        </div>
    </div>

    {{-- ===== Modale "Nouveau dossier" ===== --}}
    <flux:modal name="dossier-creation" class="w-full max-w-lg">
        <form wire:submit="creerDossier" class="space-y-6">
            <div class="flex items-center gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-accent-pale text-brand-accent">
                    <flux:icon.folder-plus class="size-5" />
                </div>
                <div>
                    <flux:heading level="2">{{ __('Nouveau dossier') }}</flux:heading>
                    <flux:subheading>{{ __('Créez un dossier pour organiser vos courriers.') }}</flux:subheading>
                </div>
            </div>

            <flux:input wire:model="nomDossier" :label="__('Nom du dossier')" required />
            <flux:textarea wire:model="descriptionDossier" :label="__('Description')" rows="2" />
            {{-- Pas de :placeholder (2026-10-12) — voir showCourrier.blade.php
                 "Classer dans un dossier" pour le mécanisme exact (option
                 désactivée peu fiable après un morph Livewire, le <select>
                 natif retombe sur la première option RÉELLE). --}}
            <flux:select wire:model="serviceIdDossier" :label="__('Service')">
                <flux:select.option value="">{{ __('— Aucun —') }}</flux:select.option>
                @foreach ($this->services as $service)
                    <flux:select.option value="{{ $service->id }}">{{ $service->nom }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="referenceLocalisationDossier" :label="__('Référence de localisation physique')" placeholder="{{ __('ex. Armoire A, Niveau 2') }}" />

            <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Créer') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ===== Modale "Renommer" ===== --}}
    <flux:modal name="dossier-renommage" class="w-full max-w-lg">
        <form wire:submit="renommerDossier" class="space-y-6">
            <flux:heading level="2">{{ __('Modifier le dossier') }}</flux:heading>
            <flux:input wire:model="nomRenommage" :label="__('Nom du dossier')" required />
            <flux:textarea wire:model="descriptionRenommage" :label="__('Description')" rows="2" />
            <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Enregistrer') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ===== Modale "Déplacer" ===== --}}
    <flux:modal name="dossier-deplacement" class="w-full max-w-lg">
        <div class="space-y-6">
            <flux:heading level="2">{{ __('Déplacer le dossier') }}</flux:heading>
            {{-- Pas de :placeholder (2026-10-12) — voir showCourrier.blade.php
                 "Classer dans un dossier" pour le mécanisme exact. --}}
            <flux:select wire:model="nouveauParentId" :label="__('Nouveau dossier parent')">
                <flux:select.option value="">{{ __('— Racine —') }}</flux:select.option>
                @foreach ($this->tousLesDossiersAccessibles as $candidat)
                    @if ($candidat->id !== $dossierADeplacerId)
                        <flux:select.option value="{{ $candidat->id }}">{{ $candidat->cheminComplet($this->tousLesDossiersAccessibles) }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" icon="check" wire:click="deplacerDossier">{{ __('Déplacer') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ===== Modale "Partager" — deux boîtes, même pattern que
         "Destinataires de transfert" (userList.blade.php) ===== --}}
    <flux:modal name="dossier-partage" class="w-full max-w-2xl">
        <div class="space-y-6">
            <flux:heading level="2">{{ __('Partager le dossier') }}</flux:heading>
            <flux:subheading>{{ __('Choisissez qui peut voir ce dossier, en plus de vous.') }}</flux:subheading>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                    <flux:input wire:model.live.debounce.300ms="recherchePartageDisponibles" icon="magnifying-glass" placeholder="{{ __('Rechercher...') }}" size="sm" />
                    <div class="mt-2 max-h-64 space-y-1 overflow-y-auto">
                        @forelse ($this->partageDisponibles as $utilisateur)
                            <div class="flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                <label class="flex min-w-0 items-center gap-2">
                                    <input type="checkbox" wire:model.live="selectionPartageDisponibles" value="{{ $utilisateur->id }}" class="rounded border-zinc-300" />
                                    <span class="truncate">{{ $utilisateur->name }}</span>
                                </label>
                                <flux:button variant="ghost" size="sm" icon="plus" square wire:click="ajouterPartage({{ $utilisateur->id }})" :aria-label="__('Ajouter')" />
                            </div>
                        @empty
                            <flux:text class="px-2 py-3 text-xs text-zinc-400">{{ __('Aucun résultat.') }}</flux:text>
                        @endforelse
                    </div>
                    <flux:button variant="outline" size="sm" class="mt-2 w-full" wire:click="ajouterSelectionPartage">{{ __('Ajouter la sélection') }}</flux:button>
                </div>

                <div class="rounded-xl border border-brand-border p-3 dark:border-zinc-700">
                    <flux:input wire:model.live.debounce.300ms="recherchePartageAssignes" icon="magnifying-glass" placeholder="{{ __('Rechercher...') }}" size="sm" />
                    <div class="mt-2 max-h-64 space-y-1 overflow-y-auto">
                        @forelse ($this->partageAssignes as $utilisateur)
                            <div class="flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                <label class="flex min-w-0 items-center gap-2">
                                    <input type="checkbox" wire:model.live="selectionPartageAssignes" value="{{ $utilisateur->id }}" class="rounded border-zinc-300" />
                                    <span class="truncate">{{ $utilisateur->name }}</span>
                                </label>
                                <flux:button variant="ghost" size="sm" icon="minus" square wire:click="retirerPartage({{ $utilisateur->id }})" :aria-label="__('Retirer')" />
                            </div>
                        @empty
                            <flux:text class="px-2 py-3 text-xs text-zinc-400">{{ __('Cet utilisateur n\'a encore aucun destinataire autorisé.') }}</flux:text>
                        @endforelse
                    </div>
                    <flux:button variant="outline" size="sm" class="mt-2 w-full" wire:click="retirerSelectionPartage">{{ __('Retirer la sélection') }}</flux:button>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="primary">{{ __('Fermer') }}</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    {{-- ===== Modale "Supprimer" ===== --}}
    <flux:modal name="dossier-suppression" class="w-full max-w-md">
        <div class="space-y-6">
            <flux:heading level="2">{{ __('Supprimer ce dossier ?') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">{{ __('Un dossier contenant des sous-dossiers ou des courriers ne peut pas être supprimé — déplacez-les d\'abord.') }}</flux:text>
            <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" icon="trash" wire:click="supprimerDossier">{{ __('Supprimer') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ===== Modale "Ajouter des courriers" (2026-09-22) ===== --}}
    <flux:modal name="dossier-ajout-courriers" class="w-full max-w-lg">
        <div class="space-y-4">
            <flux:heading level="2">{{ __('Ajouter des courriers au dossier') }}</flux:heading>
            <flux:input wire:model.live.debounce.300ms="rechercheCourriersAAjouter" icon="magnifying-glass" :placeholder="__('Rechercher par N°, objet ou expéditeur…')" />

            <flux:checkbox.group wire:model.live="courriersAAjouter" class="max-h-64 space-y-1 overflow-y-auto">
                @forelse ($this->courriersDisponiblesPourAjout as $courrier)
                    <label class="flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                        <span class="flex min-w-0 items-center gap-2">
                            <flux:checkbox value="{{ $courrier->id }}" />
                            <span class="truncate">{{ $courrier->numero_reference }} — {{ $courrier->objet }}</span>
                        </span>
                    </label>
                @empty
                    <flux:text class="px-2 py-3 text-xs text-zinc-400">
                        {{ trim($rechercheCourriersAAjouter) === '' ? __('Tapez pour rechercher un courrier.') : __('Aucun résultat.') }}
                    </flux:text>
                @endforelse
            </flux:checkbox.group>

            {{-- rechercheCourriersAAjouter vide renvoie une Collection simple
                 (pas de pagination tant qu'il n'y a rien à paginer) —
                 ->links() n'existe que sur le LengthAwarePaginator retourné
                 une fois une recherche tapée. --}}
            @if (trim($rechercheCourriersAAjouter) !== '')
                <div>{{ $this->courriersDisponiblesPourAjout->links() }}</div>
            @endif

            <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" icon="check" wire:click="ajouterCourriersSelection" :disabled="empty($courriersAAjouter)">{{ __('Ajouter la sélection') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
