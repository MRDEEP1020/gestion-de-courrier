<?php

namespace App\Jobs;

use App\Services\ChiffrementFichiers;
use App\Services\StockageChiffre;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use RuntimeException;
use Throwable;

// Module 9 — chiffre en place les fichiers encore en clair (2026-11-09, voir
// DECISIONS.md « Fichiers : chiffrement, aperçu sécurisé, aucun
// téléchargement ») : tout ce qui a été déposé avant le chiffrement, sur le
// stockage principal ET sur la copie de secours. Le contenu ne change pas
// (empreintes SHA-256 inchangées, elles portent sur le contenu en clair) ;
// seule sa forme stockée change. Chaque fichier est vérifié deux fois :
// déchiffrement contrôlé avant écriture, relecture après — en cas d'écart,
// le contenu d'origine est remis. Rejouable sans risque (un fichier déjà
// chiffré est ignoré). Lancé depuis Sécurité & Audit › Système.
class ChiffrerFichiersExistantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CLE_ETAT = 'gec.chiffrement.etat';

    public int $tries = 3;

    public $timeout = 3600;

    public function handle(ChiffrementFichiers $chiffrement): void
    {
        if (! $chiffrement->cleConfiguree()) {
            throw new RuntimeException('Aucune clé de chiffrement des fichiers (GEC_FICHIERS_CLE) : conversion impossible.');
        }

        $bilan = ['chiffres' => 0, 'deja_chiffres' => 0, 'echecs' => 0];

        foreach (['s3', 's3_backup'] as $disque) {
            if ($disque === 's3_backup' && blank(config('filesystems.disks.s3_backup.bucket'))) {
                continue;
            }

            $adaptateur = Storage::disk($disque)->getAdapter();

            if (! $adaptateur instanceof StockageChiffre) {
                continue;
            }

            $brut = $adaptateur->interne();

            foreach ($brut->listContents('', true) as $element) {
                if (! $element->isFile()) {
                    continue;
                }

                try {
                    $resultat = $this->chiffrer($brut, $element->path(), $chiffrement);
                    $bilan[$resultat]++;
                } catch (Throwable $e) {
                    $bilan['echecs']++;
                    Log::error('Fichier non chiffré', ['disque' => $disque, 'chemin' => $element->path(), 'erreur' => $e->getMessage()]);
                }
            }
        }

        Cache::forever(self::CLE_ETAT, $bilan + ['le' => now()->toIso8601String()]);
    }

    private function chiffrer($brut, string $chemin, ChiffrementFichiers $chiffrement): string
    {
        $clair = $brut->read($chemin);

        if (ChiffrementFichiers::estChiffre($clair)) {
            return 'deja_chiffres';
        }

        $empreinte = hash('sha256', $clair);
        $chiffre = $chiffrement->chiffrerChaine($clair);

        if (! hash_equals($empreinte, hash('sha256', $chiffrement->dechiffrerChaine($chiffre)))) {
            throw new RuntimeException('Contrôle avant écriture échoué');
        }

        $type = (new ExtensionMimeTypeDetector)->detectMimeTypeFromPath($chemin) ?? 'application/octet-stream';
        $config = new Config(['ContentType' => $type, 'mimetype' => $type]);
        $brut->write($chemin, $chiffre, $config);

        try {
            $relu = $chiffrement->dechiffrerChaine($brut->read($chemin));
        } catch (Throwable) {
            $relu = null;
        }

        if ($relu === null || ! hash_equals($empreinte, hash('sha256', $relu))) {
            $brut->write($chemin, $clair, $config);
            Log::critical('Chiffrement annulé : relecture non conforme, contenu d\'origine remis', ['chemin' => $chemin]);

            throw new RuntimeException('Relecture non conforme');
        }

        return 'chiffres';
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du chiffrement des fichiers existants', ['erreur' => $exception?->getMessage()]);
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
