{{-- 2026-10-14, demande explicite de l'utilisateur ("design the login page",
     puis "redesing it") — voir layouts/auth/split.blade.php pour le panneau
     de marque NSIA. En-tête aligné à gauche écrit ici plutôt que via
     x-auth-header (centré, partagé par les autres pages d'authentification
     restées scaffold — hors périmètre). La clé d'accès (passkey) passe SOUS
     le formulaire principal : la grande majorité des comptes, créés par un
     administrateur, n'ont qu'un mot de passe. --}}
<x-layouts::auth.split :title="__('Connexion')">
    <div class="flex flex-col gap-7">
        <div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-accent-pale px-3 py-1 text-xs font-semibold text-brand-accent-medium ring-1 ring-brand-accent-border ring-inset dark:bg-white/10 dark:text-white dark:ring-white/15">
                <flux:icon.shield-check class="size-3.5" />
                {{ __('Espace sécurisé') }}
            </span>
            <h1 class="mt-4 text-3xl font-semibold tracking-tight text-brand-text-primary dark:text-white">
                {{ __('Bienvenue sur GEC') }}
            </h1>
            <p class="mt-2 text-brand-text-secondary dark:text-zinc-400">
                {{ __('Connectez-vous avec vos identifiants professionnels.') }}
            </p>
        </div>

        <x-auth-session-status :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input
                name="email"
                :label="__('Adresse e-mail')"
                :value="old('email')"
                type="email"
                icon="envelope"
                required
                autofocus
                autocomplete="email"
                :placeholder="__('prenom.nom@exemple.com')"
            />

            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Mot de passe')"
                    type="password"
                    icon="lock-closed"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Votre mot de passe')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute inset-e-0 top-0 text-sm font-medium" :href="route('password.request')" wire:navigate>
                        {{ __('Mot de passe oublié ?') }}
                    </flux:link>
                @endif
            </div>

            <flux:checkbox name="remember" :label="__('Rester connecté sur cet appareil')" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" icon:trailing="arrow-right" class="mt-1 w-full" data-test="login-button">
                {{ __('Se connecter') }}
            </flux:button>
        </form>

        <x-passkey-verify
            separator-position="before"
            :separator="__('ou')"
            :label="__('Se connecter avec une clé d\'accès')"
            :loading-label="__('Authentification en cours…')"
        />

        {{-- Inscription publique désactivée (2026-09-23, config/fortify.php) :
             les comptes sont créés par un administrateur — dit explicitement
             ici plutôt que de laisser l'utilisateur chercher un lien
             d'inscription qui n'existe pas. --}}
        @if (Route::has('register'))
            <div class="space-x-1 text-center text-sm text-zinc-600 rtl:space-x-reverse dark:text-zinc-400">
                <span>{{ __('Vous n\'avez pas de compte ?') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('S\'inscrire') }}</flux:link>
            </div>
        @else
            <div class="flex gap-3 rounded-2xl bg-brand-surface p-4 text-sm text-brand-text-secondary ring-1 ring-brand-border ring-inset dark:bg-white/5 dark:text-zinc-400 dark:ring-white/10">
                <flux:icon.information-circle class="size-5 shrink-0 text-brand-accent" />
                <p>{{ __('Pas encore d\'accès ? Votre compte est créé par l\'administrateur de la plateforme.') }}</p>
            </div>
        @endif
    </div>
</x-layouts::auth.split>
