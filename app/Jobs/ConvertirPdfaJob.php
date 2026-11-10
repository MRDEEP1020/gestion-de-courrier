<?php

namespace App\Jobs;

use App\Models\Courrier;
use App\Services\ConvertisseurPdfa;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Module 9 — produit la copie d'archive PDF/A d'un courrier archivé
// (2026-10-30, conformité de l'archivage). Déclenché à l'archivage
// (WorkflowService::archiverAutomatiquement()) et repris pour les archives
// antérieures par ConvertirArchivesPdfaJob. Règle n°1 : en file d'attente.
class ConvertirPdfaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public $timeout = 600;

    public function __construct(public int $courrierId) {}

    public function handle(ConvertisseurPdfa $convertisseur): void
    {
        $courrier = Courrier::find($this->courrierId);

        if ($courrier === null || $courrier->statut !== 'archive' || $courrier->fichier_pdfa_path) {
            return;
        }

        $chemin = $convertisseur->convertir($courrier);

        if ($chemin === null) {
            return;
        }

        // Enregistrement normal (pas saveQuietly) : déclenche le scellement
        // de l'empreinte de la copie (Courrier::booted()).
        $courrier->update(['fichier_pdfa_path' => $chemin]);

        // Règle n°4 (complétée) — même copie de secours que tout fichier.
        ReplicateFichierJob::dispatch($chemin);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec de la conversion PDF/A', ['courrier_id' => $this->courrierId, 'erreur' => $exception?->getMessage()]);
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }
}
