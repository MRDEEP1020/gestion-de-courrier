<?php

namespace App\Http\Controllers;

use App\Models\Courrier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CourrierAccuseReceptionController extends Controller
{
    // Module 1 — "Cas particulier : courrier confidentiel" (specifications-modules-GEC.md) :
    // "un accusé de réception est généré et remis au déposant... c'est la
    // seule preuve documentée de ce courrier" — document dédié, distinct du
    // bordereau général (CourrierBordereauController), volontairement minimal
    // (jamais l'objet réel, jamais le contenu — il n'y en a pas ici).
    public function __invoke(Courrier $courrier): Response
    {
        Gate::authorize('imprimerAccuseReception', $courrier);

        // N'a de sens que pour un courrier réellement passé par le flux
        // confidentiel — pas un document générique consultable par ID pour
        // n'importe quel courrier.
        if ($courrier->confidentialite <= 1) {
            throw new NotFoundHttpException;
        }

        $auteur = $courrier->historiques()->where('action', 'creation')->first()?->auteur;

        $pdf = Pdf::loadView('pdf.accuse-reception', ['courrier' => $courrier, 'auteur' => $auteur])
            ->setPaper('a5');

        return $pdf->stream("accuse-reception-{$courrier->numero_reference}.pdf");
    }
}
