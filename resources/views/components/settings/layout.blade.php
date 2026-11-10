<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        {{-- 2026-10-26 — mêmes libellés et icônes que le menu du compte. --}}
        <flux:navlist aria-label="{{ __('Paramètres') }}">
            <flux:navlist.item :href="route('profile.edit')" icon="user-circle" wire:navigate>{{ __('Mon profil') }}</flux:navlist.item>
            <flux:navlist.item :href="route('notifications.edit')" icon="bell" wire:navigate>{{ __('Notifications') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" icon="swatch" wire:navigate>{{ __('Apparence') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        {{-- 2026-10-26 — `large` : page « Mon profil » à deux colonnes
             (formulaire + aperçu du compte, comme Utilisateurs & Accès). --}}
        <div @class(['mt-5 w-full', 'max-w-5xl' => $large ?? false, 'max-w-lg' => ! ($large ?? false)])>
            {{ $slot }}
        </div>
    </div>
</div>
