<?php

namespace App\Jobs;

use App\Models\Courrier;
use App\Models\EmpreinteFichier;
use App\Models\PieceJointe;
use App\Services\IntegriteFichiers;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Module 9 — vérification quotidienne de l'intégrité des fichiers
// (2026-10-30, conformité de l'archivage). Scelle d'abord les fichiers qui
// n'ont pas encore d'empreinte (fichiers antérieurs à cette fonctionnalité,
// ou scellement manqué), puis recalcule chaque empreinte et la compare.
// Une différence ou un fichier disparu déclenche une alerte (journal
// d'erreurs + historique du courrier, voir IntegriteFichiers::alerter()).
// Planifié chaque nuit (bootstrap/app.php) ; lançable depuis Sécurité & Audit.
class VerifierIntegriteFichiersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CLE_DERNIERE_VERIFICATION = 'gec.integrite.derniere_verification';

    public int $tries = 3;

    public $timeout = 3600;

    public function handle(IntegriteFichiers $integrite): void
    {
        $this->scellerManquants($integrite);

        EmpreinteFichier::query()
            ->where('statut', '!=', EmpreinteFichier::STATUT_DETRUIT)
            ->chunkById(200, function ($empreintes) use ($integrite) {
                foreach ($empreintes as $empreinte) {
                    try {
                        $integrite->verifier($empreinte);
                    } catch (Throwable $e) {
                        // Un fichier en échec (stockage injoignable…) ne bloque jamais les autres.
                        report($e);
                    }
                }
            });

        Cache::forever(self::CLE_DERNIERE_VERIFICATION, now()->toIso8601String());
    }

    private function scellerManquants(IntegriteFichiers $integrite): void
    {
        $scelles = fn (string $type) => EmpreinteFichier::where('fichier_type', $type)->select('fichier_id');

        Courrier::query()->whereNotNull('fichier_path')->whereNotIn('id', $scelles(EmpreinteFichier::TYPE_COURRIER))
            ->select(['id', 'fichier_path'])
            ->chunkById(200, fn ($courriers) => $courriers->each(fn ($c) => rescue(fn () => $integrite->sceller(EmpreinteFichier::TYPE_COURRIER, $c->id, $c->fichier_path))));

        Courrier::query()->whereNotNull('fichier_pdfa_path')->whereNotIn('id', $scelles(EmpreinteFichier::TYPE_COURRIER_PDFA))
            ->select(['id', 'fichier_pdfa_path'])
            ->chunkById(200, fn ($courriers) => $courriers->each(fn ($c) => rescue(fn () => $integrite->sceller(EmpreinteFichier::TYPE_COURRIER_PDFA, $c->id, $c->fichier_pdfa_path))));

        PieceJointe::query()->whereNotIn('id', $scelles(EmpreinteFichier::TYPE_PIECE_JOINTE))
            ->select(['id', 'fichier_path'])
            ->chunkById(200, fn ($pieces) => $pieces->each(fn ($p) => rescue(fn () => $integrite->sceller(EmpreinteFichier::TYPE_PIECE_JOINTE, $p->id, $p->fichier_path))));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec de la vérification d\'intégrité des fichiers', ['erreur' => $exception?->getMessage()]);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
