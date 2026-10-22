<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bordereau {{ $courrier->numero_reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .reference { font-size: 14px; color: #444; margin: 0 0 24px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        td { padding: 6px 4px; vertical-align: top; border-bottom: 1px solid #e0e0e0; }
        td.label { width: 35%; color: #666; text-transform: uppercase; font-size: 10px; }
        .footer { margin-top: 40px; font-size: 10px; color: #888; }
    </style>
</head>
<body>
    <h1>GEC — Bordereau d'enregistrement</h1>
    <p class="reference">Référence : <strong>{{ $courrier->numero_reference }}</strong></p>

    <table>
        <tr>
            <td class="label">Sens</td>
            <td>{{ $courrier->sens === 'entrant' ? 'Entrant' : 'Sortant' }}</td>
        </tr>
        <tr>
            <td class="label">Date de réception / d'envoi</td>
            <td>{{ $courrier->date_mouvement->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="label">Objet</td>
            <td>{{ $courrier->objet }}</td>
        </tr>
        <tr>
            <td class="label">Type de document</td>
            <td>
                {{ $courrier->type_document }}
                @if ($courrier->sous_type_sinistre)
                    ({{ $courrier->sous_type_sinistre === 'materiel' ? 'Matériel' : 'Corporel' }})
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">Mode de réception</td>
            <td>{{ $courrier->mode_reception }}</td>
        </tr>
        <tr>
            <td class="label">Service</td>
            {{-- service null-safe : un courrier entrant pas encore transféré
                 n'a pas de service (2026-09-15, voir DECISIONS.md,
                 synchronisation SRS-GEC.pdf). --}}
            <td>{{ $courrier->service?->nom ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Expéditeur</td>
            <td>
                {{ $courrier->expediteur_nom ?: '—' }}
                @if ($courrier->expediteur_organisation)
                    ({{ $courrier->expediteur_organisation }})
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">Destinataire(s)</td>
            <td>{{ $courrier->destinataire ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Priorité</td>
            <td>{{ $courrier->priorite }}</td>
        </tr>
        <tr>
            <td class="label">Confidentialité</td>
            <td>Niveau {{ $courrier->confidentialite }}</td>
        </tr>
        <tr>
            <td class="label">Statut</td>
            <td>{{ \App\Models\Courrier::libelleStatut($courrier->statut) }}</td>
        </tr>
    </table>

    <p class="footer">Document généré le {{ now()->format('d/m/Y à H:i') }} — GEC Nsia Assurances.</p>
</body>
</html>
