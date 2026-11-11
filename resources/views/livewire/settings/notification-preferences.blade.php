{{-- 2026-11-10 — page refaite (« re do it ») : un interrupteur par canal qui
     montre le réglage appliqué et s'enregistre aussitôt ; colonnes alignées
     sur écran large, libellé sur chaque interrupteur au téléphone. --}}
<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Notifications') }}</flux:heading>

    {{-- 2026-11-10 (« only the admin can see and modify it ») — interrupteurs
         E-mail / Application réservés au privilège « Choisir ses canaux de
         notification » ; « Rappels de courrier en retard » pour tous. --}}
    <x-settings.layout :heading="__('Notifications')" :subheading="$this->peutChoisirCanaux ? __('Choisissez comment vous êtes averti pour chaque type d\'activité. Chaque changement est enregistré aussitôt.') : __('Choisissez à quel rythme les rappels de courrier en retard vous sont renvoyés. Les canaux (e-mail, application) sont réglés par l\'administrateur.')" :large="true">
        <div class="max-w-3xl space-y-6">
            @if ($this->peutChoisirCanaux)
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
                    @if ($this->personnalisees !== [] || $relanceHeures !== null)
                        <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="reinitialiser" class="shrink-0">{{ __('Rétablir les réglages de l\'administrateur') }}</flux:button>
                    @endif
                </div>
            </div>
            @endif

            <div class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center gap-2">
                    <flux:icon.clock class="size-4 text-brand-accent" />
                    <flux:heading level="3">{{ __('Rappels de courrier en retard') }}</flux:heading>
                </div>
                <flux:text class="mt-1 text-sm">{{ __('Tant qu\'un courrier qui vous concerne reste en retard, le rappel vous est renvoyé à cet intervalle.') }}</flux:text>

                @php($relance = $this->relanceAppliquee())
                {{-- 2026-11-11 (« give a good design … inside a modal ») — résumé
                     sur la page, minuteur dans une modale. --}}
                <div class="mt-4 flex flex-col gap-4 rounded-xl bg-zinc-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:bg-zinc-800/60">
                    <div class="flex items-center gap-3">
                        <div class="flex size-12 shrink-0 items-center justify-center rounded-full bg-brand-accent/10 text-brand-accent">
                            <flux:icon.arrow-path class="size-5" />
                        </div>
                        <div class="min-w-0">
                            <flux:text class="text-xs uppercase tracking-wide">{{ __('Intervalle des rappels') }}</flux:text>
                            <div class="text-2xl font-semibold leading-tight text-zinc-900 dark:text-white">{{ $this->libelleIntervalle($relance) }}</div>
                            <div class="mt-1">
                                @if ($relanceHeures !== null)
                                    <flux:badge size="sm" color="blue">{{ __('Personnalisé — administrateur : :duree', ['duree' => $this->libelleIntervalle($this->relanceHeuresParDefaut())]) }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Réglage de l\'administrateur') }}</flux:badge>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if ($relanceHeures !== null)
                            <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="relanceParDefaut">{{ __('Revenir') }}</flux:button>
                        @endif
                        <flux:modal.trigger name="minuteur-relance">
                            <flux:button size="sm" variant="primary" icon="pencil-square">{{ __('Modifier') }}</flux:button>
                        </flux:modal.trigger>
                    </div>
                </div>

                {{-- Minuteur à deux roues, comme sur un téléphone (« set it them
                     self like in the clock phone » ; composant Alpine
                     roueIntervalle, resources/js/app.js) : faire tourner, toucher
                     une valeur ou flèches haut/bas ; rien n'est enregistré avant
                     « Enregistrer ». wire:ignore : les roues gardent leur
                     position entre deux rendus ; elles repartent de l'intervalle
                     appliqué à chaque ouverture. --}}
                <flux:modal name="minuteur-relance" class="w-full max-w-md">
                    <div wire:ignore class="space-y-5"
                        x-data="roueIntervalle({{ intdiv($relance, 24) }}, {{ $relance % 24 }}, {{ \Illuminate\Support\Js::from([
                            'jour' => trans_choice(':count jour|:count jours', 1, ['count' => ':count']),
                            'jours' => trans_choice(':count jour|:count jours', 2, ['count' => ':count']),
                            'heure' => trans_choice(':count heure|:count heures', 1, ['count' => ':count']),
                            'heures' => trans_choice(':count heure|:count heures', 2, ['count' => ':count']),
                        ]) }})"
                        x-on:relance-replacee.window="replacer($event.detail)">
                        <div>
                            <flux:heading size="lg">{{ __('Intervalle des rappels') }}</flux:heading>
                            <flux:text class="mt-1">{{ __('Faites tourner les roues, comme le minuteur de votre téléphone : de 1 heure à 10 jours.') }}</flux:text>
                        </div>

                        <div class="relative mx-auto flex w-full max-w-xs gap-1 rounded-2xl bg-zinc-50 px-2 dark:bg-zinc-800/60">
                            <div class="pointer-events-none absolute inset-x-2 top-1/2 h-12 -translate-y-1/2 rounded-xl bg-white shadow-sm ring-1 ring-brand-border dark:bg-zinc-700 dark:ring-zinc-600" aria-hidden="true"></div>
                            @foreach (['jours' => [10, __('jours')], 'heures' => [23, __('heures')]] as $roue => [$max, $unite])
                                <div class="relative flex-1">
                                    <div x-ref="{{ $roue }}" role="spinbutton" tabindex="0" aria-label="{{ ucfirst($unite) }}" aria-valuemin="0" aria-valuemax="{{ $max }}" x-bind:aria-valuenow="{{ $roue }}"
                                        x-on:scroll.debounce.120ms="lire('{{ $roue }}')" x-on:keydown.arrow-up.prevent="pas('{{ $roue }}', -1)" x-on:keydown.arrow-down.prevent="pas('{{ $roue }}', 1)" x-on:keydown.enter.prevent="enregistrer()"
                                        class="h-60 w-full snap-y snap-mandatory overflow-y-scroll rounded-xl py-[96px] [scrollbar-width:none] [mask-image:linear-gradient(to_bottom,transparent,black_30%,black_70%,transparent)] focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent [&::-webkit-scrollbar]:hidden">
                                        @foreach (range(0, $max) as $n)
                                            <div x-on:click="fixer('{{ $roue }}', {{ $n }}, true)" class="flex h-12 cursor-pointer snap-center items-center justify-end pr-16 text-3xl tabular-nums text-zinc-900 dark:text-white">{{ $n }}</div>
                                        @endforeach
                                    </div>
                                    <span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-sm font-medium text-zinc-500 dark:text-zinc-400" aria-hidden="true">{{ $unite }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="text-center">
                            <flux:text class="text-xs uppercase tracking-wide">{{ __('Intervalle choisi') }}</flux:text>
                            <div class="text-lg font-semibold text-zinc-900 dark:text-white" x-text="libelle" aria-live="polite"></div>
                        </div>

                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Annuler') }}</flux:button>
                            </flux:modal.close>
                            <flux:button variant="primary" icon="check" x-on:click="enregistrer()">{{ __('Enregistrer') }}</flux:button>
                        </div>
                    </div>
                    {{-- Hors wire:ignore pour se mettre à jour (bornes déjà tenues
                         côté navigateur : seulement si la requête est forgée). --}}
                    <flux:error name="relance" class="mt-3" />
                </flux:modal>

                @if (! $preferences['alertes_sla']['email'] && ! $preferences['alertes_sla']['application'])
                    <flux:callout variant="warning" icon="exclamation-triangle" class="mt-4">
                        <flux:callout.text>{{ $this->peutChoisirCanaux ? __('Les alertes de délai sont désactivées pour vous sur les deux canaux : vous ne recevrez aucun rappel.') : __('L\'administrateur a désactivé les alertes de délai sur les deux canaux : vous ne recevrez aucun rappel.') }}</flux:callout.text>
                    </flux:callout>
                @endif
            </div>
        </div>
    </x-settings.layout>
</section>
