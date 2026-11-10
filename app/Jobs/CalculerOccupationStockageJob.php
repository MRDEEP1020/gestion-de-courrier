<?php

namespace App\Jobs;

use App\Models\OccupationStockage;
use App\Services\StockageChiffre;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter;
use Throwable;

// Module 9 — occupation du stockage (2026-11-09, demande « on doit aussi
// pouvoir voir l'espace de stockage », voir DECISIONS.md) : parcourt le
// stockage principal et la copie de secours (liste des fichiers seulement,
// jamais leur contenu), totalise par type, compte les fichiers absents de la
// sauvegarde et relève l'espace libre du disque. Règle n°1 : lister tout le
// stockage ne se fait jamais dans une requête. Chaque nuit (avec rattrapage)
// et sur demande depuis Sécurité & Audit › Stockage.
class CalculerOccupationStockageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public $timeout = 1800;

    public int $uniqueFor = 900;

    public function handle(): void
    {
        $principal = $this->inventaire('s3');
        $parType = array_fill_keys(array_keys(OccupationStockage::TYPES), ['fichiers' => 0, 'octets' => 0]);

        foreach ($principal as $chemin => $octets) {
            $type = self::type($chemin);
            $parType[$type]['fichiers']++;
            $parType[$type]['octets'] += $octets;
        }

        $sauvegarde = filled(config('filesystems.disks.s3_backup.bucket')) ? $this->inventaire('s3_backup') : null;

        // Les pages d'aperçu se refont à partir des originaux : elles ne
        // sont pas copiées vers la sauvegarde et ne comptent pas comme manquantes.
        $manquants = $sauvegarde === null ? null : collect(array_keys(array_diff_key($principal, $sauvegarde)))
            ->reject(fn (string $chemin) => self::type($chemin) === 'apercus')
            ->count();

        $chemin = config('services.stockage.chemin_disque');
        $disque = filled($chemin) && is_dir($chemin);

        OccupationStockage::create([
            'calcule_le' => now(),
            'fichiers' => count($principal),
            'octets' => array_sum($principal),
            'par_type' => $parType,
            'sauvegarde_fichiers' => $sauvegarde === null ? null : count($sauvegarde),
            'sauvegarde_octets' => $sauvegarde === null ? null : array_sum($sauvegarde),
            'sauvegarde_manquants' => $manquants,
            'disque_libre' => $disque ? (int) disk_free_space($chemin) : null,
            'disque_total' => $disque ? (int) disk_total_space($chemin) : null,
        ]);
    }

    // Type d'un fichier d'après son chemin (Règle n°4 : nommage prévisible).
    public static function type(string $chemin): string
    {
        $segments = explode('/', $chemin);

        return match (true) {
            $segments[0] === 'apercus' => 'apercus',
            $segments[0] === 'brouillons' => 'brouillons',
            $segments[0] === 'avatars' => 'avatars',
            $segments[0] === 'courriers' && str_ends_with(strtolower($chemin), '-pdfa.pdf') => 'pdfa',
            // courriers/{année}/{service}/{référence}/{uuid}.{ext}
            $segments[0] === 'courriers' && count($segments) >= 5 => 'pieces_jointes',
            $segments[0] === 'courriers' => 'documents',
            default => 'autres',
        };
    }

    /**
     * Taille stockée de chaque fichier (contenu chiffré compris), sans lire
     * aucun fichier : une seule liste par disque.
     *
     * @return array<string, int>
     */
    private function inventaire(string $disque): array
    {
        $adaptateur = Storage::disk($disque)->getAdapter();

        if ($adaptateur instanceof StockageChiffre) {
            $adaptateur = $adaptateur->interne();
        }

        /** @var FilesystemAdapter $adaptateur */
        $fichiers = [];

        foreach ($adaptateur->listContents('', true) as $element) {
            if ($element->isFile()) {
                $fichiers[$element->path()] = (int) $element->fileSize();
            }
        }

        return $fichiers;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du calcul de l\'occupation du stockage', ['erreur' => $exception?->getMessage()]);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
