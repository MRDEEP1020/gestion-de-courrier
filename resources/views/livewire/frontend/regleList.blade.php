<section class="w-full max-w-5xl">
    <div class="flex items-center gap-3">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
            <flux:icon.folder class="size-5" />
        </div>
        <div>
            <flux:heading level="1">{{ __('Règles de classement automatique') }}</flux:heading>
            <flux:subheading>{{ __('Module 3 — si l\'un des mots-clés apparaît dans les champs surveillés, le système propose un type, un service et des tags. L\'agent valide ou ignore.') }}</flux:subheading>
        </div>
    </div>

    {{-- 2026-09-23 : regles_classement.voir (lecture seule) / .gerer
         (formulaire + boutons de ligne) / .reanalyser (bouton Réanalyser). --}}
    @php($peutGererRegles = auth()->user()->can('create', App\Models\RegleClassement::class))
    <div class="mt-6 grid gap-8 {{ $peutGererRegles ? 'lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]' : '' }}">
        @if ($peutGererRegles)
        <form wire:submit="enregistrer" class="space-y-5 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading level="2">{{ $regleId ? __('Modifier la règle') : __('Nouvelle règle') }}</flux:heading>

            <flux:input wire:model="nom" :label="__('Nom de la règle')" placeholder="{{ __('Réclamations') }}" required />

            <flux:input wire:model="motsCles" :label="__('Mots-clés déclencheurs (séparés par des virgules)')" placeholder="{{ __('réclamation, sinistre, dommage') }}" required />
            <flux:text class="-mt-3 text-xs text-zinc-500">{{ __('Insensible à la casse et aux accents. Un seul mot-clé présent suffit.') }}</flux:text>

            <flux:checkbox.group wire:model="champs" :label="__('Champs surveillés (aucun coché = tous)')">
                <flux:checkbox value="objet" :label="__('Objet')" />
                <flux:checkbox value="expediteur" :label="__('Expéditeur')" />
                <flux:checkbox value="texte_ocr" :label="__('Texte OCR')" />
            </flux:checkbox.group>

            <flux:separator text="{{ __('Proposition') }}" />

            <flux:input wire:model="typeDocumentPropose" :label="__('Type de document proposé')" placeholder="{{ __('Réclamation') }}" />

            <flux:select wire:model="serviceProposeId" :label="__('Service proposé')" placeholder="{{ __('Aucun') }}">
                <flux:select.option value="">{{ __('— Aucun —') }}</flux:select.option>
                @foreach ($this->services as $service)
                    <flux:select.option value="{{ $service->id }}">{{ $service->nom }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="tags" :label="__('Tags à ajouter (séparés par des virgules)')" placeholder="{{ __('réclamation, urgent') }}" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="number" wire:model="priorite" :label="__('Priorité (petit = évalué en premier)')" min="1" max="1000" required />
                <flux:checkbox wire:model="actif" :label="__('Règle active')" class="mt-7" />
            </div>

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ $regleId ? __('Mettre à jour') : __('Créer la règle') }}</flux:button>
                @if ($regleId)
                    <flux:button variant="ghost" wire:click="nouvelle">{{ __('Annuler') }}</flux:button>
                @endif
            </div>
        </form>
        @endif

        <div>
            <flux:heading level="2">{{ __('Règles existantes') }} ({{ $this->regles->count() }})</flux:heading>

            {{-- 2026-09-23 : toute la carte (compteur + bouton) derrière
                 regles_classement.reanalyser — le compteur n'existe que pour
                 justifier ce bouton, jamais calculé pour un lecteur qui ne
                 peut de toute façon pas relancer l'analyse. --}}
            @can('reanalyser', App\Models\RegleClassement::class)
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-brand-surface-soft px-4 py-3 dark:bg-zinc-800">
                    <flux:text class="text-sm">
                        {{ __(':n courrier(s) sans décision de classement', ['n' => $this->courriersAReanalyser]) }}
                        <span class="text-zinc-400">— {{ __('non classés ou proposition en attente') }}</span>
                    </flux:text>
                    <flux:button
                        size="sm"
                        icon="arrow-path"
                        wire:click="reanalyser"
                        wire:confirm="{{ __('Relancer l\'analyse automatique de ces courriers avec les règles actuelles ?') }}"
                        :disabled="$this->courriersAReanalyser === 0"
                    >{{ __('Réanalyser') }}</flux:button>
                </div>
            @endcan

            @if ($this->regles->isEmpty())
                <flux:text class="mt-3 text-zinc-500">{{ __('Aucune règle pour l\'instant — le classement automatique ne proposera rien tant qu\'il n\'y en a pas.') }}</flux:text>
            @else
                {{-- Format de tableau commun (2026-10-29, « same format on all the
                     tables of the project ») — celui de « Tous les courriers »
                     (voir le commentaire de courrierList.blade.php) : en-têtes en
                     petites capitales soulignés navy, lignes sur
                     UNE seule ligne (mots-clés à côté du nom, propositions
                     jointes, détail complet au survol), 16 px en haut et en bas
                     de chaque cellule ; les trois actions passent dans le menu
                     « … » centré, comme sur les autres tableaux. --}}
                <div class="@container mt-3 overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <table class="w-full text-sm">
                        <thead class="border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400">
                            <tr>
                                <th class="w-px whitespace-nowrap py-3 pr-3 @min-[60rem]:pr-5 pl-4">{{ __('Priorité') }}</th>
                                <th class="py-3 pr-3 @min-[60rem]:pr-5">{{ __('Règle') }}</th>
                                <th class="py-3 {{ $peutGererRegles ? 'pr-3 @min-[60rem]:pr-5' : 'pr-4' }}">{{ __('Propose') }}</th>
                                @if ($peutGererRegles)
                                    <th class="relative w-px py-3 pr-4"><span class="sr-only">{{ __('Actions') }}</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->regles as $regle)
                                @php($champsRegle = $regle->champs ? implode(' + ', $regle->champs) : __('tous les champs'))
                                @php($propositions = array_filter([
                                    $regle->type_document_propose ? __('type').' : '.$regle->type_document_propose : null,
                                    $regle->servicePropose ? __('service').' : '.$regle->servicePropose->nom : null,
                                    $regle->tags ? __('tags').' : '.implode(', ', $regle->tags) : null,
                                ]))
                                <tr wire:key="regle-{{ $regle->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800 {{ $regle->actif ? '' : 'opacity-50' }}">
                                    <td class="whitespace-nowrap py-4 pr-3 @min-[60rem]:pr-5 pl-4">{{ $regle->priorite }}</td>
                                    <td class="w-[55%] min-w-32 py-4 pr-3 @min-[60rem]:pr-5">
                                        <div class="w-0 min-w-full truncate" title="{{ $regle->nom }} — {{ implode(', ', $regle->mots_cles) }} — {{ $champsRegle }}">
                                            <span class="font-medium">{{ $regle->nom }}</span>
                                            <span class="text-zinc-500">· {{ implode(', ', $regle->mots_cles) }}</span>
                                        </div>
                                    </td>
                                    <td class="w-[45%] min-w-28 py-4 {{ $peutGererRegles ? 'pr-3 @min-[60rem]:pr-5' : 'pr-4' }}">
                                        <div class="w-0 min-w-full truncate" title="{{ implode(' · ', $propositions) }}">{{ $propositions ? implode(' · ', $propositions) : __('—') }}</div>
                                    </td>
                                    @if ($peutGererRegles)
                                        <td class="py-4 pr-4 text-center">
                                            <flux:dropdown position="bottom" align="end">
                                                <flux:button variant="ghost" size="xs" icon="ellipsis-horizontal" square :aria-label="__('Actions')" />
                                                <flux:menu>
                                                    <flux:menu.item icon="pencil-square" wire:click="modifier({{ $regle->id }})">{{ __('Modifier') }}</flux:menu.item>
                                                    <flux:menu.item :icon="$regle->actif ? 'pause-circle' : 'play-circle'" wire:click="basculer({{ $regle->id }})">{{ $regle->actif ? __('Désactiver') : __('Activer') }}</flux:menu.item>
                                                    <flux:menu.separator />
                                                    <flux:menu.item icon="trash" variant="danger" wire:click="supprimer({{ $regle->id }})" wire:confirm="{{ __('Supprimer cette règle ?') }}">{{ __('Supprimer') }}</flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
