{{-- Tableau d'archives de Sécurité & Audit (2026-10-30) — échues ou sous gel
     juridique ; actions réservées à archives.gerer_conservation. --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400">
            <tr>
                <th class="py-3 pl-4 pr-3">{{ __('Référence') }}</th>
                <th class="py-3 pr-3">{{ __('Objet') }}</th>
                <th class="py-3 pr-3">{{ __('Type') }}</th>
                <th class="whitespace-nowrap py-3 pr-3">{{ __('Archivé le') }}</th>
                <th class="whitespace-nowrap py-3 pr-3">{{ __('Conserver jusqu\'au') }}</th>
                <th class="relative w-px py-3 pr-4"><span class="sr-only">{{ __('Actions') }}</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($archives as $archive)
                <tr wire:key="archive-{{ $gelees ? 'gel' : 'echue' }}-{{ $archive->id }}">
                    <td class="whitespace-nowrap py-4 pl-4 pr-3">
                        <flux:link :href="route('courriers.show', $archive->id)" wire:navigate>{{ $archive->numero_reference }}</flux:link>
                    </td>
                    <td class="max-w-72 truncate py-4 pr-3" title="{{ $archive->objet }}">{{ $archive->objet }}</td>
                    <td class="py-4 pr-3">{{ $archive->type_document }}</td>
                    <td class="whitespace-nowrap py-4 pr-3 tabular-nums">{{ $archive->archive_le?->format('d/m/Y') ?? '—' }}</td>
                    <td class="whitespace-nowrap py-4 pr-3 tabular-nums">{{ $archive->conserver_jusqua?->format('d/m/Y') ?? '—' }}</td>
                    <td class="py-4 pr-4 text-center">
                        @can('gererConservation', App\Models\Courrier::class)
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" square :aria-label="__('Actions')" />
                                <flux:menu>
                                    <flux:menu.item icon="clock" wire:click="ouvrirAction({{ $archive->id }}, 'prolonger')">{{ __('Prolonger') }}</flux:menu.item>
                                    @if ($gelees)
                                        <flux:menu.item icon="lock-open" wire:click="ouvrirAction({{ $archive->id }}, 'degeler')">{{ __('Lever le gel juridique') }}</flux:menu.item>
                                    @else
                                        <flux:menu.item icon="lock-closed" wire:click="ouvrirAction({{ $archive->id }}, 'geler')">{{ __('Placer sous gel juridique') }}</flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="ouvrirAction({{ $archive->id }}, 'detruire')">{{ __('Détruire') }}</flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
