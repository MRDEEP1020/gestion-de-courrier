<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $courrier->numero_reference }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .reference { font-size: 14px; color: #444; margin: 0 0 24px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        td { padding: 6px 4px; vertical-align: top; border-bottom: 1px solid #e0e0e0; }
        td.label { width: 32%; color: #666; text-transform: uppercase; font-size: 10px; }
        h2 { font-size: 12px; margin: 16px 0 6px; text-transform: uppercase; color: #666; }
        ul { margin: 0; padding-left: 18px; }
        li { margin-bottom: 3px; }
        .footer { margin-top: 32px; font-size: 10px; color: #888; }
    </style>
</head>
<body>
    {{-- 2026-11-11 — première page de chaque courrier de la copie
         personnelle (App\Services\SauvegardeLocale) : ce qu'est le courrier
         et ce que contient le PDF, pour le retrouver sans GEC. --}}
    <h1>GEC — Copie de secours</h1>
    <p class="reference">Référence : <strong>{{ $courrier->numero_reference }}</strong></p>

    <table>
        @foreach ($lignes as $libelle => $valeur)
            <tr>
                <td class="label">{{ $libelle }}</td>
                <td>{{ $valeur }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Pages suivantes</h2>
    @if ($inclus === [])
        <p>Aucune.</p>
    @else
        <ul>
            @foreach ($inclus as $element)
                <li>{{ $element }}</li>
            @endforeach
        </ul>
    @endif

    @if ($horsPdf !== [])
        <h2>Pièces jointes à ouvrir dans la copie « Tout (passe-partout) »</h2>
        <ul>
            @foreach ($horsPdf as $element)
                <li>{{ $element }}</li>
            @endforeach
        </ul>
    @endif

    @if ($absents !== [])
        <h2>Introuvables sur le stockage au moment de la copie</h2>
        <ul>
            @foreach ($absents as $element)
                <li>{{ $element }}</li>
            @endforeach
        </ul>
    @endif

    <p class="footer">Copie faite le {{ now()->format('d/m/Y à H:i') }}. Ce PDF s'ouvre avec le mot de passe de sauvegarde de son titulaire, en lecture seule (impression et copie bloquées), ou avec le passe-partout.</p>
</body>
</html>
