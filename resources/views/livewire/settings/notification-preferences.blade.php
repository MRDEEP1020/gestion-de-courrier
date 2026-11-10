{{-- 2026-11-10 — page refaite (« re do it ») : un interrupteur par canal qui
     montre le réglage appliqué et s'enregistre aussitôt ; colonnes alignées
     sur écran large, libellé sur chaque interrupteur au téléphone. --}}
<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notifications') }}</flux:heading>

    <x-settings.layout :heading="__('Notifications')" :subheading="__('Choisissez comment vous êtes averti pour chaque type d\'activité. Chaque changement est enregistré aussitôt.')" :large="true">
        <div class="max-w-3xl space-y-6">
            <div class="overflow-hidden rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="hidden grid-cols-[minmax(0,1fr)_7rem_7rem] items-center gap-4 border-b border-brand-border bg-zinc-50 px-5 py-3 text-sm font-medium text-zinc-900 sm:grid dark:border-zinc-700 dark:bg-zinc-800/60 dark:text-white">
                    <span>{{ __('Activité') }}</span>
                    <span class="flex items-center justify-center gap-1.5"><flux:icon.envelope class="size-4" />{{ __('E-mail') }}</span>
                    <span class="flex items-center justify-center gap-1.5"><flux:icon.bell class="size-4" />{{ __('Application') }}</span>
                </div>

                <div class="divide-y divide-brand-border dark:divide-zinc-700">
                    @foreach (\App\Services\NotificationPreferenceService::CATEGORIES as $categorie => $details)
                        <div wire:key="preference-{{ $categorie }}" class="grid gap-3 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_7rem_7rem] sm:items-center sm:gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:text class="font-medium text-zinc-900 dark:text-white">{{ __($details['label']) }}</flux:text>
                                    @if (in_array($categorie, $this->personnalisees, true))
                                        <flux:badge size="sm" color="blue">{{ __('Personnalisé') }}</flux:badge>
                                    @endif
                                </div>
                                <flux:text class="mt-0.5 text-sm">{{ __($details['description']) }}</flux:text>
                            </div>

                            @foreach (\App\Services\NotificationPreferenceService::CANAUX as $canal)
                                @php($nomCanal = $canal === 'email' ? __('E-mail') : __('Application'))
                                <div class="flex items-center justify-between gap-3 rounded-lg bg-zinc-50 px-3 py-2 sm:justify-center sm:bg-transparent sm:p-0 dark:bg-zinc-800/60 sm:dark:bg-transparent">
                                    <span class="flex items-center gap-1.5 text-sm sm:hidden" aria-hidden="true">
                                        <flux:icon :icon="$canal === 'email' ? 'envelope' : 'bell'" class="size-4" />{{ $nomCanal }}
                                    </span>
                                    <flux:switch wire:model.live="preferences.{{ $categorie }}.{{ $canal }}" aria-label="{{ $nomCanal }} — {{ __($details['label']) }}" />
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col gap-3 border-t border-brand-border bg-zinc-50 px-5 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700 dark:bg-zinc-800/60">
                    <flux:text class="text-sm">{{ __('« Application » regroupe la cloche et les alertes qui s\'affichent en direct. « Personnalisé » : différent du réglage de l\'administrateur.') }}</flux:text>
                    @if ($this->personnalisees !== [] || $frequenceRelanceSla !== 'default')
                        <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="reinitialiser" class="shrink-0">{{ __('Rétablir les réglages de l\'administrateur') }}</flux:button>
                    @endif
                </div>
            </div>

            <div class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-2">
                    <flux:icon.clock class="size-4 text-brand-accent" />
                    <flux:heading level="3">{{ __('Rappels de courrier en retard') }}</flux:heading>
                </div>
                <flux:text class="mt-1 text-sm">{{ __('Tant qu\'un courrier qui vous concerne reste en retard, le rappel vous est renvoyé à cet intervalle.') }}</flux:text>

                @php($joursAdministrateur = $this->frequenceRelanceSlaParDefaut())
                <div class="mt-4 sm:max-w-sm">
                    <flux:select wire:model.live="frequenceRelanceSla" :label="__('Répéter le rappel')">
                        <flux:select.option value="default">{{ __('Par défaut : :frequence', ['frequence' => mb_strtolower(trans_choice('Tous les jours|Tous les :count jours', $joursAdministrateur, ['count' => $joursAdministrateur]))]) }}</flux:select.option>
                        @foreach (range(1, 10) as $jours)
                            <flux:select.option value="{{ $jours }}">{{ trans_choice('Tous les jours|Tous les :count jours', $jours, ['count' => $jours]) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text class="mt-2 text-sm">{{ __('« Par défaut » : l\'intervalle choisi par l\'administrateur.') }}</flux:text>
                </div>

                @if (! $preferences['alertes_sla']['email'] && ! $preferences['alertes_sla']['application'])
                    <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4">
                        <flux:callout.text>{{ __('Les alertes de délai sont désactivées pour vous sur les deux canaux : vous ne recevrez aucun rappel.') }}</flux:callout.text>
                    </flux:callout>
                @endif
            </div>
        </div>
    </x-settings.layout>
</section>
