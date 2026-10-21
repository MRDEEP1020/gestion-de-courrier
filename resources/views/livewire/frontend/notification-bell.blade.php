{{-- Module 7 — centre de notifications in-app (2026-10-05), remplace le
     placeholder statique "Aucune notification pour l'instant." qui vivait
     directement dans sidebar.blade.php. `data-notifications-non-lues` ici :
     ce composant vit HORS du bloc @persist('app-sidebar') (voir
     sidebar.blade.php), donc il est toujours frais après un wire:navigate —
     le script en bas de sidebar.blade.php lit cette valeur pour
     resynchroniser le badge de l'item "Notifications" DANS la sidebar
     persistante, qui ne peut pas se recalculer lui-même. --}}
<div data-notifications-non-lues="{{ $this->nombreNonLues }}">
<flux:dropdown position="bottom" align="end">
    {{-- Retour utilisateur (2026-10-05, capture d'écran) : le badge
         précédent (offset -top-1/-end-1, pas de bordure) débordait trop loin
         du bouton et se fondait visuellement avec le reste de l'en-tête.
         Patron "badge de notification" standard : offset réduit (le badge
         reste posé SUR le coin de l'icône plutôt que de flotter à côté),
         `ring` de la couleur de fond de l'en-tête pour le détacher
         proprement (même technique qu'un avatar avec indicateur de statut),
         `pointer-events-none` pour ne jamais voler le clic au bouton. --}}
    <span class="relative inline-flex">
        <flux:button variant="ghost" icon="bell" size="sm" square :aria-label="__('Notifications')" />
        @if ($this->nombreNonLues > 0)
            <span class="badge-notif-pop pointer-events-none absolute -top-0.5 -end-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-danger px-1 text-[10px] font-semibold leading-none text-white ring-2 ring-white dark:ring-zinc-900">
                {{ $this->nombreNonLues > 9 ? '9+' : $this->nombreNonLues }}
            </span>
        @endif
    </span>

    <flux:menu class="w-80">
        <div class="flex items-center justify-between px-3 py-2">
            <flux:text class="font-medium text-brand-text-primary">{{ __('Notifications') }}</flux:text>
            @if ($this->nombreNonLues > 0)
                <button type="button" wire:click="marquerToutesCommeLues" class="text-xs text-brand-accent hover:underline">
                    {{ __('Tout marquer comme lu') }}
                </button>
            @endif
        </div>

        {{-- 2026-10-09 — enveloppe générique (libelle/sous_libelle/lien/icone/
             couleur) partagée par TOUTES les classes de notification (voir
             App\Notifications\CourrierNotification et les autres ajoutées
             ce jour, audit "list me all") — plus de branche spécifique à
             CourrierEnRetardNotification ici.
             2026-10-12, retour utilisateur direct ("i can't disting
             between them", capture d'écran du menu — toutes les icônes se
             ressemblaient) : <flux:menu.item icon="..."> délègue le rendu
             de l'icône à <flux:icon>, que le vendor force en gris neutre
             SANS exception (`**:data-flux-menu-item-icon:text-zinc-400`,
             voir vendor/livewire/flux/stubs/.../menu/item.blade.php) —
             aucune classe de couleur posée sur l'icône ne peut l'emporter,
             jamais de patch vendor. Repris le même patron déjà en place
             sur la page Notifications complète (notifications-index.blade.php) :
             un rond de couleur pleine, construit nous-mêmes DANS le
             contenu de l'item plutôt que via sa prop `icon`. --}}
        @php
            $classesParCouleurCloche = [
                'danger' => 'bg-brand-danger',
                'warning' => 'bg-brand-warning',
                'success' => 'bg-brand-success',
                'navy' => 'bg-brand-navy',
                'blue' => 'bg-brand-accent',
                'neutre' => 'bg-brand-disabled-text',
            ];
        @endphp
        @forelse ($this->notifications as $notification)
            {{-- 2026-10-12, retour utilisateur direct ("am talking about the
                 highlight") — la première version (teinte de fond seule,
                 40% d'opacité) était trop discrète pour distinguer lu/non
                 lu au premier coup d'œil. Repris le même repère fort déjà
                 en place sur la page Notifications complète : une barre
                 verticale pleine couleur à gauche (border-s-4), transparente
                 une fois lue plutôt qu'absente — l'épaisseur de la ligne ne
                 bouge jamais, seule sa couleur change. --}}
            <flux:menu.item
                wire:click="marquerCommeLue('{{ $notification->id }}')"
                :href="$notification->data['lien']"
                wire:navigate
                @class([
                    'border-s-4',
                    '!border-brand-accent !bg-brand-accent-pale/40 dark:!bg-brand-accent/10' => ! $notification->read_at,
                    'border-transparent' => $notification->read_at,
                ])
            >
                <div class="flex min-w-0 items-center gap-2">
                    <div class="flex size-7 shrink-0 items-center justify-center rounded-full text-white {{ $classesParCouleurCloche[$notification->data['couleur'] ?? 'neutre'] ?? $classesParCouleurCloche['neutre'] }}">
                        <flux:icon :icon="$notification->data['icone'] ?? 'bell'" variant="micro" class="size-3.5" />
                    </div>
                    <div class="flex min-w-0 flex-col">
                        <span @class(['truncate text-sm', 'font-semibold' => ! $notification->read_at])>
                            {{ $notification->data['libelle'] }}
                        </span>
                        @if ($notification->data['sous_libelle'] ?? null)
                            <span class="truncate text-xs text-zinc-400">{{ $notification->data['sous_libelle'] }}</span>
                        @endif
                        <span class="text-xs text-zinc-400">{{ $notification->created_at->diffForHumans() }}</span>
                    </div>
                </div>
            </flux:menu.item>
        @empty
            <div class="flex flex-col items-center gap-2 px-3 py-6 text-center">
                <flux:icon.bell class="size-6 text-brand-text-muted" />
                <flux:text class="text-zinc-500">{{ __('Aucune notification pour l\'instant.') }}</flux:text>
            </div>
        @endforelse

        @if ($this->notifications->isNotEmpty())
            <flux:menu.separator />
            <flux:menu.item :href="route('notifications.index')" wire:navigate>
                {{ __('Voir toutes les notifications') }}
            </flux:menu.item>
        @endif
    </flux:menu>
</flux:dropdown>
</div>
