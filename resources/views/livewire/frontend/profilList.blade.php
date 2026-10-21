{{-- Page "Profils" (2026-09-23, "make profile page as userlist") : même
     présentation que userList.blade.php — en-tête, carte de recherche,
     tableau avec menu d'actions, modale "Gestion des permissions" par
     module. --}}
<div>
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Administration') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Profils') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
                <flux:icon.identification class="size-5" />
            </div>
            <div>
                <flux:heading level="1">{{ __('Profils') }}</flux:heading>
                <flux:subheading>{{ __('Gérez les profils et les permissions héritées par tous leurs utilisateurs.') }}</flux:subheading>
            </div>
        </div>

        @can('creerProfil', App\Models\Privilege::class)
            <flux:button variant="primary" icon="plus" wire:click="ouvrirCreation">
                {{ __('Nouveau profil') }}
            </flux:button>
        @endcan
    </div>

    <div class="mt-6 rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:input wire:model.live.debounce.400ms="recherche" icon="magnifying-glass" :placeholder="__('Rechercher un profil…')" />
    </div>

    <div class="mt-6 overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead class="bg-brand-table-header text-left text-sm font-medium text-zinc-600 dark:bg-zinc-800">
                <tr>
                    <th class="py-3 pl-4 pr-3">{{ __('Profil') }}</th>
                    <th class="py-3 pr-3">{{ __('Utilisateurs') }}</th>
                    <th class="py-3 pr-3">{{ __('Permissions') }}</th>
                    <th class="py-3 pr-3">{{ __('Répartition') }}</th>
                    <th class="py-3 pr-4 text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-border dark:divide-zinc-700">
                @forelse ($this->profils as $profil)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                        <td class="py-3 pl-4 pr-3">
                            <div class="flex items-center gap-2.5">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-accent-pale text-brand-accent">
                                    <flux:icon.identification class="size-4" />
                                </div>
                                <span class="font-medium">{{ $profil->nom }}</span>
                            </div>
                        </td>
                        <td class="py-3 pr-3 text-zinc-500">{{ trans_choice(':n utilisateur|:n utilisateurs', $profil->users_count, ['n' => $profil->users_count]) }}</td>
                        <td class="py-3 pr-3">
                            <span class="inline-flex items-center rounded-full bg-brand-accent-pale px-2 py-0.5 text-xs font-medium text-brand-accent">
                                {{ $profil->privileges->count() }} / {{ $this->permissionsCatalogue->count() }}
                            </span>
                        </td>
                        <td class="py-3 pr-3">
                            <div class="flex flex-wrap gap-1 text-xs">
                                <span class="inline-flex items-center rounded-full bg-brand-accent/10 px-2 py-0.5 text-brand-accent">{{ __('Lecture') }} {{ $profil->privileges->where('type', 'lecture')->count() }}</span>
                                <span class="inline-flex items-center rounded-full bg-brand-warning/10 px-2 py-0.5 text-brand-warning">{{ __('Écriture') }} {{ $profil->privileges->where('type', 'ecriture')->count() }}</span>
                                <span class="inline-flex items-center rounded-full bg-brand-danger/10 px-2 py-0.5 text-brand-danger">{{ __('Administratif') }} {{ $profil->privileges->where('type', 'administratif')->count() }}</span>
                            </div>
                        </td>
                        <td class="py-3 pr-4 text-right">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="{{ __('Actions') }}" />
                                <flux:menu>
                                    <flux:menu.item icon="shield-check" wire:click="ouvrirPermissions({{ $profil->id }})">{{ __('Gérer les permissions') }}</flux:menu.item>
                                    @can('gererUtilisateurs', App\Models\Privilege::class)
                                        <flux:menu.item icon="users" :href="route('admin.utilisateurs', ['profilFiltreId' => $profil->id])" wire:navigate>{{ __('Voir les utilisateurs') }}</flux:menu.item>
                                    @endcan
                                </flux:menu>
                            </flux:dropdown>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="p-6 text-center text-zinc-500">{{ __('Aucun profil ne correspond à cette recherche.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ===================== Modale "Nouveau profil" ===================== --}}
    <flux:modal name="profil-form" class="w-full max-w-sm">
        <form wire:submit="creerProfil" class="space-y-4">
            <flux:heading size="lg">{{ __('Nouveau profil') }}</flux:heading>
            <flux:input wire:model="nouveauProfilNom" :label="__('Nom du profil')" placeholder="{{ __('ex. Archiviste') }}" required />
            <flux:text class="text-xs text-zinc-500">{{ __('Un nouveau profil n\'a aucune permission : la fenêtre des permissions s\'ouvre juste après sa création.') }}</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" icon="check">{{ __('Créer') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- ===================== Modale "Gestion des permissions" ===================== --}}
    <flux:modal name="profil-permissions" class="w-full max-w-5xl">
        @if ($this->profilEnEdition)
            @php($profil = $this->profilEnEdition)
            @php($idsAssignes = $profil->privileges->pluck('id'))
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-accent-pale text-brand-accent">
                        <flux:icon.shield-check class="size-5" />
                    </div>
                    <div>
                        <flux:heading level="2">{{ __('Gestion des permissions') }}</flux:heading>
                        <flux:subheading>{{ __('Permissions du profil :nom — héritées par tous ses utilisateurs.', ['nom' => $profil->nom]) }}</flux:subheading>
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
                                <label wire:key="profil-permission-{{ $permission->id }}" class="flex cursor-pointer items-start gap-3 rounded-lg border border-brand-border p-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800/60">
                                    <input type="checkbox" class="mt-1" wire:click="basculerPermission({{ $permission->id }})" @checked($idsAssignes->contains($permission->id)) />
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
                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-accent-pale text-brand-accent">
                                <flux:icon.identification class="size-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium">{{ $profil->nom }}</div>
                                <div class="truncate text-xs text-zinc-500">{{ trans_choice(':n utilisateur|:n utilisateurs', $profil->users_count, ['n' => $profil->users_count]) }}</div>
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
                    {{ __('Chaque case est enregistrée immédiatement et s\'applique à tous les utilisateurs de ce profil (à leur prochain chargement de page).') }}
                </flux:callout>

                <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Fermer') }}</flux:button></flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
