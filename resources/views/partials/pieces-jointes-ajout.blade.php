{{-- Module 1 (2026-10-23) — ajout de PLUSIEURS pièces jointes (ex. une
     facture et une lettre du même expéditeur), à l'enregistrement comme à la
     modification. Propriétés et actions fournies par
     App\Concerns\AjoutPiecesJointes ; le choix peut se faire en plusieurs
     fois, chaque fichier reste retirable avant l'enregistrement. --}}
<input type="file" wire:model="nouvellesPiecesJointes" multiple accept=".pdf,.jpg,.jpeg,.png"
    class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:hover:file:bg-zinc-600" />
<div wire:loading wire:target="nouvellesPiecesJointes" class="mt-1 text-sm text-zinc-500">{{ __('Envoi en cours…') }}</div>
<flux:error name="piecesJointes" />

@if (count($piecesJointes) > 0)
    <ul class="mt-2 divide-y divide-brand-border rounded-lg border border-brand-border dark:divide-zinc-700 dark:border-zinc-700">
        @foreach ($piecesJointes as $index => $fichier)
            <li wire:key="piece-jointe-{{ $index }}-{{ $fichier->getFilename() }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                <span class="flex min-w-0 items-center gap-2">
                    <flux:icon.paper-clip class="size-4 shrink-0 text-brand-text-secondary" />
                    <span class="truncate">{{ $fichier->getClientOriginalName() }}</span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    <span class="text-xs text-zinc-500">{{ \Illuminate\Support\Number::fileSize($fichier->getSize()) }}</span>
                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="retirerPieceJointe({{ $index }})" :aria-label="__('Retirer :nom', ['nom' => $fichier->getClientOriginalName()])" />
                </span>
            </li>
        @endforeach
    </ul>
    <flux:text class="mt-1 text-xs text-zinc-500">{{ trans_choice(':n fichier sera joint au courrier.|:n fichiers seront joints au courrier.', count($piecesJointes), ['n' => count($piecesJointes)]) }}</flux:text>
@endif
