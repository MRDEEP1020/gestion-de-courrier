{{--
    Habillage décoratif de la sidebar (2026-10-09/10, demande explicite de
    l'utilisateur) — remplace la première version 100% SVG dessinée à la
    main par les 4 VRAIS assets fournis (public/images/nsia/*.jpeg, déplacés
    depuis la racine du repo où ils avaient été déposés) :
    sidebar-background (fond navy), abstract-curves (courbes bleues),
    building (immeuble NSIA), gold-light-trails (filets dorés).

    IMPORTANT — JPEG ne supporte AUCUN canal alpha (contrairement à
    PNG/WebP) : les 4 fichiers ont donc un fond plat (navy pour le fond,
    NOIR PUR pour les 3 autres), jamais une vraie transparence — vérifié en
    les ouvrant un par un avant d'écrire ce fichier (règle explicite de
    l'utilisateur : ne jamais superposer des JPEG en supposant une
    transparence). Deux techniques différentes selon le cas, pas la même
    partout :
    - courbes + filets dorés : fond NOIR PUR homogène, aucune zone de
      contenu photographique à préserver intacte -> mix-blend-mode: screen
      (le noir pur s'annule mathématiquement à screen, laissant passer le
      fond navy ; uniquement les pixels clairs/colorés des courbes et
      filets s'additionnent par-dessus). Techniquement correct ici
      PRÉCISÉMENT parce que le fond est noir pur (testé visuellement avant
      d'écrire ce fichier).
    - immeuble : 2026-10-14, remplacé par la photo du VRAI bâtiment NSIA
      (public/images/nsia/building-real.jpg — "nsia2.jpeg is the real
      building", puis "YES DO BOTH" pour la sidebar ; l'ancien
      building.jpeg, façade vitrée bleue générée, n'est plus utilisé
      nulle part). Fond noir pur mesuré (canal max = 2) ->
      mix-blend-lighten : le noir s'annule, la façade crème garde ses
      vraies couleurs (screen la délaverait). Façade CLAIRE, contrairement
      à l'ancienne : deux masques combinés (intersect) — étages supérieurs
      fondus dans le navy (un groupe de nav déplié peut descendre jusque-là,
      texte blanc illisible sur crème) et bord gauche fondu (le slogan, en
      bas à gauche, reste sur fond sombre).
    w-[70%] h-auto (jamais object-fit/stretch) sur l'immeuble : aspect ratio
    natif préservé, demande explicite de l'utilisateur ("Do not distort the
    building or stretch the curves").

    Entièrement décoratif : pointer-events-none sur le conteneur (hérité
    par tous les enfants), position absolute/-z-10 à l'intérieur de
    <flux:sidebar> (lui-même `relative isolate`, voir sidebar.blade.php)
    pour peindre STRICTEMENT derrière le contenu normal de la sidebar
    (en-tête, nav, déconnexion) sans toucher au vendor Flux.

    Logo NSIA réel : la photo de l'immeuble affiche déjà le symbole/logo
    réel (généré avec le bâtiment), mais UNIQUEMENT dans cette scène
    photographique — pas question de le recadrer/extraire d'ici pour
    remplacer x-app-logo (en-tête de sidebar) : qualité d'extraction trop
    faible (perspective, compression JPEG, fondu avec la façade). Le
    logo de l'en-tête reste x-app-logo en attendant un fichier source dédié.

    inset-0 (PAS -inset-4) — bug réel corrigé le 2026-10-12 ("why does the
    sidebar scroll when am scrolling on it") : <flux:sidebar> (vendor, prop
    `sticky`) est À LA FOIS le conteneur `overflow-y-auto` ET l'ancêtre
    positionné de ce calque. Un -inset-4 (destiné à l'origine à compenser
    le p-4 du vendor pour atteindre les bords réels) étend la boîte de ce
    calque de 1rem AU-DELÀ du bord bas de la zone de padding — et CETTE
    boîte, même absolute/-z-10/overflow-hidden, contribue quand même au
    scrollHeight du conteneur qui la porte (overflow-hidden sur un élément
    ne retire jamais sa propre contribution de taille au calcul de
    défilement de son ANCÊTRE). Résultat : la sidebar devenait "scrollable"
    de quelques pixels même quand la nav tenait déjà entièrement dans la
    hauteur visible. inset-0 (aligné exactement sur la boîte de padding,
    sans débordement) élimine ce débordement fantôme ; le dégradé déjà posé
    sur <flux:sidebar> lui-même (bg-linear-to-b from-brand-sidebar-bg-from
    to-brand-sidebar-bg-to, voir sidebar.blade.php) comble la fine bande de
    padding restante avec la même teinte navy, donc sans coupure visible. --}}
<div class="pointer-events-none absolute inset-0 -z-10 overflow-hidden bg-cover bg-center"
    style="background-image: url('{{ asset('images/nsia/sidebar-background.jpeg') }}')">

    {{-- 1. Courbes bleues abstraites, surdimensionnées, derrière la nav. --}}
    <div class="absolute inset-0 mix-blend-screen bg-cover bg-top opacity-55"
        style="background-image: url('{{ asset('images/nsia/abstract-curves.jpeg') }}')"></div>

    {{-- 2. Filets de lumière dorés — DERRIÈRE l'immeuble depuis le
         2026-10-14 (avant : par-dessus) : leur brume grise, en screen,
         délavait la façade claire du vrai bâtiment. Haut fondu par
         mask-image : la brume touche les bords du JPEG, arête nette sinon
         (constaté sur la page de connexion). --}}
    <div class="absolute inset-x-0 bottom-0 h-2/5 mix-blend-screen bg-cover bg-center opacity-45"
        style="background-image: url('{{ asset('images/nsia/gold-light-trails.jpeg') }}'); mask-image: linear-gradient(to bottom, transparent, #000 40%); -webkit-mask-image: linear-gradient(to bottom, transparent, #000 40%)"></div>

    {{-- 3. Vrai bâtiment NSIA, ancré en bas à droite, ratio natif. --}}
    <img src="{{ asset('images/nsia/building-real.jpg') }}" alt=""
        class="absolute right-0 bottom-0 h-auto w-[70%] mix-blend-lighten"
        style="mask-image: linear-gradient(to bottom, transparent, #000 40%), linear-gradient(to right, transparent, #000 40%); -webkit-mask-image: linear-gradient(to bottom, transparent, #000 40%), linear-gradient(to right, transparent, #000 40%); mask-composite: intersect; -webkit-mask-composite: source-in;">

    {{-- 4. Overlay de lisibilité : assombrit légèrement le haut (texte de
         nav) et le bas (derrière le slogan), laisse la zone médiane
         (façade de l'immeuble) la plus visible. --}}
    <div class="absolute inset-0 bg-linear-to-b from-black/35 via-transparent to-black/30"></div>

    {{-- Slogan de marque : déplacé le 2026-10-14 dans le flux normal de
         sidebar.blade.php (après <flux:spacer />). Positionné ici en
         absolute bottom-5, il se superposait à "Déconnexion" dès qu'un
         groupe déplié allongeait la nav jusqu'en bas (constaté sur capture,
         1366×768, groupe "Courriers" ouvert). --}}
</div>
