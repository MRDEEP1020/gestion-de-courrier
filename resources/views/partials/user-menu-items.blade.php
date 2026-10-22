{{-- Contenu du menu profil, partagé entre la navbar mobile (flux:header
     lg:hidden) et la navbar desktop (flux:header hidden lg:flex) — voir
     DECISIONS.md "Navigation (navbar + sidebar)". Extrait le 2026-09-16
     pour éviter de dupliquer ce bloc une troisième fois. --}}
<flux:menu.radio.group>
    <div class="p-0 text-sm font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />

            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>
    </div>
</flux:menu.radio.group>

<flux:menu.separator />
<flux:menu.radio>
    <flux:menu.item :href="route('langue.changer', 'fr')">
        {{ __('Fr') }}
    </flux:menu.item>
    <flux:menu.item :href="route('langue.changer', 'en')">
        {{ __('Eng') }}
    </flux:menu.item>
</flux:menu.radio>

<flux:menu.radio.group>
    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
        {{ __('Settings') }}
    </flux:menu.item>
</flux:menu.radio.group>

<flux:menu.separator />

<form method="POST" action="{{ route('logout') }}" class="w-full">
    @csrf
    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer"
        data-test="logout-button">
        {{ __('Log out') }}
    </flux:menu.item>
</form>
