@props(['statut'])

{{--
    Badge de statut d'un courrier — composant partagé (2026-09-16, voir
    DECISIONS.md "Badge de statut partagé") : ce mapping statut → couleur
    était dupliqué et avait dérivé dans 4 fichiers différents
    (courrierList/dashboard/workflowQueue/showCourrier), chacun avec une
    palette légèrement différente pour le même statut. Un seul point de
    vérité désormais.

    Réécrit le 2026-09-17 (système de couleurs GEC fourni par
    l'utilisateur, section "Status Badges") : PAS de <flux:badge
    color="...">, dont le prop `color` n'accepte que la palette Tailwind
    nommée de Flux Pro (composant compilé, source indisponible dans
    vendor/) — impossible d'obtenir les hex EXACTS demandés (fond clair +
    texte foncé par statut) via ce mécanisme. Un <span> habillé de nos
    propres tokens --color-brand-* donne les couleurs exactes sans toucher
    au vendor.

    Mise à jour (2026-09-18, maquette "Tous les courriers" en table,
    demande explicite de l'utilisateur) : "en_traitement" rejoint désormais
    INFO (bleu), pas WARNING — clarifié avec l'utilisateur ("en traitement
    is blue en attente in oragne terminier in green, erreur in rouge
    everywhere same color consistent"). Les autres statuts "en attente"
    (en_validation, en_cours_de_transfert, en_attente_*) restent WARNING
    (orange) — seul en_traitement a été déplacé. "affecte" reste SUCCESS
    (vert) — listé explicitement sous 🟢 dans le système fourni ("Affecté"),
    non concerné par ce changement.

    Mise à jour (2026-10-05, retour utilisateur) : "archive" quitte le
    groupe SUCCESS (vert) pour rejoindre la palette grise/noire neutre
    (mêmes tokens --color-brand-disabled-bg/text que le statut par défaut),
    afin de ne plus être confondu visuellement avec "traité"/"affecté".
--}}
@php
    $classes = match ($statut) {
        'traite', 'affecte' => 'bg-brand-success-light text-brand-success-dark',
        'archive' => 'bg-brand-disabled-bg text-brand-disabled-text',
        'rejete' => 'bg-brand-danger-light text-brand-danger-dark',
        'en_traitement', 'enregistre' => 'bg-brand-info-light text-brand-info-dark',
        'en_attente_information', 'en_attente_de_transfert', 'en_validation', 'en_cours_de_transfert', 'en_attente_signature_dg', 'en_attente_tresorerie' => 'bg-brand-warning-light text-brand-warning-dark',
        default => 'bg-brand-disabled-bg text-brand-disabled-text',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center rounded-full px-3 py-0.5 text-xs font-medium', $classes]) }}>
    {{ \App\Models\Courrier::libelleStatut($statut) }}
</span>
