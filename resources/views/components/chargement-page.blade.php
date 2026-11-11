{{-- 2026-11-11 (« i want this type of loading instead », photo d'un écran de
     chargement plein page : logo au centre, arc qui tourne, page estompée) —
     remplace les squelettes et la barre de progression. Visible dans le HTML
     dès le départ (premier chargement), masqué par resources/js/app.js à la
     fin du chargement, puis affiché pendant un changement de page ou une
     action lente. @persist : le même nœud traverse les wire:navigate, sans
     clignoter à chaque page. Filet de sécurité : sans JavaScript, il
     disparaît tout seul au bout de 8 s (voir .chargement-page dans app.css). --}}
@persist('chargement-page')
    <div id="chargement-page" class="chargement-page est-visible" role="status" aria-live="polite">
        <div class="chargement-centre">
            <span class="chargement-arc" aria-hidden="true"></span>
            <img src="{{ asset('images/apercu/nsia-logo.png') }}" alt="" class="chargement-logo">
        </div>
        <span class="sr-only">{{ __('Chargement…') }}</span>
    </div>
@endpersist
