<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Accusé de réception {{ $courrier->numero_reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .reference { font-size: 14px; color: #444; margin: 0 0 24px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        td { padding: 6px 4px; vertical-align: top; border-bottom: 1px solid #e0e0e0; }
        td.label { width: 40%; color: #666; text-transform: uppercase; font-size: 10px; }
        .mention { display: inline-block; margin-top: 8px; padding: 4px 10px; border: 1px solid #1a1a1a; font-weight: bold; text-transform: uppercase; }
        .footer { margin-top: 40px; font-size: 10px; color: #888; }
    </style>
</head>
<body>
    <h1>GEC — Accusé de réception (courrier confidentiel)</h1>
    <p class="reference">Référence : <strong>{{ $courrier->numero_reference }}</strong></p>

    {{-- Volontairement minimal — spec Module 1 : "la seule preuve
         documentée de ce courrier (pas de scan, pas d'OCR, pas
         d'historique de contenu)". Aucun objet, aucun expéditeur, aucun
         service : ces informations ne sont jamais connues pour un
         courrier confidentiel. --}}
    <table>
        <tr>
            <td class="label">Date/heure d'enregistrement</td>
            <td>{{ $courrier->created_at->format('d/m/Y à H:i') }}</td>
        </tr>
        <tr>
            <td class="label">Nom visible sur l'enveloppe</td>
            <td>{{ $courrier->destinataire ?: '—' }}</td>
        </tr>
        <tr>
            <td class="label">Enregistré par</td>
            <td>{{ $auteur?->name ?? '—' }}</td>
        </tr>
    </table>

    <p><span class="mention">Confidentiel — Niveau {{ $courrier->confidentialite }}</span></p>

    <p class="footer">Document généré le {{ now()->format('d/m/Y à H:i') }} — GEC Nsia Assurances. Courrier non ouvert, non scanné : ce document est la seule preuve d'enregistrement.</p>
</body>
</html>
