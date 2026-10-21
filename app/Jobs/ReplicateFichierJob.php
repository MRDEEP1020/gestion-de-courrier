<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReplicateFichierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Règle n°4 (complétée) — "stockage local en priorité + réplication
    // automatique vers un stockage cloud de secours" (confirmé par le client,
    // voir DECISIONS.md). L'écriture primaire (disque `s3`, local/on-premise)
    // n'attend jamais cette copie : elle part en Job après coup, jamais dans
    // le cycle requête/réponse (Règle n°1). Un chemin, pas un modèle complet,
    // pour rester utilisable aussi bien pour le document principal (Module 2)
    // que pour une pièce jointe (Module 1) sans dépendre de leur relation.
    public function __construct(public string $chemin) {}

    public function handle(): void
    {
        if (blank(config('filesystems.disks.s3_backup.bucket'))) {
            // Aucun stockage de secours configuré (dev sans identifiants cloud,
            // voir .env) — jamais bloquant, jamais un échec (Règle n°1).
            return;
        }

        if (! Storage::disk('s3')->exists($this->chemin)) {
            // Le fichier primaire a disparu entre le dispatch et l'exécution
            // (cas limite) : rien à répliquer, pas une panne à re-tenter.
            Log::warning('Réplication ignorée : fichier introuvable sur le disque primaire', ['chemin' => $this->chemin]);

            return;
        }

        // Flux plutôt que chargement en mémoire : les documents scannés (PDF/TIFF
        // haute résolution) peuvent dépasser plusieurs Mo (Règle n°3, esprit).
        $flux = Storage::disk('s3')->readStream($this->chemin);

        try {
            Storage::disk('s3_backup')->writeStream($this->chemin, $flux);
        } finally {
            if (is_resource($flux)) {
                fclose($flux);
            }
        }
    }

    // Règle n°1 — échec loggé de façon exploitable. Le document reste
    // disponible sur le disque primaire : seule la copie de secours manque.
    public function failed(?Throwable $exception): void
    {
        Log::error('Échec de la réplication vers le stockage de secours', [
            'chemin' => $this->chemin,
            'erreur' => $exception?->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
