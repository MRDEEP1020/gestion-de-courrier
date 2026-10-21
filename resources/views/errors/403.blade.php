{{-- Page d'erreur 403 personnalisée (2026-09-22, demande explicite de
     l'utilisateur : "rather than showing that page we should show him like
     what happening" — la page 403 brute de Laravel, sans navigation ni
     explication, remplacée par une page intégrée au même habillage que le
     reste de l'app (sidebar/en-tête réels, pas une page isolée) pour que
     l'utilisateur puisse comprendre ET repartir sans clic "Précédent".
     Message volontairement générique sur la CAUSE exacte : les gates de
     CourrierPolicy (confidentialité, dossier de classement, périmètre
     organisationnel, privilège par profil) sont CUMULATIFS — un refus peut
     venir de plusieurs à la fois, une raison unique inventée serait fausse
     dans certains cas. Liste les causes les plus fréquentes à la place,
     sans jamais fabriquer un motif précis que le code n'a pas réellement
     déterminé. --}}
@php
    // Piège trouvé en test (2026-09-22) : $errors n'est partagé
    // GLOBALEMENT (View::share) que par le middleware ShareErrorsFromSession
    // du groupe 'web' — absent quand une exception d'autorisation est levée
    // dans mount() d'un composant Livewire testé via Livewire::test(), qui
    // ne rejoue pas ce middleware. Le champ de recherche <flux:input> de la
    // sidebar (partagée par toute page de l'app) a besoin de $errors en
    // interne (@error) : sans ce garde-fou, 22 tests existants
    // (->assertForbidden() sur divers composants) échouaient avec
    // "Undefined variable $errors" au lieu de simplement voir la 403.
    // Illuminate\View\Factory::shared('errors') (pas $errors local à CETTE
    // vue) — un composant Blade imbriqué comme <x-layouts::app.sidebar> a sa
    // propre portée de variables, il ne lit que les données PARTAGÉES
    // globalement (View::share), jamais les variables locales du gabarit
    // appelant.
    if (! app('view')->shared('errors')) {
        \Illuminate\Support\Facades\View::share('errors', new \Illuminate\Support\ViewErrorBag);
    }
@endphp
<x-layouts::app.sidebar :title="__('Accès refusé')">
    <flux:main class="bg-brand-surface">
        <div class="mx-auto flex max-w-2xl flex-col items-center gap-6 px-4 py-16 text-center">
            <div class="flex size-16 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                <flux:icon.lock-closed class="size-8" />
            </div>

            <div class="space-y-2">
                <flux:heading level="1" size="xl">{{ __('Vous n\'avez pas accès à cette page') }}</flux:heading>
                <flux:text class="text-brand-text-secondary">
                    {{ $exception->getMessage() && $exception->getMessage() !== 'This action is unauthorized.'
                        ? $exception->getMessage()
                        : __('Cette ressource existe, mais votre compte n\'a pas les droits nécessaires pour la consulter.') }}
                </flux:text>
            </div>

            <div class="w-full rounded-xl border border-brand-border bg-white p-4 text-left dark:border-zinc-700 dark:bg-zinc-900">
                <flux:text class="mb-2 font-medium text-zinc-700 dark:text-zinc-200">{{ __('Raisons possibles :') }}</flux:text>
                <ul class="space-y-1.5 text-sm text-brand-text-secondary">
                    <li class="flex items-start gap-2">
                        <flux:icon.shield-check class="mt-0.5 size-4 shrink-0" />
                        <span>{{ __('Le niveau de confidentialité de cet élément dépasse votre niveau d\'accès.') }}</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <flux:icon.folder class="mt-0.5 size-4 shrink-0" />
                        <span>{{ __('Il est classé dans un dossier auquel vous n\'avez pas été rattaché.') }}</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <flux:icon.building-office-2 class="mt-0.5 size-4 shrink-0" />
                        <span>{{ __('Il est hors du périmètre organisationnel qui vous a été assigné.') }}</span>
                    </li>
                    <li class="flex items-start gap-2">
                        <flux:icon.identification class="mt-0.5 size-4 shrink-0" />
                        <span>{{ __('Votre profil ne dispose pas du privilège nécessaire pour cette action.') }}</span>
                    </li>
                </ul>
            </div>

            <flux:text class="text-sm text-zinc-400">
                {{ __('Si vous pensez que c\'est une erreur, contactez un administrateur.') }}
            </flux:text>

            <flux:button variant="primary" icon="arrow-left" :href="route('dashboard')" wire:navigate>
                {{ __('Retour au tableau de bord') }}
            </flux:button>
        </div>
    </flux:main>
</x-layouts::app.sidebar>
