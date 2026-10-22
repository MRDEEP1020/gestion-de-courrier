<?php

namespace App\Jobs;

use App\Models\Courrier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReanalyserCourriersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Module 3 — relance le classement automatique sur les courriers existants :
    // après création/modification d'une règle par l'administrateur, ou pour les
    // courriers enregistrés avant la mise en service du module. Un seul job
    // "chef d'orchestre" qui parcourt la table par paquets (Règle n°3) et
    // délègue chaque courrier à IndexCourrierJob (Règle n°1) — jamais une boucle
    // sur toute la table dans une action Livewire.
    public function __construct(public bool $tous = false) {}

    /**
     * Courriers concernés : par défaut ceux sans décision de l'agent
     * (non classés ou proposition en attente). Avec $tous, également les
     * validés/ignorés — IndexCourrierJob ne les touche que si la proposition
     * change réellement.
     */
    public static function cibles(bool $tous = false): Builder
    {
        return Courrier::query()
            ->when(! $tous, fn (Builder $query) => $query->whereIn('classement_statut', ['non_classe', 'propose']));
    }

    public function handle(): void
    {
        $total = 0;

        self::cibles($this->tous)->select('id')->chunkById(200, function ($courriers) use (&$total) {
            foreach ($courriers as $courrier) {
                IndexCourrierJob::dispatch($courrier)->onQueue('indexation');
                $total++;
            }
        });

        Log::info('Réanalyse du classement automatique planifiée', ['courriers' => $total, 'tous' => $this->tous]);
    }

    // Règle n°1 — échec loggé de façon exploitable.
    public function failed(?Throwable $exception): void
    {
        Log::error('Échec de la réanalyse du classement automatique', [
            'tous' => $this->tous,
            'erreur' => $exception?->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
