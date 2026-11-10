{{-- Aperçu de la signature électronique (2026-10-28, « mettre le nom comme
     signature au cas où on ne veut pas imprimer ») — le nom tel qu'il
     apparaîtra dans le cadre de signature de la décharge (PDF). --}}
@props(['nom'])
<div class="rounded-xl border border-dashed border-brand-navy/40 bg-white px-4 py-3 text-center dark:border-zinc-600 dark:bg-zinc-900">
    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Votre signature') }}</div>
    <div class="mt-1 font-serif text-2xl italic text-brand-navy dark:text-zinc-100">{{ $nom }}</div>
    <div class="mt-0.5 text-xs text-zinc-500">{{ __('Signé électroniquement dans GEC — :date', ['date' => now()->format('d/m/Y à H:i')]) }}</div>
</div>
