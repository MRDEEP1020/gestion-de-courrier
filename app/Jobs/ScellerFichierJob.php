<?php

namespace App\Jobs;

use App\Services\IntegriteFichiers;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Module 9 — scelle l'empreinte SHA-256 d'un fichier juste après son
// enregistrement (ou la vérifie s'il vient d'être déplacé), 2026-10-30 :
// déclenché par les modèles (Courrier::booted(), PieceJointe::booted()) pour
// couvrir tous les points d'écriture sans en oublier un. Règle n°1 : en file
// d'attente, jamais dans la requête.
class ScellerFichierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // `$remplacement` : nouvelle numérisation du même document (2026-11-09),
    // voir IntegriteFichiers::sceller().
    public function __construct(public string $type, public int $id, public bool $remplacement = false) {}

    public function handle(IntegriteFichiers $integrite): void
    {
        $chemin = $integrite->cheminActuel($this->type, $this->id);

        if ($chemin) {
            $integrite->sceller($this->type, $this->id, $chemin, $this->remplacement);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du scellement d\'empreinte', ['type' => $this->type, 'id' => $this->id, 'erreur' => $exception?->getMessage()]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
