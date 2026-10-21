{{-- Squelette d'une liste simple (ex. "Tâches du jour") — pastille +
     2 lignes de texte par élément, même gabarit qu'une entrée réelle.
     Toujours utilisé à l'intérieur d'un <ul>/<div> existant. --}}
@props(['items' => 3])
@for ($i = 0; $i < $items; $i++)
    <li class="flex items-start gap-3 px-4 py-3">
        <div class="skeleton mt-1.5 size-2 shrink-0 rounded-full"></div>
        <div class="min-w-0 flex-1 space-y-1.5">
            <div class="skeleton h-3.5 rounded" style="width: {{ [45, 55, 35][$i % 3] }}%"></div>
            <div class="skeleton h-3 rounded" style="width: {{ [70, 60, 80][$i % 3] }}%"></div>
        </div>
    </li>
@endfor
