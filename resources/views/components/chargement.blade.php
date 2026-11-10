{{-- Écran de chargement (2026-10-30, demande utilisateur avec maquette :
     anneau, message et astuce sur fond de grille) — se pose PAR-DESSUS un
     conteneur `relative` (absolute inset-0). Affichage piloté par l'appelant
     (x-show Alpine, wire:loading.flex, ou rendu conditionnel). Contenu
     facultatif (slot) sous le message, ex. un lien « Continuer sans
     attendre ». Styles : .chargement-grille / .chargement-anneau (app.css). --}}
@props(['titre', 'texte' => null, 'astuce' => null])

<div {{ $attributes->class('chargement-grille absolute inset-0 z-20 flex justify-center rounded-2xl') }} role="status" aria-live="polite">
    <div class="sticky top-24 flex h-fit flex-col items-center gap-3 px-6 py-20 text-center">
        <span class="chargement-anneau size-14" aria-hidden="true"></span>
        <p class="mt-2 text-sm font-medium text-brand-text-primary">{{ $titre }}</p>
        @if ($texte)
            <p class="max-w-sm text-sm text-brand-text-secondary">{{ $texte }}</p>
        @endif
        @if ($astuce)
            <p class="mt-3 max-w-xs text-xs text-brand-text-muted">{{ $astuce }}</p>
        @endif
        {{ $slot }}
    </div>
</div>
