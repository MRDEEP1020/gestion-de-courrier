{{-- Squelette d'un panneau latéral (ex. "Aperçu du courrier") — un grand
     bloc (aperçu document) suivi de plusieurs paires libellé/valeur, même
     gabarit que le contenu réel pour ne jamais faire sauter la mise en
     page. --}}
@props(['lignes' => 4])
<div class="space-y-4 p-4">
    <div class="skeleton h-40 w-full rounded-lg"></div>
    @for ($i = 0; $i < $lignes; $i++)
        <div class="space-y-1.5">
            <div class="skeleton h-3 w-20 rounded"></div>
            <div class="skeleton h-4 rounded" style="width: {{ [55, 70, 45, 65, 50][$i % 5] }}%"></div>
        </div>
    @endfor
</div>
