<?php

namespace App\Jobs;

use App\Models\ApercuDocument;
use App\Models\Courrier;
use App\Models\PieceJointe;
use App\Services\ApercuSecurise;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Module 9 — rattrapage horaire de l'aperçu sécurisé (2026-11-09) : les
// documents déposés avant cette fonctionnalité sont préparés par petits
// lots, pour qu'ils s'affichent sans attente à leur première consultation.
class PreparerApercusManquantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAR_PASSAGE = 30;

    public int $tries = 3;

    public function handle(): void
    {
        $prepares = fn (string $type) => ApercuDocument::where('fichier_type', $type)->select('fichier_id');

        Courrier::query()->whereNotNull('fichier_path')->whereNotIn('id', $prepares(ApercuSecurise::TYPE_COURRIER))
            ->latest('id')->limit(self::PAR_PASSAGE)->pluck('id')
            ->each(fn (int $id) => PreparerApercuJob::dispatch(ApercuSecurise::TYPE_COURRIER, $id));

        PieceJointe::query()->whereNotIn('id', $prepares(ApercuSecurise::TYPE_PIECE_JOINTE))
            ->latest('id')->limit(self::PAR_PASSAGE)->pluck('id')
            ->each(fn (int $id) => PreparerApercuJob::dispatch(ApercuSecurise::TYPE_PIECE_JOINTE, $id));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du rattrapage des aperçus sécurisés', ['erreur' => $exception?->getMessage()]);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
