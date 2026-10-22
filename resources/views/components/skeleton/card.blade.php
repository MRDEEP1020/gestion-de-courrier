{{-- Squelette d'une carte KPI compacte (icône + libellé/chiffre + tendance)
     — même gabarit que les cartes réelles de CourrierList/Dashboard, pour
     qu'il n'y ait aucun saut de mise en page entre l'état chargement et le
     rendu final. --}}
<div class="rounded-xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
    <div class="flex items-center gap-2.5">
        <div class="skeleton size-10 shrink-0 rounded-full"></div>
        <div class="min-w-0 flex-1 space-y-1.5">
            <div class="skeleton h-3 w-16 rounded"></div>
            <div class="skeleton h-5 w-10 rounded"></div>
        </div>
    </div>
    <div class="mt-2">
        <div class="skeleton h-3 w-28 rounded"></div>
    </div>
</div>
