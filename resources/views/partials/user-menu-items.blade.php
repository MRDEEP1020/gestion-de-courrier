{{-- Contenu du menu profil, partagé entre la navbar mobile (flux:header
     lg:hidden) et la navbar desktop (flux:header hidden lg:flex) — voir
     DECISIONS.md "Navigation (navbar + sidebar)". Extrait le 2026-09-16
     pour éviter de dupliquer ce bloc une troisième fois.
     2026-10-26 — refonte demandée par l'utilisateur (« redesign this ») :
     carte d'identité (photo, profil, entité), langue en sélecteur segmenté
     (langue active visible), accès direct aux pages de paramètres (Sécurité retirée le 2026-10-26),
     déconnexion en rouge ; libellés en français (traduits via en.json). --}}
@php
    $utilisateur = auth()->user();
    $entite = $utilisateur->entitePrincipale();
    $langue = app()->getLocale();
@endphp

<div class="m-1 w-72 max-w-full rounded-lg bg-brand-surface-soft dark:bg-zinc-800/60">
    <div class="flex items-center gap-3 px-3 pt-3 pb-2">
        <flux:avatar size="lg" circle :initials="$utilisateur->initials()" :src="$utilisateur->photoUrl()" />
        <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-semibold text-brand-text-primary dark:text-white">{{ $utilisateur->name }}</div>
            <div class="truncate text-xs text-brand-text-secondary dark:text-zinc-400">{{ $utilisateur->email }}</div>
        </div>
    </div>

    @if ($utilisateur->profil || $entite)
        <div class="flex flex-wrap gap-1.5 px-3 pb-3">
            @if ($utilisateur->profil)
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-accent-pale px-2 py-0.5 text-xs font-medium text-brand-accent-medium dark:bg-zinc-800 dark:text-zinc-200">
                    <flux:icon.identification variant="micro" class="size-3.5 shrink-0" />
                    {{ $utilisateur->profil->nom }}
                </span>
            @endif
            @if ($entite)
                <span class="inline-flex max-w-full items-center gap-1 rounded-full bg-brand-blue-pale px-2 py-0.5 text-xs font-medium text-brand-navy dark:bg-zinc-800 dark:text-zinc-200" title="{{ $entite->name }}">
                    <flux:icon.building-office-2 variant="micro" class="size-3.5 shrink-0" />
                    <span class="truncate">{{ $entite->name }}</span>
                </span>
            @endif
        </div>
    @endif
</div>

<flux:menu.separator />

{{-- Lien simple (rechargement complet) : la langue est lue en session au
     début de chaque requête (middleware SetLocale). --}}
<div class="flex items-center justify-between gap-3 px-2 py-1.5">
    <span class="flex items-center gap-2 text-sm text-brand-text-secondary dark:text-zinc-300">
        <flux:icon.language variant="mini" class="size-4 text-zinc-400" />
        {{ __('Langue') }}
    </span>
    <div class="inline-flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800" role="group" aria-label="{{ __('Langue') }}">
        @foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $libelle)
            <a href="{{ route('langue.changer', $code) }}" @if ($langue === $code) aria-current="true" @endif
                class="rounded-md px-3 py-1 text-xs font-semibold transition {{ $langue === $code ? 'bg-white text-brand-navy shadow-sm dark:bg-zinc-600 dark:text-white' : 'text-zinc-500 hover:text-brand-text-primary dark:text-zinc-400 dark:hover:text-white' }}">
                {{ $libelle }}
            </a>
        @endforeach
    </div>
</div>

<flux:menu.separator />

<flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>{{ __('Mon profil') }}</flux:menu.item>
<flux:menu.item :href="route('appearance.edit')" icon="swatch" wire:navigate>{{ __('Apparence') }}</flux:menu.item>

<flux:menu.separator />

<form method="POST" action="{{ route('logout') }}" class="w-full">
    @csrf
    <flux:menu.item as="button" type="submit" variant="danger" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer text-brand-danger! [&_svg]:text-brand-danger!"
        data-test="logout-button">
        {{ __('Déconnexion') }}
    </flux:menu.item>
</form>
