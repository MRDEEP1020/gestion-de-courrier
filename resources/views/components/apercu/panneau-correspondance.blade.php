{{--
    Panneau "correspondance" (maquette replica.html, 2026-10-02 — volet
    e-mail Outlook à droite du document) — adapté au domaine GEC : un
    courrier n'est pas un e-mail, donc ce panneau affiche l'OBJET,
    L'EXPÉDITEUR/DESTINATAIRE et la pièce jointe réels du courrier, jamais
    un corps de message (aucun champ équivalent n'existe dans le modèle —
    "texte_ocr" est le texte du document scanné lui-même, pas un message
    d'accompagnement, l'afficher ici aurait été trompeur).

    Props PRIMITIFS uniquement (jamais de modèle Eloquent) : ce panneau sert
    aussi bien un Courrier déjà persisté (showCourrier/editForm/courrierList)
    qu'un CourrierBrouillon + CourrierForm pas encore enregistrés
    (registrationForm, où objet/expéditeur vivent sur $form, pas encore sur
    un Courrier) — voir le journal de développement.
--}}
@props([
    'objet' => null,
    'numeroReference' => null,
    'expediteurNom' => null,
    'expediteurOrganisation' => null,
    'expediteurFonction' => null,
    'expediteurAdresse' => null,
    'expediteurTelephone' => null,
    'expediteurEmail' => null,
    'destinataire' => null,
    'date' => null, // \Carbon\CarbonInterface|string|null
    'nomFichier' => null,
    'tailleFichier' => null, // octets, nullable
])

@php
    $expediteurAffiche = $expediteurOrganisation ?: $expediteurNom;

    // Format d/m/Y — même convention que partout ailleurs dans
    // l'application pour `date_mouvement` (voir showCourrier.blade.php
    // ligne 62/257), pas le format "Jeu 01/10/2026 10:52" de la maquette
    // Outlook : `date_mouvement` est casté en DATE (pas datetime) sur
    // Courrier, afficher une heure serait toujours "00:00".
    $dateAffichee = $date instanceof \Carbon\CarbonInterface ? $date->format('d/m/Y') : $date;

    $tailleAffichee = null;
    if (is_numeric($tailleFichier) && $tailleFichier > 0) {
        $tailleAffichee = $tailleFichier >= 1048576
            ? number_format($tailleFichier / 1048576, 1).' Mo'
            : number_format($tailleFichier / 1024, 0).' Ko';
    }
@endphp

<div class="flex h-full min-h-0 flex-col gap-3 overflow-y-auto bg-[#f5f5f5] p-3 dark:bg-zinc-800">
    {{-- Objet --}}
    <div class="shrink-0 rounded-md bg-white p-3 shadow-[0_0_0_1px_#e6e6e6] dark:bg-zinc-900 dark:shadow-none dark:ring-1 dark:ring-zinc-700">
        <p class="text-[15.5px] font-semibold text-[#242424] dark:text-zinc-100">
            {{ $objet ?: __('Sans objet') }}
        </p>
        @if ($numeroReference)
            <p class="mt-0.5 text-xs text-[#616161] dark:text-zinc-400">{{ $numeroReference }}</p>
        @endif
    </div>

    {{-- Expéditeur / date / destinataire --}}
    <div class="shrink-0 rounded-md bg-white p-3 shadow-[0_0_0_1px_#e6e6e6] dark:bg-zinc-900 dark:shadow-none dark:ring-1 dark:ring-zinc-700">
        <div class="flex items-start gap-3">
            <flux:avatar :name="$expediteurAffiche" size="sm" />
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-2">
                    <p class="truncate text-[13px] font-medium text-[#242424] dark:text-zinc-100">
                        {{ $expediteurAffiche ?: __('Expéditeur non renseigné') }}
                    </p>
                    @if ($dateAffichee)
                        <span class="shrink-0 text-xs text-[#424242] dark:text-zinc-400">{{ $dateAffichee }}</span>
                    @endif
                </div>
                @if ($destinataire)
                    <p class="mt-0.5 truncate text-[12.5px] text-[#424242] dark:text-zinc-400">{{ __('À : ') }}{{ $destinataire }}</p>
                @endif
            </div>
        </div>

        @if ($nomFichier)
            <div class="mt-3 flex items-center gap-2 rounded-md border border-[#e0e0e0] p-2 dark:border-zinc-700">
                <flux:icon.document-text class="size-5 shrink-0 text-[#2b5fb4]" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13px] text-[#424242] dark:text-zinc-300">{{ $nomFichier }}</p>
                    @if ($tailleAffichee)
                        <p class="text-[11px] text-[#616161] dark:text-zinc-500">{{ $tailleAffichee }}</p>
                    @endif
                </div>
                {{-- Bouton « Télécharger » retiré le 2026-11-09 (aperçu sécurisé seulement). --}}
            </div>
        @endif
    </div>

    <x-apercu.signature-expediteur
        :nom="$expediteurNom"
        :fonction="$expediteurFonction"
        :organisation="$expediteurOrganisation"
        :adresse="$expediteurAdresse"
        :telephone="$expediteurTelephone"
        :email="$expediteurEmail"
    />
</div>
