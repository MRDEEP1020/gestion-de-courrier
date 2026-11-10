<?php

namespace App\Http\Controllers;

use App\Models\TransfertService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TransfertDechargeController extends Controller
{
    // Module 4/5 (2026-10-27, « arrange the décharge design as a real
    // décharge ») — décharge imprimable de la remise de l'original papier au
    // service qui a ACCEPTÉ le transfert : document à signer par l'accueil
    // (remis par) et le service (reçu par), même mécanisme PDF que le
    // bordereau et l'accusé de réception. Mêmes droits que la fiche du
    // courrier, où la décharge est déjà affichée (Règle n°6 : vérifié ici).
    public function __invoke(TransfertService $transfert): Response
    {
        $courrier = $transfert->courrier;

        Gate::authorize('view', $courrier);

        // Pas de décharge tant que le service n'a pas accepté le transfert.
        if (! $transfert->estAccepte() || ! $transfert->numero_decharge) {
            throw new NotFoundHttpException;
        }

        $transfert->loadMissing('service', 'transferePar', 'decidePar', 'originalRemisPar', 'receptionSigneePar');
        $courrier->loadMissing('piecesJointes');

        $pdf = Pdf::loadView('pdf.decharge-remise', ['transfert' => $transfert, 'courrier' => $courrier])
            ->setPaper('a4');

        return $pdf->stream("decharge-{$transfert->numero_decharge}.pdf");
    }
}
