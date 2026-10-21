{{--
    Bloc "signature" (maquette replica.html, 2026-10-02) — adapté aux
    champs RÉELS de l'expéditeur (Courrier::expediteur_nom/fonction/
    organisation/adresse/telephone/email). Ne s'affiche que si AU MOINS un
    de ces champs est renseigné (sinon simple carte vide sans valeur).

    Avatar/visage et bandeau décoratif de la maquette NON repris : c'était
    la photo et le graphisme personnels d'un collaborateur précis capturés
    dans l'e-mail d'exemple réel — les réutiliser comme image "par défaut"
    affichée pour N'IMPORTE QUEL courrier de N'IMPORTE QUEL expéditeur,
    pour TOUTE l'entreprise, aurait détourné la photo d'une personne réelle
    de son contexte. Seul le logo NSIA (actif de marque de l'entreprise
    elle-même, propriétaire de cet outil) a été extrait vers
    public/images/apercu/nsia-logo.png ; l'avatar utilise le composant
    <flux:avatar> existant (initiales), même convention que le reste de
    l'application (voir resources/views/components/desktop-user-menu.blade.php).
    Le mince trait doré sous le nom est recréé en CSS (couleurs reprises de
    la maquette), ce n'était déjà pas une image dans replica.html.
--}}
@props([
    'nom' => null,
    'fonction' => null,
    'organisation' => null,
    'adresse' => null,
    'telephone' => null,
    'email' => null,
])

@php
    $aDesInfos = $nom || $fonction || $organisation || $adresse || $telephone || $email;
@endphp

@if ($aDesInfos)
    <div class="shrink-0 rounded-md bg-white p-3 shadow-[0_0_0_1px_#e6e6e6] dark:bg-zinc-900 dark:shadow-none dark:ring-1 dark:ring-zinc-700">
        @if ($nom)
            <p class="text-[15px] font-bold text-[#c99a0c]">{{ $nom }}</p>
            <div class="my-1.5 h-px w-40 bg-[linear-gradient(90deg,#d9b24a,#f0e2b0)]"></div>
        @endif

        <div class="space-y-0.5 text-[12px] text-[#111] dark:text-zinc-300">
            @if ($fonction)
                <p class="font-bold">{{ $fonction }}</p>
            @endif
            @if ($organisation)
                <p class="font-bold">{{ $organisation }}</p>
            @endif
            @if ($adresse)
                <p>{{ $adresse }}</p>
            @endif
            @if ($telephone)
                <p>☎&nbsp;{{ $telephone }}</p>
            @endif
            @if ($email)
                <p class="truncate">{{ $email }}</p>
            @endif
        </div>

        <img src="{{ asset('images/apercu/nsia-logo.png') }}" alt="NSIA Assurances" class="mt-3 h-9 w-auto">
    </div>
@endif
