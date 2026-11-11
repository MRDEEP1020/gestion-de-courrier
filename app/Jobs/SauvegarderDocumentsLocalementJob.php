<?php

namespace App\Jobs;

use App\Services\SauvegardeLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

// 2026-11-11 — copie de secours sur le PC, rangée par dossier (voir
// App\Services\SauvegardeLocale). Chaque heure, et après chaque changement
// (dossier créé/renommé/supprimé, courrier classé…, voir
// SauvegardeLocale::signalerChangement()) ; ne fait rien tant que le dossier
// ou le mot de passe n'est pas renseigné.
// Une seule synchronisation EN ATTENTE à la fois (unique jusqu'à son
// démarrage : un changement pendant qu'elle tourne en relance une autre) et
// jamais deux EN MÊME TEMPS (même manifeste) — la seconde repasse 30 s après.
class SauvegarderDocumentsLocalementJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public $timeout = 3600;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sauvegarde-locale'))->releaseAfter(30)->expireAfter(3600)];
    }

    public function handle(SauvegardeLocale $sauvegarde): void
    {
        if (! $sauvegarde->active()) {
            return;
        }

        $stats = $sauvegarde->synchroniser();

        // Affiché dans Sécurité & Audit › Stockage.
        Cache::forever(SauvegardeLocale::CLE_ETAT, [...$stats, 'le' => now()->toIso8601String()]);
    }
}
