{{-- Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service
     et acceptation") — remplace l'aperçu du document tant que le service
     destinataire n'a pas accepté le courrier. Les lignes floues sont un
     simple décor : le vrai document n'est JAMAIS envoyé au navigateur (le
     blocage est côté serveur, CourrierPolicy::voirContenu()), un flou CSS
     sur le vrai document se retirerait en un clic. --}}
<div class="relative flex h-56 flex-col items-center justify-center overflow-hidden rounded-lg border border-brand-border bg-brand-surface-soft text-center" aria-live="polite">
    <div class="pointer-events-none absolute inset-0 space-y-3 p-6 blur-sm" aria-hidden="true">
        <div class="h-3 w-2/3 rounded bg-zinc-300"></div>
        <div class="h-3 w-full rounded bg-zinc-200"></div>
        <div class="h-3 w-5/6 rounded bg-zinc-200"></div>
        <div class="h-3 w-full rounded bg-zinc-200"></div>
        <div class="h-3 w-3/4 rounded bg-zinc-200"></div>
        <div class="h-3 w-full rounded bg-zinc-200"></div>
        <div class="h-3 w-1/2 rounded bg-zinc-200"></div>
    </div>
    <div class="relative flex flex-col items-center gap-2 px-4">
        <flux:icon.lock-closed class="size-7 text-brand-text-secondary" />
        <span class="text-sm font-medium text-brand-text-primary">{{ __('Contenu visible après acceptation') }}</span>
        <span class="text-xs text-brand-text-secondary">{{ __('Acceptez ce courrier pour afficher le document et ses pièces jointes.') }}</span>
    </div>
</div>
