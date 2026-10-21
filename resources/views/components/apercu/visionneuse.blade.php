{{--
    Zone document restylée (maquette replica.html, 2026-10-02) — fond gris
    "bureau", page blanche centrée avec ombre légère, barre d'état
    "Page X sur Y" en pied. Purement présentatiel, même principe que
    barre-outils.blade.php : référence `erreur`/`zoom`/`pageActuelle`/
    `numPages` du x-data parent, ne déclare rien de son cru.

    Adressage par `id` (document.getElementById), PAS x-ref — nécessaire ici
    pour que ce même composant serve aussi bien le panneau compact
    (x-data sur ce nœud) que les modales "Agrandir" (x-data déclaré plus
    haut, boîte/conteneur récupérés par id global, voir leur `instance()`).
    Le x-data appelant doit donc faire
    `apercu.boite = document.getElementById('{{ $id }}-corps')` /
    `apercu.conteneur = document.getElementById('{{ $id }}-conteneur')`
    plutôt que `this.$refs...`.
--}}
@props([
    'id',
    'hauteur' => 'h-64', // classe Tailwind de hauteur du panneau compact ; ignorée si `grandir` est vrai
    'grandir' => false,   // true dans les modales "Agrandir" : occupe tout l'espace flex restant (flex-1 min-h-0)
])

@php
    // Chaînes LITTÉRALES (jamais construites par concaténation) : le
    // scanner statique de Tailwind doit voir "max-h-64"/"max-h-74" tels
    // quels dans le code source pour générer la règle CSS correspondante —
    // une classe assemblée à l'exécution (`'max-'.$hauteur`) lui est
    // invisible et ne produirait AUCUN style (piège JIT classique).
    $hauteurMax = match ($hauteur) {
        'h-74' => 'max-h-[26rem]',
        default => 'max-h-96',
    };
@endphp

