{{-- Squelette de lignes de tableau — même nombre de colonnes que le
     tableau réel (passer :cols pour matcher), largeurs de barres variées
     pour éviter un effet "grille" trop régulier. Toujours utilisé à
     l'intérieur d'un <tbody> existant, jamais autonome. --}}
@props(['cols' => 5, 'rows' => 5])
@php
    // Largeurs en pourcentage, cycliques par colonne — purement visuel,
    // jamais une donnée réelle.
    $largeurs = [85, 65, 75, 55, 90, 60, 70, 50, 80];
@endphp
@for ($r = 0; $r < $rows; $r++)
    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
        @for ($c = 0; $c < $cols; $c++)
            <td class="px-3 py-3">
                <div class="skeleton h-4 rounded" style="width: {{ $largeurs[$c % count($largeurs)] }}%"></div>
            </td>
        @endfor
    </tr>
@endfor
