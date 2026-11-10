<section class="w-full max-w-5xl">
    <div class="flex items-center gap-3">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
            <flux:icon.paper-airplane class="size-5" />
        </div>
        <div>
            <flux:heading level="1">{{ __('Mes courriers') }}</flux:heading>
            <flux:subheading>{{ __('Les courriers entrants que vous avez enregistrés : à transférer, en attente d\'acceptation par le service, puis acceptés (original papier à remettre).') }}</flux:subheading>
        </div>
    </div>

    {{-- 2026-10-23 (voir DECISIONS.md "Transfert direct au service et
         acceptation") — 3 étapes : à transférer (ou refusé, à réorienter),
         en attente d'acceptation par le service, accepté. --}}
    <div class="mt-6 flex flex-wrap gap-2">
        <flux:button size="sm" :variant="$onglet === 'en_attente_de_transfert' ? 'primary' : 'ghost'" wire:click="changerOnglet('en_attente_de_transfert')">
            {{ __('À transférer') }}
        </flux:button>
        <flux:button size="sm" :variant="$onglet === 'en_cours_de_transfert' ? 'primary' : 'ghost'" wire:click="changerOnglet('en_cours_de_transfert')">
            {{ __('En attente d\'acceptation') }}
        </flux:button>
        <flux:button size="sm" :variant="$onglet === 'acceptes' ? 'primary' : 'ghost'" wire:click="changerOnglet('acceptes')">
            {{ __('Acceptés') }}
        </flux:button>
    </div>

    {{-- 2026-10-30 (« they have to also see the search and filter inside Mes
         courriers ») — l'agent n'a plus « Tous les courriers » au menu :
         recherche toujours visible, autres filtres repliés (dépliés d'office
         si l'un d'eux est actif), appliqués dans l'onglet courant. --}}
    <div class="mt-4 rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
        x-data="{ filtres: {{ $this->filtresActifs() ? 'true' : 'false' }} }">
        <div class="flex items-center gap-3">
            <div class="flex-1">
                <flux:input wire:model.live.debounce.400ms="recherche" icon="magnifying-glass" clearable
                    :placeholder="__('Rechercher par référence, objet ou expéditeur…')" />
            </div>
            <flux:button variant="ghost" size="sm" icon="adjustments-horizontal" x-on:click="filtres = !filtres">
                {{ __('Filtres') }}
            </flux:button>
        </div>

        <div x-show="filtres" x-cloak>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <flux:select wire:model.live="typeDocument" :label="__('Type de document')">
                    <flux:select.option value="">{{ __('Tous les types') }}</flux:select.option>
                    @foreach (\App\Livewire\Backend\Forms\CourrierForm::typesDocument() as $type)
                        <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="priorite" :label="__('Priorité')">
                    <flux:select.option value="">{{ __('Toutes priorités') }}</flux:select.option>
                    <flux:select.option value="basse">{{ __('Basse') }}</flux:select.option>
                    <flux:select.option value="normale">{{ __('Normale') }}</flux:select.option>
                    <flux:select.option value="haute">{{ __('Haute') }}</flux:select.option>
                    <flux:select.option value="urgente">{{ __('Urgente') }}</flux:select.option>
                </flux:select>

                <flux:select wire:model.live="serviceId" :label="__('Service')">
                    <flux:select.option value="">{{ __('Tous les services') }}</flux:select.option>
                    @foreach ($this->servicesDeMesCourriers as $service)
                        <flux:select.option value="{{ $service->id }}">{{ $service->nom }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="periode" :label="__('Période')">
                    <flux:select.option value="">{{ __('Toute période') }}</flux:select.option>
                    <flux:select.option value="aujourd_hui">{{ __('Aujourd\'hui') }}</flux:select.option>
                    <flux:select.option value="cette_semaine">{{ __('Cette semaine') }}</flux:select.option>
                    <flux:select.option value="ce_mois">{{ __('Ce mois') }}</flux:select.option>
                    <flux:select.option value="cette_annee">{{ __('Cette année') }}</flux:select.option>
                </flux:select>

                <flux:input type="date" wire:model.live="dateDebut" :label="__('Date de réception — du')" />
                <flux:input type="date" wire:model.live="dateFin" :label="__('Date de réception — au')" />
            </div>

            <div class="mt-4">
                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="reinitialiserFiltres">{{ __('Réinitialiser les filtres') }}</flux:button>
            </div>
        </div>
    </div>

    <flux:separator class="mt-6" />

    @if ($this->courriers->isEmpty())
        <flux:text class="mt-6 text-zinc-500">
            @if ($this->filtresActifs())
                {{ __('Aucun courrier ne correspond à votre recherche dans cet onglet.') }}
            @elseif ($onglet === 'en_attente_de_transfert')
                {{ __('Aucun courrier à transférer.') }}
            @elseif ($onglet === 'en_cours_de_transfert')
                {{ __('Aucun courrier en attente d\'acceptation.') }}
            @else
                {{ __('Aucun courrier accepté pour l\'instant.') }}
            @endif
        </flux:text>
    @else
        {{-- Actions groupées : transfert vers UN service (onglet "À
             transférer"), remise des originaux (onglet "Acceptés"). Toujours
             dans un <flux:checkbox.group> (jamais wire:model direct sur une
             case isolée). --}}
        @if ($onglet === 'en_attente_de_transfert')
            <div class="mt-4 flex items-center gap-2">
                <flux:modal.trigger name="transferer-selection-modal">
                    <flux:button size="sm" variant="primary" icon="arrow-up-tray" :disabled="empty($selection)">
                        {{ __('Transférer la sélection') }} ({{ count($selection) }})
                    </flux:button>
                </flux:modal.trigger>
            </div>

            <flux:modal name="transferer-selection-modal" class="w-full max-w-md">
                <form wire:submit="transfererSelection" class="space-y-4">
                    <div>
                        <flux:heading size="lg">{{ __('Transférer au service') }}</flux:heading>
                        <flux:text class="mt-1 text-zinc-500">{{ __(':n courrier(s) sélectionné(s). Le responsable du service devra les accepter avant que vous lui remettiez les originaux.', ['n' => count($selection)]) }}</flux:text>
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
        @elseif ($onglet === 'acceptes')
            <div class="mt-4 flex items-center gap-2">
                <flux:modal.trigger name="remise-originaux-modal">
                    <flux:button size="sm" variant="primary" icon="document-check" :disabled="empty($selection)">
                        {{ __('Marquer les originaux comme remis') }} ({{ count($selection) }})
                    </flux:button>
                </flux:modal.trigger>
            </div>

            {{-- 2026-10-23 — vraie modale plutôt que la boîte de confirmation
                 du navigateur (retour utilisateur). --}}
            <flux:modal name="remise-originaux-modal" class="w-full max-w-md">
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Originaux papier remis ?') }}</flux:heading>
                    <flux:text class="text-sm">{{ __('Confirmez que les :n original(aux) sélectionné(s) ont bien été remis à leur service. Chaque remise sera enregistrée dans l\'historique du courrier.', ['n' => count($selection)]) }}</flux:text>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Annuler') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" icon="document-check" wire:click="marquerOriginauxRemis">{{ __('Confirmer la remise') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        <flux:heading level="2" class="mt-4 text-base">{{ __('Liste des courriers') }} ({{ $this->courriers->total() }})</flux:heading>

        {{-- Format de tableau commun (2026-10-29, « same format on all the
             tables of the project ») — celui de « Tous les courriers » (voir le
             commentaire de courrierList.blade.php). Lignes sur UNE seule
             ligne : un refus par un service devient un badge à côté de
             l'objet, son motif complet au survol (et sur la fiche). --}}
        @php $sansCase = $onglet === 'en_cours_de_transfert'; @endphp
        <flux:checkbox.group wire:model.live="selection">
            <div class="@container mt-3 overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <table class="w-full text-sm">
                    <thead class="border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400">
                        <tr>
                            @unless ($sansCase)
                                {{-- 2026-10-30 (« there is no select all button ») — coche
                                     toutes les cases de la page (seulement les courriers sur
                                     lesquels l'agent peut agir : seules ces lignes ont une case). --}}
                                <th class="relative w-4 py-3 pl-4 pr-3">
                                    <flux:checkbox.all :aria-label="__('Tout sélectionner')" />
                                </th>
                            @endunless
                            <th class="w-px whitespace-nowrap py-3 pr-3 @min-[60rem]:pr-5 {{ $sansCase ? 'pl-4' : '' }}">{{ __('Référence') }}</th>
                            <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Objet') }}</th>
                            <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Type') }}</th>
                            <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Service') }}</th>
                            @if ($onglet === 'acceptes')
                                <th class="w-px whitespace-nowrap py-3 pr-3 @min-[60rem]:pr-5">{{ __('Décharge') }}</th>
                                <th class="w-px py-3 pr-3 @min-[60rem]:pr-5">{{ __('Original') }}</th>
                            @endif
                            <th class="w-px whitespace-nowrap py-3 pr-4">{{ __('Date de réception') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->courriers as $courrier)
                            @php
                                $transfert = $courrier->dernierTransfert;
                                $serviceLigne = $courrier->service?->nom ?? __('—');
                            @endphp
                            <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800" onclick="window.location='{{ route('courriers.show', $courrier->id) }}'">
                                @if ($onglet === 'en_attente_de_transfert')
                                    <td class="py-4 pl-4 pr-3" onclick="event.stopPropagation()">
                                        @can('transferer', $courrier)
                                            <flux:checkbox value="{{ $courrier->id }}" :aria-label="__('Sélectionner :ref', ['ref' => $courrier->numero_reference])" />
                                        @endcan
                                    </td>
                                @elseif ($onglet === 'acceptes')
                                    <td class="py-4 pl-4 pr-3" onclick="event.stopPropagation()">
                                        @can('marquerOriginalRemis', $courrier)
                                            <flux:checkbox value="{{ $courrier->id }}" :aria-label="__('Sélectionner :ref', ['ref' => $courrier->numero_reference])" />
                                        @endcan
                                    </td>
                                @endif
                                <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5 font-medium {{ $sansCase ? 'pl-4' : '' }}">
                                    <span class="block max-w-36 truncate" title="{{ $courrier->numero_reference }}"><flux:link :href="route('courriers.show', $courrier->id)" wire:navigate class="text-brand-accent">{{ $courrier->numero_reference }}</flux:link></span>
                                </td>
                                <td class="w-[56%] min-w-32 py-4 pr-3 @min-[60rem]:pr-5">
                                    <div class="flex w-0 min-w-full items-center gap-1.5">
                                        <span class="min-w-0 truncate" title="{{ $courrier->objet }}">{{ $courrier->objet }}</span>
                                        {{-- Refusé par un service : à réorienter, avec son motif. --}}
                                        @if ($onglet === 'en_attente_de_transfert' && $transfert?->estRefuse())
                                            <span class="inline-flex shrink-0 items-center whitespace-nowrap rounded-md bg-brand-warning-light px-1.5 py-0.5 text-xs font-semibold text-brand-warning-dark" title="{{ __('Refusé par :service : :motif', ['service' => $transfert->service?->nom, 'motif' => $transfert->motif_refus]) }}">
                                                {{ __('Refusé par :service', ['service' => $transfert->service?->nom]) }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="w-[18%] min-w-20 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">
                                    <div class="w-0 min-w-full truncate" title="{{ $courrier->type_document }}">{{ $courrier->type_document }}</div>
                                </td>
                                {{-- service null-safe : un courrier à transférer n'a pas encore de service. --}}
                                <td class="w-[26%] min-w-20 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">
                                    <div class="w-0 min-w-full truncate" title="{{ $serviceLigne }}">{{ $serviceLigne }}</div>
                                </td>
                                @if ($onglet === 'acceptes')
                                    <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">{{ $transfert?->numero_decharge ?? __('—') }}</td>
                                    <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5">
                                        @if ($transfert?->original_remis_le)
                                            <span class="inline-flex items-center rounded-md bg-brand-success-light px-2 py-0.5 text-xs font-semibold text-brand-success-dark">{{ __('Remis') }}</span>
                                        @else
                                            <span class="inline-flex items-center rounded-md bg-brand-warning-light px-2 py-0.5 text-xs font-semibold text-brand-warning-dark">{{ __('À remettre') }}</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="whitespace-nowrap py-4 pr-4 text-zinc-500">{{ $courrier->date_mouvement->format('d/m/Y') }}</td>
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
