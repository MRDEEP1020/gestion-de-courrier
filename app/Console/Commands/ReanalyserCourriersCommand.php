<?php

namespace App\Console\Commands;

use App\Jobs\ReanalyserCourriersJob;
use Illuminate\Console\Command;

class ReanalyserCourriersCommand extends Command
{
    protected $signature = 'courriers:reanalyser
                            {--tous : Inclure aussi les courriers dont le classement a déjà été validé ou ignoré}';

    protected $description = 'Module 3 — relance le classement automatique des courriers existants (via la queue indexation)';

    public function handle(): int
    {
        $tous = (bool) $this->option('tous');
        $nombre = ReanalyserCourriersJob::cibles($tous)->count();

        // Rien n'est traité ici (Règle n°1) : le job parcourt la table et
        // délègue chaque courrier à IndexCourrierJob, consommé par le worker.
        ReanalyserCourriersJob::dispatch($tous)->onQueue('indexation');

        $this->info("Réanalyse planifiée pour {$nombre} courrier(s) — traitée par le worker de queue (queue « indexation »).");

        return self::SUCCESS;
    }
}
