{{-- 2026-11-11 — renouvellement du mot de passe (RenouvelerMotDePasseController) :
     formulaire classique, même présentation que la page de connexion. --}}
@php
    $titres = [
        'initial' => [__('Choisissez votre mot de passe'), __('Pour protéger votre compte, remplacez le mot de passe qui vous a été donné par un mot de passe personnel, connu de vous uniquement.')],
        'expire' => [__('Votre mot de passe a expiré'), __('Pour continuer, choisissez un nouveau mot de passe.')],
        'bientot' => [__('Votre mot de passe expire bientôt'), trans_choice('Il expire dans :n jour. Renouvelez-le dès maintenant.|Il expire dans :n jours. Renouvelez-le dès maintenant.', $joursRestants ?? 0, ['n' => $joursRestants ?? 0])],
    ];
    [$titre, $description] = $titres[$raison] ?? $titres['expire'];
@endphp

<x-layouts::auth.split :title="__('Renouveler votre mot de passe')">
    <div class="flex flex-col gap-7">
        <div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-accent-pale px-3 py-1 text-xs font-semibold text-brand-accent-medium ring-1 ring-brand-accent-border ring-inset dark:bg-white/10 dark:text-white dark:ring-white/15">
                <flux:icon.key class="size-3.5" />
                {{ __('Mot de passe') }}
            </span>
            <h1 class="mt-4 text-3xl font-semibold tracking-tight text-brand-text-primary dark:text-white">{{ $titre }}</h1>
            <p class="mt-2 text-brand-text-secondary dark:text-zinc-400">{{ $description }}</p>
        </div>

        <form method="POST" action="{{ route('mot-de-passe.renouveler.enregistrer') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input name="motDePasseActuel" :label="__('Mot de passe actuel')" type="password" required autocomplete="current-password" viewable />

            <flux:input name="password" :label="__('Nouveau mot de passe')" type="password" required autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                :description="__('Différent de l\'actuel.')" viewable />

            <flux:input name="password_confirmation" :label="__('Confirmer le nouveau mot de passe')" type="password" required autocomplete="new-password" viewable />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Enregistrer le nouveau mot de passe') }}</flux:button>
        </form>

        {{-- Préavis : on peut remettre à plus tard ; sinon, seule la déconnexion reste possible. --}}
        @if ($raison === 'bientot')
            <flux:button :href="route('dashboard')" variant="ghost" class="w-full">{{ __('Plus tard') }}</flux:button>
        @else
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="ghost" class="w-full">{{ __('Se déconnecter') }}</flux:button>
            </form>
        @endif
    </div>
</x-layouts::auth.split>
