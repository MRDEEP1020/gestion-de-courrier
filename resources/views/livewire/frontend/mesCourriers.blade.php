<section class="w-full max-w-5xl">
    <div class="flex items-center gap-3">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
            <flux:icon.paper-airplane class="size-5" />
        </div>
        <div>
            <flux:heading level="1">{{ __('Mes courriers') }}</flux:heading>
            <flux:subheading>{{ __('Les courriers entrants que vous avez enregistrés, par étape du transfert vers le DGA/ADJ DGA.') }}</flux:subheading>
        </div>
    </div>

    {{-- 3 onglets = les 3 sous-statuts du document (specifications-modules-GEC.md,
         Module 4) — "Transféré" correspond au statut 'enregistre' déjà
         existant (voir DECISIONS.md, synchronisation SRS-GEC.pdf). --}}
    <div class="mt-6 flex flex-wrap gap-2">
        <flux:button size="sm" :variant="$onglet === 'en_attente_de_transfert' ? 'primary' : 'ghost'" wire:click="changerOnglet('en_attente_de_transfert')">
            {{ __('En attente de transfert') }}
        </flux:button>
        <flux:button size="sm" :variant="$onglet === 'en_cours_de_transfert' ? 'primary' : 'ghost'" wire:click="changerOnglet('en_cours_de_transfert')">
            {{ __('En cours de transfert') }}
        </flux:button>
        <flux:button size="sm" :variant="$onglet === 'enregistre' ? 'primary' : 'ghost'" wire:click="changerOnglet('enregistre')">
            {{ __('Transféré') }}
        </flux:button>
    </div>

    <flux:separator class="mt-6" />

    @if ($this->courriers->isEmpty())
        <flux:text class="mt-6 text-zinc-500">
            @if ($onglet === 'en_attente_de_transfert')
                {{ __('Aucun courrier en attente de transfert.') }}
            @elseif ($onglet === 'en_cours_de_transfert')
                {{ __('Aucun courrier en cours de transfert.') }}
            @else
                {{ __('Aucun courrier transféré pour l\'instant.') }}
            @endif
        </flux:text>
    @else
        {{-- Module 1/4 — transfert en masse (demande explicite de
             l'utilisateur, 2026-09-15 : "buttons for select bulk and
             transfere to a supervisor") : uniquement sur l'onglet "En
             attente de transfert", seul statut où l'action a un sens.
             Toujours dans un <flux:checkbox.group> (jamais wire:model
             direct sur une case isolée, voir notes internes). --}}
        {{-- Module 1/4 — la réceptionniste choisit désormais QUI dans une
             modale (2026-09-15, voir DECISIONS.md "Destinataires de
             transfert") plutôt qu'un envoi générique "au DGA". --}}
        @if ($onglet === 'en_attente_de_transfert')
            <div class="mt-4 flex items-center gap-2">
                <flux:modal.trigger name="transferer-selection-modal">
                    <flux:button size="sm" variant="primary" icon="arrow-up-tray" :disabled="empty($selection)">
                        {{ __('Transférer la sélection') }} ({{ count($selection) }})
                    </flux:button>
                </flux:modal.trigger>
            </div>

            <flux:modal name="transferer-selection-modal" class="w-full max-w-sm">
                <form wire:submit="transfererSelection" class="space-y-4">
                    <flux:heading size="lg">{{ __('Transférer à') }}</flux:heading>
                    <flux:text class="text-zinc-500">{{ __(':n courrier(s) sélectionné(s).', ['n' => count($selection)]) }}</flux:text>

                    @if ($this->destinatairesTransfert->isEmpty())
                        <flux:text class="text-zinc-500">{{ __('Aucun destinataire autorisé pour votre compte. Contactez un administrateur.') }}</flux:text>
                    @else
                        <flux:radio.group wire:model="destinataireChoisi">
                            @foreach ($this->destinatairesTransfert as $destinataire)
                                <flux:radio value="{{ $destinataire->id }}" label="{{ $destinataire->name }}" />
                            @endforeach
                        </flux:radio.group>
                        @error('destinataireChoisi') <flux:text class="mt-1 text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Annuler') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" :disabled="$this->destinatairesTransfert->isEmpty()">{{ __('Confirmer le transfert') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif

        <flux:heading level="2" class="mt-4 text-base">{{ __('Liste des courriers') }} ({{ $this->courriers->total() }})</flux:heading>

        <flux:checkbox.group wire:model.live="selection">
            <div class="mt-3 overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <table class="w-full text-sm">
                    <thead class="bg-brand-table-header text-left text-sm font-medium text-zinc-600 dark:bg-zinc-800">
                        <tr>
                            @if ($onglet === 'en_attente_de_transfert')
                                <th class="py-3 pl-4 pr-3"><span class="sr-only">{{ __('Sélection') }}</span></th>
                            @endif
                            <th class="py-3 pr-3">{{ __('Référence') }}</th>
                            <th class="py-3 pr-3">{{ __('Objet') }}</th>
                            <th class="py-3 pr-3">{{ __('Type') }}</th>
                            <th class="py-3 pr-3">{{ __('Service') }}</th>
                            <th class="py-3 pr-4">{{ __('Date') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->courriers as $courrier)
                            <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800" onclick="window.location='{{ route('courriers.show', $courrier->id) }}'">
                                @if ($onglet === 'en_attente_de_transfert')
                                    <td class="py-3 pl-4 pr-3" onclick="event.stopPropagation()">
                                        @can('transferer', $courrier)
                                            <flux:checkbox value="{{ $courrier->id }}" :aria-label="__('Sélectionner :ref', ['ref' => $courrier->numero_reference])" />
                                        @endcan
                                    </td>
                                @endif
                                <td class="py-3 pr-3 font-medium">
                                    <flux:link :href="route('courriers.show', $courrier->id)" wire:navigate class="text-brand-accent">{{ $courrier->numero_reference }}</flux:link>
                                </td>
                                <td class="py-3 pr-3">{{ $courrier->objet }}</td>
                                <td class="py-3 pr-3 text-zinc-500">{{ $courrier->type_document }}</td>
                                {{-- service null-safe : un courrier en attente/en cours
                                     de transfert n'a pas encore de service (2026-09-15,
                                     voir DECISIONS.md, synchronisation SRS-GEC.pdf). --}}
                                <td class="py-3 pr-3 text-zinc-500">{{ $courrier->service?->nom ?? __('—') }}</td>
                                <td class="py-3 pr-4 text-zinc-500">{{ $courrier->date_mouvement->format('d/m/Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </flux:checkbox.group>

        <div class="mt-4">
            {{ $this->courriers->links() }}
        </div>
    @endif
</section>