<div @class([
    'overflow-hidden rounded-b-lg border border-t-0 border-[#e1e1e1] bg-[#f0f0f0] dark:border-zinc-700 dark:bg-zinc-800',
    'flex w-full flex-1 min-h-0 min-w-0 flex-col' => $grandir,
])>
    {{-- `overflow-auto` INCONDITIONNEL — avant ce composant partagé, 7 des 8
         instances (tout sauf le panneau compact de registrationForm) avaient
         déjà ce comportement fixe, pas un `x-bind:class` selon le zoom ; un
         zoom conditionnel ajouté ici en uniformisant avait cassé le
         cliquer-glisser (plus de scroll possible, donc plus rien à déplacer)
         à 100% ou moins sur ces 7 instances (retour réel de l'utilisateur,
         capture d'écran, 2026-10-05).

         PAS de `items-center justify-center` sur ce conteneur flex : piège
         CSS connu (align-items/justify-content: center + overflow: auto) —
         dès que le contenu dépasse la boîte (zoom > 100 %), le navigateur le
         recentre en le décalant, et la partie qui dépasse désormais en
         haut/à gauche devient IMPOSSIBLE à atteindre en défilant (seul le
         bas/la droite restent accessibles) ; confirmé en conditions réelles
         (capture d'écran, 2026-10-05 : "can't even see the top after i
         zoom"). `margin: auto` sur l'enfant (`m-auto` ci-dessous) centre de
         la même façon tant que le contenu tient dans la boîte, mais
         s'annule proprement dès qu'il déborde — haut ET bas restent alors
         atteignables.

         Barres de défilement natives masquées (retour utilisateur,
         2026-10-05, capture d'écran : "i don't want to see those scrolling
         bars") — replica.html ne montre que de fins nubs décoratifs
         (`#8a8a8a`, non interactifs), jamais la vraie barre du système
         d'exploitation. `overflow-auto` reste actif (le défilement/
         cliquer-glisser continue de fonctionner au clavier/à la molette/au
         doigt), seul le rendu visuel de la barre est masqué.

         Pas de padding sur ce conteneur (retour utilisateur, 2026-10-05 :
         "the pdf should fit in no extra spaces") — la page touche
         maintenant les bords de la zone grise au lieu d'en être séparée par
         une marge fixe de 16px sur les 4 côtés. Dans la modale
         (`limiterHauteur=true`, voir document-preview.js), un espace
         résiduel peut subsister sur UN SEUL axe (jamais les deux) quand le
         ratio largeur/hauteur de la page scannée diffère de celui de la
         boîte — géométriquement inévitable sans rogner ou déformer la page
         (voir le commentaire de `charger()` dans document-preview.js).

         `max-h` AJOUTÉ sur le panneau compact uniquement (retour
         utilisateur, 2026-10-05 : "that panel should be fix when scroll as
         it was before" — le panneau "Aperçu du courrier" est dans une
         colonne `sticky top-20`, voir showCourrier/editForm/
         registrationForm.blade.php). document-preview.js fait grandir
         `boite.style.height` pour épouser EXACTEMENT la hauteur du rendu
         (`conteneur.scrollHeight`, voir son commentaire `rendre()`) — avec
         le padding retiré juste au-dessus, `boite.clientWidth` a augmenté
         de 32px, donc l'échelle "100%" (qui remplit la LARGEUR disponible)
         rend la page légèrement plus grande, et proportionnellement plus
         haute une fois le panneau agrandi en conséquence.

         Vérifié en conditions réelles (Playwright, 2026-10-05) sur
         /courriers/5 : la colonne de droite (sticky) atteignait 1157px pour
         une colonne de gauche (le conteneur du sticky, donc la hauteur de
         ligne de la grille) de seulement 1194px — à peine 37px de marge de
         défilement, ce qui rend le `sticky` quasiment imperceptible (dès
         qu'on dépasse ces 37px, il n'y a plus de place dans la ligne de
         grille pour qu'il reste accroché, il redevient visuellement
         indiscernable d'un panneau non-sticky). Un premier plafond à 32rem
         (512px) ne laissait quasiment aucune marge ; ramené ensuite à
         `h-64` (256px, la hauteur par défaut `$hauteur` ci-dessus) le
         `sticky` redevenait franchement perceptible (confirmé : `top`
         restait bien figé à 80px sur ~230px de défilement), MAIS la
         vignette devenait trop petite pour rester confortablement lisible
         (retour utilisateur, 2026-10-05 : "THE HIEGHT BROKE", juste après
         ce changement). `max-h-96` (384px, à mi-chemin entre les deux
         précédents essais) garde une vignette correctement lisible tout en
         laissant encore une marge de défilement réelle (même principe pour
         `max-h-[26rem]` sur le panneau de registrationForm, dont la
         hauteur de base `h-74` est déjà plus haute que `h-64`). Le reste du
         document redevient atteignable par défilement interne (barre
         masquée, cliquer-glisser toujours actif). Sans effet sur la modale
         "Agrandir" (`grandir`), déjà bornée par la hauteur de la modale
         elle-même. --}}
    {{-- Le plafond reprend la même base que `$hauteur` ("h-74" → un plafond
         plus généreux) plutôt qu'une constante fixe : registrationForm
         passe `hauteur="h-74"` (déjà plus haut que le défaut "h-64"), un
         plafond unique aurait sinon rétréci ce panneau en dessous de sa
         valeur voulue. --}}
    <div
        id="{{ $id }}-corps"
        @class([
            'flex w-full overflow-auto [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
            $hauteur => ! $grandir,
            $hauteurMax => ! $grandir,
            'flex-1 min-h-0' => $grandir,
        ])
        title="{{ __('Cliquer-glisser pour déplacer • Ctrl + molette pour zoomer') }}"
    >
        <p x-show="erreur" x-text="erreur" class="p-2 text-sm text-brand-danger"></p>
        <div id="{{ $id }}-conteneur" class="m-auto rounded-sm bg-white shadow-[0_0_0_1px_#e6e6e6]"></div>
    </div>

    <div x-show="numPages > 1" x-cloak class="flex shrink-0 items-center justify-center border-t border-[#e1e1e1] bg-[#f7f7f7] py-1 text-[13px] text-[#424242] dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
        {{ __('Page') }}&nbsp;<span x-text="pageActuelle"></span>&nbsp;{{ __('sur') }}&nbsp;<span x-text="numPages"></span>
    </div>
</div>
