<?php

namespace App\Jobs;

use App\Events\ApercuPret;
use App\Services\ApercuSecurise;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Module 9 — prépare les pages de l'aperçu sécurisé d'un document
// (2026-11-09, voir App\Services\ApercuSecurise). Règle n°1 : Ghostscript ne
// tourne jamais dans une requête. Déclenché à l'arrivée d'un fichier
// (modèles Courrier, PieceJointe, CourrierBrouillon), à la première
// consultation d'un document ancien, et par le rattrapage horaire.
// ShouldBeUnique : plusieurs consultations simultanées d'un document pas
// encore prêt ne lancent qu'un seul rendu.
class PreparerApercuJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public string $type, public int $id)
    {
        $this->onQueue('ocr');
    }

    public function uniqueId(): string
    {
        return "{$this->type}:{$this->id}";
    }

    public function handle(ApercuSecurise $apercus): void
    {
        $apercus->preparer($this->type, $this->id);

        $cible = $apercus->cible($this->type, $this->id);

        if ($cible !== null) {
            // Temps réel indisponible (Reverb arrêté) : la visionneuse garde
            // son bouton « Réessayer », le rendu lui-même a réussi.
            rescue(fn () => ApercuPret::dispatch($this->type, $this->id, $apercus->canal($cible)), report: true);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec de la préparation de l\'aperçu sécurisé', ['type' => $this->type, 'id' => $this->id, 'erreur' => $exception?->getMessage()]);
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }
}
