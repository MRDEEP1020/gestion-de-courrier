<section class="w-full max-w-5xl">
    <div class="flex items-center gap-3">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
            <flux:icon.archive-box class="size-5" />
        </div>
        <div>
            <flux:heading level="1">{{ __('Courriers enregistrés') }}</flux:heading>
            <flux:subheading>{{ __('Les courriers entrants déjà enregistrés dans le système, par étape du transfert vers leur service.') }}</flux:subheading>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap gap-2">
        <flux:button size="sm" :variant="$onglet === 'en_attente_de_transfert' ? 'primary' : 'ghost'" wire:click="changerOnglet('en_attente_de_transfert')">
            {{ __('En attente de transfert') }}
        </flux:button>
        <flux:button size="sm" :variant="$onglet === 'en_cours_de_transfert' ? 'primary' : 'ghost'" wire:click="changerOnglet('en_cours_de_transfert')">
            {{ __('En attente d\'acceptation') }}
        </flux:button>
        <flux:button size="sm" :variant="$onglet === 'enregistre' ? 'primary' : 'ghost'" wire:click="changerOnglet('enregistre')">
            {{ __('Acceptés — à affecter') }}
        </flux:button>
        {{-- 2026-09-24 — suivre les courriers transférés à toutes leurs
             étapes, en lecture seule (voir CourriersEnregistres::ONGLETS). --}}
        <flux:button size="sm" icon="eye" :variant="$onglet === 'suivi' ? 'primary' : 'ghost'" wire:click="changerOnglet('suivi')">
            {{ __('Suivi') }}
        </flux:button>
    </div>

    <flux:separator class="mt-6" />

    @if ($this->courriers->isEmpty())
        <flux:text class="mt-6 text-zinc-500">
            @if ($onglet === 'en_attente_de_transfert')
                {{ __('Aucun courrier en attente de transfert.') }}
            @elseif ($onglet === 'en_cours_de_transfert')
                {{ __('Aucun courrier en attente d\'acceptation.') }}
            @elseif ($onglet === 'suivi')
                {{ __('Aucun courrier à suivre pour l\'instant.') }}
            @else
                {{ __('Aucun courrier accepté en attente d\'affectation.') }}
            @endif
        </flux:text>
    @else
        <flux:heading level="2" class="mt-4 text-base">{{ __('Liste des courriers') }} ({{ $this->courriers->total() }})</flux:heading>

        {{-- Format de tableau commun (2026-10-29, « same format on all the tables
             of the project ») — celui de « Tous les courriers » (voir le
             commentaire de courrierList.blade.php) : en-têtes en petites
             capitales soulignés d'un trait navy, lignes sur
             UNE seule ligne (texte trop long coupé, lisible en entier au
             survol), 16 px en haut et en bas de chaque cellule ; les colonnes
             de texte se partagent la place (largeurs en % totalisant 100 %),
             les autres (w-px) gardent leur contenu. Statut : le badge partagé
             <x-statut-badge> (mêmes couleurs que partout ailleurs). --}}
        <div class="@container mt-3 overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <table class="w-full text-sm">
                <thead class="border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400">
                    <tr>
                        <th class="w-px whitespace-nowrap py-3 pr-3 @min-[60rem]:pr-5 pl-4">{{ __('Référence') }}</th>
                        <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Objet') }}</th>
                        <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Type') }}</th>
                        <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Service') }}</th>
                        @if ($onglet === 'suivi')
                            <th class="w-px py-3 pr-3 @min-[60rem]:pr-5">{{ __('Statut') }}</th>
                            <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Affecté à') }}</th>
                            <th class="w-px whitespace-nowrap py-3 pr-4">{{ __('Dernier mouvement') }}</th>
                        @else
                            <th class="w-px whitespace-nowrap py-3 pr-4">{{ __('Date de réception') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->courriers as $courrier)
                        @php
                            $suivi = $onglet === 'suivi';
                            $serviceLigne = $courrier->service?->nom ?? __('—');
                            $affecteLigne = $courrier->affectationCourante?->collaborateur?->name ?? __('—');
                        @endphp
                        <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800" onclick="window.location='{{ route('courriers.show', $courrier->id) }}'">
                            <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5 pl-4 font-medium">
                                <span class="block max-w-36 truncate" title="{{ $courrier->numero_reference }}"><flux:link :href="route('courriers.show', $courrier->id)" wire:navigate class="text-brand-accent">{{ $courrier->numero_reference }}</flux:link></span>
                            </td>
                            <td class="{{ $suivi ? 'w-[40%]' : 'w-[56%]' }} min-w-32 py-4 pr-3 @min-[60rem]:pr-5">
                                <div class="w-0 min-w-full truncate" title="{{ $courrier->objet }}">{{ $courrier->objet }}</div>
                            </td>
                            <td class="{{ $suivi ? 'w-[14%]' : 'w-[18%]' }} min-w-20 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">
                                <div class="w-0 min-w-full truncate" title="{{ $courrier->type_document }}">{{ $courrier->type_document }}</div>
                            </td>
                            {{-- service null-safe : un courrier à transférer n'a pas encore de service. --}}
                            <td class="{{ $suivi ? 'w-[22%]' : 'w-[26%]' }} min-w-20 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">
                                <div class="w-0 min-w-full truncate" title="{{ $serviceLigne }}">{{ $serviceLigne }}</div>
                            </td>
                            @if ($suivi)
                                <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5">
                                    <x-statut-badge :statut="$courrier->statut" class="rounded-md! px-2! font-semibold!" />
                                </td>
                                <td class="w-[24%] min-w-20 py-4 pr-3 @min-[60rem]:pr-5 text-zinc-500">
                                    <div class="w-0 min-w-full truncate" title="{{ $affecteLigne }}">{{ $affecteLigne }}</div>
                                </td>
                                <td class="whitespace-nowrap py-4 pr-4 text-zinc-500">{{ $courrier->updated_at->format('d/m/Y H:i') }}</td>
                            @else
                                <td class="whitespace-nowrap py-4 pr-4 text-zinc-500">{{ $courrier->date_mouvement->format('d/m/Y') }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $this->courriers->links() }}
        </div>
    @endif
</section>
