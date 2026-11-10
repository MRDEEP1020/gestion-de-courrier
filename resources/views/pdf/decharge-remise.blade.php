{{-- Décharge de remise de l'original papier (2026-10-27, « arrange the
     décharge design as a real décharge ») — mise en page d'une décharge
     papier classique : en-tête de la société, numéro et lieu/date, formule
     « Je soussigné(e)… reconnais avoir reçu… », désignation du courrier,
     cadres de signature « Remis par » / « Reçu par », mention « pour servir
     et valoir ce que de droit ». DomPDF : mise en page en tableaux (pas de
     flex/grid). Les noms connus de GEC sont pré-remplis ; date, heure et
     signatures restent manuscrites si la remise n'est pas encore confirmée.
     2026-10-28 — textes modifiables par un administrateur (Paramètres
     système › Modèle de décharge, Parametre::modeleDecharge()) ; $modele et
     $titreChef peuvent être fournis par l'aperçu (ApercuModeleDechargeController). --}}
@php
    $modele ??= \App\Models\Parametre::actuel()->modeleDecharge();
    $remisPar = $transfert->originalRemisPar ?? $transfert->transferePar;
    // Signataire de la réception s'il a signé dans GEC, sinon la personne qui a accepté.
    $recuPar = $transfert->receptionSigneePar ?? $transfert->decidePar;
    $titreChef ??= $transfert->service?->titreChef() ?? 'Responsable';
    $dateDocument = ($transfert->original_remis_le ?? $transfert->decide_le ?? now())->locale('fr');
    $expediteur = $courrier->expediteur_organisation ?: $courrier->expediteur_nom;
    $reperes = [
        '{recu_par}' => $recuPar?->name ?? '…………………………………………',
        '{titre_chef}' => $titreChef,
        '{service}' => $transfert->service?->nom ?? '…………………………',
        '{remis_par}' => $remisPar?->name ?? '…………………………………………',
        '{date_acceptation}' => $transfert->decide_le?->format('d/m/Y à H:i') ?? '…………………',
        '{reference}' => $courrier->numero_reference,
    ];
    // Texte de l'administrateur échappé PUIS repères remplacés par des valeurs
    // elles-mêmes échappées (en gras dans la formule, sauf le titre).
    $remplir = function (string $texte, bool $gras = false) use ($reperes) {
        $html = nl2br(e($texte), false);
        foreach ($reperes as $repere => $valeur) {
            $html = str_replace($repere, $gras && $repere !== '{titre_chef}' ? '<strong>'.e($valeur).'</strong>' : e($valeur), $html);
        }

        return new \Illuminate\Support\HtmlString($html);
    };
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Décharge {{ $transfert->numero_decharge }}</title>
    <style>
        @page { margin: 28px 40px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        .entete td { vertical-align: top; }
        .entete .logo img { height: 34px; }
        .entete .societe { font-size: 9px; color: #555; margin-top: 4px; }
        .entete .meta { text-align: right; font-size: 10px; color: #333; }
        .entete .numero { display: inline-block; border: 1.5px solid #15296B; color: #15296B; font-weight: bold; padding: 4px 10px; font-size: 11px; margin-bottom: 6px; }
        .filet { border-top: 3px solid #15296B; margin: 10px 0 0; }
        .filet-fin { border-top: 1px solid #c9a227; margin: 2px 0 22px; }
        h1 { text-align: center; font-size: 20px; letter-spacing: 6px; color: #15296B; margin: 0; }
        .sous-titre { text-align: center; font-size: 11px; color: #555; margin: 4px 0 22px; letter-spacing: 1px; }
        .corps { font-size: 12px; text-align: justify; margin-bottom: 14px; }
        .corps strong { color: #000; }
        .section { font-size: 9px; font-weight: bold; color: #15296B; text-transform: uppercase; letter-spacing: 1px; margin: 16px 0 6px; }
        .designation td { border: 1px solid #b8c0d6; padding: 6px 8px; vertical-align: top; }
        .designation td.libelle { width: 30%; background: #eef1f8; color: #333; font-size: 10px; text-transform: uppercase; }
        .trace td { padding: 2px 0; font-size: 10px; color: #333; }
        .trace td.libelle { width: 30%; color: #666; }
        .signatures { margin-top: 18px; }
        .signatures td { width: 50%; vertical-align: top; padding: 0 8px; }
        .signatures td:first-child { padding-left: 0; }
        .signatures td:last-child { padding-right: 0; }
        .cadre { border: 1px solid #15296B; }
        .cadre .titre { background: #15296B; color: #fff; font-weight: bold; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; padding: 5px 8px; }
        .cadre .ligne { padding: 5px 8px; font-size: 10.5px; border-bottom: 1px dotted #b8c0d6; }
        .cadre .ligne span { color: #666; }
        .cadre .zone-signature { height: 78px; padding: 5px 8px; font-size: 9px; color: #888; }
        .cadre .signature { margin-top: 14px; text-align: center; font-family: DejaVu Serif, serif; font-style: italic; font-size: 20px; color: #15296B; }
        .cadre .signature-mention { margin-top: 4px; text-align: center; font-size: 8.5px; color: #555; }
        .observations { height: 22px; border-bottom: 1px dotted #8a93ad; }
        .mention { margin-top: 20px; font-size: 11px; font-style: italic; text-align: center; }
        .pied { position: fixed; bottom: -14px; left: 0; right: 0; border-top: 1px solid #ddd; padding-top: 5px; font-size: 8.5px; color: #888; text-align: center; }
    </style>
</head>
<body>
    <table class="entete">
        <tr>
            <td class="logo">
                <img src="{{ public_path('images/apercu/nsia-logo.png') }}" alt="NSIA Assurances">
                @if (filled($modele['societe']))
                    <div class="societe">{{ $remplir($modele['societe']) }}</div>
                @endif
            </td>
            <td class="meta">
                <div class="numero">DÉCHARGE N° {{ $transfert->numero_decharge }}</div><br>
                {{ $modele['ville'] }}, le {{ $dateDocument->translatedFormat('j F Y') }} à {{ $dateDocument->format('H:i') }}
            </td>
        </tr>
    </table>
    <div class="filet"></div>
    <div class="filet-fin"></div>

    <h1>{{ $modele['titre'] }}</h1>
    @if (filled($modele['sous_titre']))
        <p class="sous-titre">{{ $modele['sous_titre'] }}</p>
    @endif

    <p class="corps">{{ $remplir($modele['formule'], gras: true) }}</p>

    <div class="section">Désignation du courrier</div>
    <table class="designation">
        <tr>
            <td class="libelle">N° de référence</td>
            <td><strong>{{ $courrier->numero_reference }}</strong></td>
        </tr>
        <tr>
            <td class="libelle">Date de réception</td>
            <td>{{ $courrier->date_mouvement?->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="libelle">Expéditeur</td>
            <td>{{ $expediteur ?: '—' }}</td>
        </tr>
        <tr>
            <td class="libelle">Objet</td>
            <td>{{ $courrier->objet ?: '—' }}</td>
        </tr>
        <tr>
            <td class="libelle">Type de document</td>
            <td>{{ $courrier->type_document ?: '—' }}</td>
        </tr>
        <tr>
            <td class="libelle">Pièce(s) jointe(s)</td>
            <td>
                @forelse ($courrier->piecesJointes as $pieceJointe)
                    {{ $pieceJointe->nom_original }}@if (! $loop->last)<br>@endif
                @empty
                    Aucune
                @endforelse
            </td>
        </tr>
    </table>

    <div class="section">Traçabilité GEC</div>
    <table class="trace">
        <tr>
            <td class="libelle">Transféré par</td>
            <td>{{ $transfert->transferePar?->name ?? '—' }} — le {{ $transfert->created_at?->format('d/m/Y à H:i') }}</td>
        </tr>
        <tr>
            <td class="libelle">Accepté par</td>
            <td>{{ $transfert->decidePar?->name ?? '—' }} ({{ $transfert->service?->nom }}) — le {{ $transfert->decide_le?->format('d/m/Y à H:i') }}</td>
        </tr>
        <tr>
            <td class="libelle">Remise de l'original</td>
            <td>
                @if ($transfert->original_remis_le)
                    Confirmée par {{ $transfert->originalRemisPar?->name }} le {{ $transfert->original_remis_le->format('d/m/Y à H:i') }}
                @else
                    Pas encore confirmée dans GEC
                @endif
            </td>
        </tr>
        <tr>
            <td class="libelle">Réception de l'original</td>
            <td>
                @if ($transfert->reception_signee_le)
                    Signée électroniquement par {{ $transfert->receptionSigneePar?->name }} le {{ $transfert->reception_signee_le->format('d/m/Y à H:i') }}
                @else
                    Pas encore signée dans GEC
                @endif
            </td>
        </tr>
    </table>

    {{-- 2026-10-28 (« la date et heure de la décharge et la possibilité de
         mettre le nom comme signature au cas où on ne veut pas imprimer ») —
         signé dans GEC : date, heure et nom (signature électronique) dans le
         cadre ; sinon pointillés et zone vide pour la signature manuscrite.
         2026-11-09 (« only the person receiving the doc should sign, the
         agent shouldn't sign ») — UN SEUL cadre de signature, celui du
         destinataire. L'accueil apparaît à gauche pour information (nom,
         date et heure de la remise), sans zone de signature. --}}
    <table class="signatures">
        <tr>
            <td>
                <div class="cadre">
                    <div class="titre">{{ $remplir($modele['cadre_remis']) }}</div>
                    <div class="ligne"><span>Nom :</span> {{ $remisPar?->name }}</div>
                    <div class="ligne"><span>Date :</span> {{ $transfert->original_remis_le?->format('d/m/Y') ?? '……… / ……… / …………' }} &nbsp; <span>Heure :</span> {{ $transfert->original_remis_le?->format('H:i') ?? '……… h ………' }}</div>
                    <div class="ligne"><span>Remise confirmée dans GEC — sans signature</span></div>
                </div>
            </td>
            <td>
                <div class="cadre">
                    <div class="titre">{{ $remplir($modele['cadre_recu']) }}</div>
                    <div class="ligne"><span>Nom :</span> {{ $recuPar?->name }}</div>
                    <div class="ligne"><span>Date :</span> {{ $transfert->reception_signee_le?->format('d/m/Y') ?? '……… / ……… / …………' }} &nbsp; <span>Heure :</span> {{ $transfert->reception_signee_le?->format('H:i') ?? '……… h ………' }}</div>
                    <div class="zone-signature">
                        @if ($transfert->reception_signee_le)
                            <div class="signature">{{ $recuPar?->name }}</div>
                            <div class="signature-mention">Signé électroniquement dans GEC le {{ $transfert->reception_signee_le->format('d/m/Y à H:i') }}</div>
                        @else
                            Signature et cachet du service
                        @endif
                    </div>
                </div>
            </td>
        </tr>
    </table>

    @if (filled($modele['observations']))
        <div class="section">{{ $modele['observations'] }}</div>
        <div class="observations"></div>
        <div class="observations"></div>
    @endif

    @if (filled($modele['mention']))
        <p class="mention">{{ $remplir($modele['mention']) }}</p>
    @endif

    <div class="pied">
        Décharge {{ $transfert->numero_decharge }} — courrier {{ $courrier->numero_reference }} — générée par GEC le {{ now()->format('d/m/Y à H:i') }}.
        {{ $remplir($modele['pied']) }}
    </div>
</body>
</html>
