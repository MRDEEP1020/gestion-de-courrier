<?php

namespace App\Jobs;

use App\Models\Courrier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Module 9 — rattrapage des copies PDF/A (2026-10-30, conformité de
// l'archivage) : archives antérieures à cette fonctionnalité, ou conversion
// échouée. Chaque heure, au plus PAR_PASSAGE conversions mises en file
// (jamais toutes d'un coup, pour ne pas saturer la file).
class ConvertirArchivesPdfaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAR_PASSAGE = 25;

    public int $tries = 1;

    public function handle(): void
    {
        if (! config('services.archivage.pdfa_auto') || ! config('services.ghostscript.binary')) {
            return;
        }

        Courrier::query()
            ->where('statut', 'archive')
            ->whereNull('fichier_pdfa_path')
            ->where('fichier_path', 'like', '%.pdf')
            ->limit(self::PAR_PASSAGE)
            ->pluck('id')
            ->each(fn ($id) => ConvertirPdfaJob::dispatch($id));
    }
}
