<section class="w-full max-w-2xl">
    <flux:heading level="1">{{ __('Numériser le document') }}</flux:heading>
    <flux:subheading>{{ $numeroReference }}</flux:subheading>

    @if ($estConfidentiel)
        <flux:callout variant="warning" class="mt-6" icon="lock-closed">
            <flux:callout.heading>{{ __('Courrier confidentiel') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Un courrier confidentiel n\'est jamais scanné.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($dejaValide)
        <flux:callout variant="warning" class="mt-6" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Document déjà validé') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Ce courrier a déjà un document scanné et validé par l\'OCR — il ne peut pas être remplacé.') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="numeriser" class="mt-6 space-y-6">
            <flux:field>
                <flux:label>{{ __('Fichier scanné (PDF, image ou capture depuis un smartphone/tablette)') }}</flux:label>
                <input type="file" wire:model="document" accept=".pdf,.jpg,.jpeg,.png,.tiff"
                    class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:hover:file:bg-zinc-600" />
                <flux:description>{{ __('PDF, JPG, PNG ou TIFF, 20 Mo maximum. Le texte sera extrait automatiquement (OCR, français et anglais).') }}</flux:description>
                <flux:error name="document" />
                <div wire:loading wire:target="document" class="mt-1 text-sm text-zinc-500">{{ __('Envoi en cours…') }}</div>
            </flux:field>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Numériser') }}</flux:button>
                <flux:button :href="route('courriers.show', $courrierId)" wire:navigate variant="ghost">
                    {{ __('Annuler') }}
                </flux:button>
            </div>
        </form>
    @endif
</section>
