<?php

namespace App\Http\Controllers;

use App\Models\Courrier;
use App\Models\CourrierConsultation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CourrierBordereauController extends Controller
{
    // Module 1 — bordereau d'enregistrement / accusé de réception imprimable (PDF).
    // Consultation + courriers.imprimer_bordereau (2026-09-23).
    public function __invoke(Courrier $courrier): Response
    {
        Gate::authorize('imprimerBordereau', $courrier);

        $courrier->loadMissing('service');

        $pdf = Pdf::loadView('pdf.bordereau', ['courrier' => $courrier])
            ->setPaper('a4');

        // 2026-10-30 — journal des consultations (conformité de l'archivage).
        CourrierConsultation::enregistrer($courrier->id, 'impression');

        return $pdf->stream("bordereau-{$courrier->numero_reference}.pdf");
    }
}
