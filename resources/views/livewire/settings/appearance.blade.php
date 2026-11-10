{{-- 2026-10-26 — libellés en français (traduits via en.json) ; le mode sombre
     est rendu lisible par le bloc « Mode sombre de l'application » de app.css.
     2026-10-27 — le choix est enregistré sur le compte (Appearance::$apparence)
     et appliqué aussitôt dans la page via l'évènement « apparence-changee ». --}}
<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Apparence') }}</flux:heading>

    <x-settings.layout :heading="__('Apparence')" :subheading="__('Choisissez l\'apparence de l\'application pour votre compte.')">
        <div x-data x-on:apparence-changee.window="$flux.appearance = $event.detail.apparence">
            <flux:radio.group wire:model.live="apparence" variant="segmented" class="max-[380px]:flex-col">
                <flux:radio value="light" icon="sun">{{ __('Clair') }}</flux:radio>
                <flux:radio value="dark" icon="moon">{{ __('Sombre') }}</flux:radio>
                <flux:radio value="system" icon="computer-desktop">{{ __('Système') }}</flux:radio>
            </flux:radio.group>
        </div>
        <flux:text class="mt-3 text-sm">{{ __('Ce choix est enregistré sur votre compte : il vous suit sur tous les postes et ne change pas l\'affichage des autres utilisateurs. « Système » suit le réglage clair ou sombre de votre ordinateur.') }}</flux:text>
    </x-settings.layout>
</section>
