{{-- Module 5 — chronomètre de traitement (2026-09-24, voir DECISIONS.md
     "Chronomètre de traitement"). Compte à rebours EN DIRECT côté
     navigateur (Alpine, resources/js/app.js — aucune requête serveur par
     seconde, jamais de wire:poll : Règle n°2), recalé sur l'heure du
     serveur. Au-delà de l'échéance il compte le dépassement ; à la clôture
     il se fige sur le temps réellement passé.
     2026-10-02 ("and bientot arreter should also appear there when the
     echance is approching only", puis "i want a badge beside the time
     contdown to appear only when the echaence date is reaching already") —
     deux badges d'état supplémentaires à côté du compte à rebours :
     "Bientôt en retard" (état "risque") et "En retard" (état "retard"),
     chacun dérivé du MÊME état Alpine `etat` que le compte à rebours (une
     seule source de vérité, le calcul JS déjà existant) plutôt que de
     dupliquer la logique d'échéance via SlaCalculatorService, qui tourne à
     la JOURNÉE près côté serveur alors que ce calcul tourne à la MINUTE
     près côté client (même décalage déjà documenté pour
     Courrier::scopeEnRetard(), voir DECISIONS.md "En retard (Module 5/10) :
     cohérence avec le chronomètre") — les afficher sur deux calculs
     différents aurait pu les faire se contredire visuellement.
     2026-10-02 (suite, "remove the time just leave the bientot and
     enretand", puis précisé "only on the tout les courier page the rest
     shouldn't change") : le compte à rebours visible reste la forme PAR
     DÉFAUT partout (ShowCourrier, Dashboard, Dossiers & Archives,
     EditForm) — seule "Tous les courriers" (CourrierList) le masque via
     :masquer-temps="true" (voir ses deux usages), ne gardant QUE les deux
     badges d'état ci-dessus pour cette page précise. --}}
@props(['courrier', 'masquerTemps' => false])

@php
    $debutChrono = $courrier->chronoDebut();
    $finChrono = $courrier->chronoFin();
    $arretChrono = $courrier->chrono_arrete_le;
    // Courrier clôturé AVANT l'existence du chronomètre (pas d'heure
    // d'arrêt enregistrée) : aucun compte à rebours qui tournerait à tort.
    $clotureSansArret = $arretChrono === null && in_array($courrier->statut, ['traite', 'archive', 'rejete'], true);
@endphp

@if ($finChrono && ! $clotureSansArret)
    {{-- class="contents" : wrapper purement structurel (porte x-data +
         les data-* partagés), n'affecte jamais la mise en page — les deux
         badges enfants apparaissent comme de vrais siblings dans le
         conteneur flex appelant (voir les pages qui posent déjà
         <x-chronometre> à côté de <x-statut-badge> dans un
         "flex items-center gap-1"). --}}
    <span
        x-data="chronometre"
        class="contents"
        {{-- Clé liée à la fin/l'arrêt : un délai modifié ou une clôture
             remplace l'élément (nouvel init Alpine) au lieu de garder
             l'ancien compte à rebours. --}}
        wire:key="chrono-{{ $courrier->id }}-{{ $finChrono->getTimestampMs() }}-{{ $arretChrono?->getTimestampMs() }}"
        data-debut="{{ $debutChrono?->getTimestampMs() }}"
        data-fin="{{ $finChrono->getTimestampMs() }}"
        data-arret="{{ $arretChrono?->getTimestampMs() }}"
        data-maintenant="{{ now()->getTimestampMs() }}"
        data-label-restant="{{ __('Temps restant') }}"
        data-label-retard="{{ __('Dépassement') }}"
        data-label-traite="{{ __('Traité en') }}"
        {{-- 2026-10-12 — texte aligné sur le nouveau calcul (app.js) : le
             badge affiche désormais le VRAI dépassement (arrêt - échéance),
             plus la durée totale de traitement — "Traité en retard, en"
             (cadrage "durée totale") prêtait à confusion une fois comparé à
             l'échéance affichée juste à côté sur la fiche. --}}
        data-label-traite-retard="{{ __('En retard de') }}"
        data-jour="{{ __('j') }}"
        data-echeance="{{ __('Échéance') }} {{ $finChrono->format('d/m/Y H:i') }}"
    >
        @unless ($masquerTemps)
            <span
                {{ $attributes->class('inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 font-mono text-xs font-medium tabular-nums') }}
                x-bind:class="{ ok: 'bg-brand-info-light text-brand-info-dark', risque: 'bg-brand-warning-light text-brand-warning-dark', retard: 'bg-brand-danger-light text-brand-danger-dark', fige: 'bg-brand-success-light text-brand-success-dark', fige_retard: 'bg-brand-danger-light text-brand-danger-dark' }[etat]"
                x-bind:title="libelle + ' — ' + $el.dataset.echeance"
            >
                <flux:icon.clock class="size-3" />
                <span x-text="(etat === 'retard' ? '+' : '') + affichage"></span>
            </span>
        @endunless
        <span
            x-show="etat === 'risque'"
            x-cloak
            x-bind:title="textes.echeance"
            class="inline-flex shrink-0 items-center rounded-full bg-brand-warning-light px-2 py-0.5 text-xs font-medium normal-case text-brand-warning-dark"
        >
            {{ __('Bientôt en retard') }}
        </span>
        <span
            x-show="etat === 'retard'"
            x-cloak
            x-bind:title="textes.echeance"
            class="inline-flex shrink-0 items-center rounded-full bg-brand-danger-light px-2 py-0.5 text-xs font-medium normal-case text-brand-danger-dark"
        >
            {{ __('En retard') }}
        </span>
    </span>
@endif
