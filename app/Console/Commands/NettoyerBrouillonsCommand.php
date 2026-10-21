<?php

namespace App\Console\Commands;

use App\Models\CourrierBrouillon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class NettoyerBrouillonsCommand extends Command
{
    protected $signature = 'courriers:nettoyer-brouillons {--jours=7 : Âge minimum (en jours) avant suppression}';

    // Module 1/2 — brouillons scannés jamais finalisés (voir DECISIONS.md
    // "Flux scan-first") : un fichier orphelin est un problème d'hygiène, pas
    // de courrier perdu (le brouillon reste visible dans "Autres documents
    // scannés en attente" de son auteur tant qu'il n'est pas nettoyé). Volontairement
    // PAS branchée sur le Scheduler par défaut — à ajouter dans routes/console.php
    // si le volume observé en pilote le justifie.
    protected $description = 'Module 1/2 — supprime les brouillons de scan non finalisés depuis plus de N jours (fichier + ligne)';

    public function handle(): int
    {
        $seuil = now()->subDays((int) $this->option('jours'));

        $brouillons = CourrierBrouillon::query()
            ->whereNull('finalise_le')
            ->where('created_at', '<', $seuil)
            ->get();

        foreach ($brouillons as $brouillon) {
            try {
                Storage::disk('s3')->delete($brouillon->fichier_path);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Nettoyage brouillon : suppression du fichier échouée, ligne conservée', ['brouillon_id' => $brouillon->id]);

                continue;
            }

            $brouillon->delete();
        }

        $this->info("{$brouillons->count()} brouillon(s) de plus de {$this->option('jours')} jour(s) supprimé(s).");

        return self::SUCCESS;
    }
}
