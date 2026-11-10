<?php

namespace App\Jobs;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Services\ConservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Module 9 — recalcule l'échéance de conservation de toutes les archives
// (2026-10-30, conformité de l'archivage) : après un changement des durées
// dans Sécurité & Audit, chaque nuit, et pour les archives antérieures à
// cette fonctionnalité (date d'archivage reprise de l'historique, sinon de
// la dernière modification). Règle n°1 : en file d'attente.
class RecalculerConservationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Dernier passage complet, lu par le rattrapage du planificateur
    // (bootstrap/app.php) quand la tâche de 03:00 a été manquée.
    public const CLE_DERNIER_PASSAGE = 'gec.conservation.dernier_recalcul';

    public int $tries = 3;

    public function handle(ConservationService $conservation): void
    {
        Courrier::query()->where('statut', 'archive')->chunkById(200, function ($courriers) use ($conservation) {
            foreach ($courriers as $courrier) {
                if ($courrier->archive_le === null) {
                    $archiveLe = CourrierHistorique::where('courrier_id', $courrier->id)
                        ->where('action', 'archivage_automatique')->min('created_at');
                    $courrier->forceFill(['archive_le' => $archiveLe ?? $courrier->updated_at])->saveQuietly();
                }

                $conservation->appliquer($courrier);
            }
        });

        Cache::forever(self::CLE_DERNIER_PASSAGE, now()->toIso8601String());
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du recalcul des échéances de conservation', ['erreur' => $exception?->getMessage()]);
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }
}
