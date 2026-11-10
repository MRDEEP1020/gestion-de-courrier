{{-- Modale d'action du circuit de traitement (2026-10-28, « all button in the
     circuit de traitement should have a modal … and the modal should be well
     design ») — même présentation pour chaque action de l'onglet Circuit :
     pastille d'icône colorée selon la nature de l'action, titre, rappel du
     courrier, explication et champs (slot), « Ce qui va se passer », pied
     Annuler / confirmation. Le formulaire appelle la méthode Livewire $action ;
     ShowCourrier::executer() ferme la modale après l'action. --}}
@props([
    'nom',
    'icone',
    'titre',
    'courrier',
    'action',
    'ton' => 'accent',
    'libelle' => null,
    'etapes' => [],
])
@php
    $pastille = match ($ton) {
        'succes' => 'bg-brand-success-light text-brand-success-dark',
        'attention' => 'bg-brand-warning-light text-brand-warning-dark',
        'danger' => 'bg-brand-danger-light text-brand-danger-dark',
        'navy' => 'bg-brand-navy/10 text-brand-navy dark:bg-zinc-800 dark:text-zinc-200',
        default => 'bg-brand-accent-pale text-brand-accent',
    };
    $rappel = $courrier->expediteur_organisation ?: $courrier->expediteur_nom ?: $courrier->objet;
@endphp
<flux:modal :name="$nom" class="w-full max-w-lg">
    <form wire:submit="{{ $action }}" class="space-y-5">
        <div class="flex items-start gap-4">
            <div class="flex size-12 shrink-0 items-center justify-center rounded-full {{ $pastille }}">
                <flux:icon :icon="$icone" class="size-6" />
            </div>
            <div class="min-w-0 pt-0.5">
                <flux:heading size="lg">{{ $titre }}</flux:heading>
                <div class="mt-1.5 flex min-w-0 items-center gap-2 text-sm">
                    <span class="shrink-0 rounded-md bg-zinc-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $courrier->numero_reference }}</span>
                    @if ($rappel)
                        <span class="truncate text-zinc-500" title="{{ $rappel }}">{{ $rappel }}</span>
                    @endif
                </div>
            </div>
        </div>

        @if ($slot->isNotEmpty())
            <div class="space-y-4 text-sm">
                {{ $slot }}
            </div>
        @endif

        @if ($etapes)
            <div class="rounded-xl border border-brand-border bg-brand-surface-soft p-4 dark:border-zinc-700 dark:bg-zinc-800/60">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Ce qui va se passer') }}</div>
                <ul class="space-y-1.5 text-sm">
                    @foreach ($etapes as $etape)
                        <li class="flex items-start gap-2">
                            <flux:icon.check-circle class="mt-0.5 size-4 shrink-0 text-brand-success" />
                            <span>{{ $etape }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-col-reverse gap-2 border-t border-brand-border pt-4 sm:flex-row sm:justify-end dark:border-zinc-700">
            <flux:modal.close>
                <flux:button variant="ghost" class="w-full sm:w-auto">{{ __('Annuler') }}</flux:button>
            </flux:modal.close>
            <flux:button type="submit" :variant="$ton === 'danger' ? 'danger' : 'primary'" :icon="$icone" class="w-full sm:w-auto">{{ $libelle ?? $titre }}</flux:button>
        </div>
    </form>
</flux:modal>
