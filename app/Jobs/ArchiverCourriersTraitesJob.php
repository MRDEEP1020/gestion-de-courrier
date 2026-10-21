<?php

namespace App\Jobs;

use App\Models\Courrier;
use App\Services\WorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Module 9 — "un courrier clôturé (statut « Traité ») est automatiquement
// transféré vers l'espace d'archivage" (specifications-modules-GEC.md).
// PREMIÈRE tâche planifiée de ce projet (voir bootstrap/app.php,
// ->withSchedule() — le Scheduler n'avait jamais été câblé avant ce
// changement, voir DECISIONS.md). Règle n°1 : Job, jamais une vérification
// à chaque chargement de page.
class ArchiverCourriersTraitesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(WorkflowService $workflowService): void
    {
        Courrier::query()
            ->where('statut', 'traite')
            ->chunkById(100, function ($courriers) use ($workflowService) {
                foreach ($courriers as $courrier) {
                    try {
                        $workflowService->archiverAutomatiquement($courrier);
                    } catch (Throwable $e) {
                        // Règle n°1 — un courrier en échec ne doit jamais
                        // interrompre le traitement des autres du même lot.
                        report($e);
                        Log::error('Échec de l\'archivage automatique', [
                            'courrier_id' => $courrier->id,
                            'numero_reference' => $courrier->numero_reference,
                            'erreur' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du job ArchiverCourriersTraitesJob', [
            'erreur' => $exception?->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
