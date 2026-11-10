<?php

namespace App\Services;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;

// Module 9 — disque chiffré (2026-11-09, voir DECISIONS.md « Fichiers :
// chiffrement, aperçu sécurisé, aucun téléchargement »). Enveloppe
// l'adaptateur S3 des disques `s3` et `s3_backup` (pilote « s3-chiffre »,
// AppServiceProvider) : tout ce qui est écrit est chiffré, tout ce qui est
// lu est déchiffré, sans toucher un seul appelant (dépôts, OCR, PDF/A,
// copies de secours, déplacements à l'acceptation, avatars). Un fichier
// encore en clair (antérieur au chiffrement) reste lisible jusqu'à sa
// conversion par ChiffrerFichiersExistantsJob. Déplacer ou copier un
// fichier recopie le contenu chiffré tel quel.
class StockageChiffre implements FilesystemAdapter
{
    // Au-delà, le flux déchiffré déborde de la mémoire vers un fichier
    // temporaire (php://temp).
    private const MEMOIRE_TAMPON = 4 * 1024 * 1024;

    public function __construct(
        private FilesystemAdapter $interne,
        private ChiffrementFichiers $chiffrement,
    ) {}

    // Adaptateur sous-jacent (contenu brut, chiffré) : seulement pour la
    // conversion des fichiers existants.
    public function interne(): FilesystemAdapter
    {
        return $this->interne;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->interne->write($path, $this->chiffrement->chiffrerChaine($contents), $this->avecTypeMime($path, $config));
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $chiffre = fopen('php://temp/maxmemory:'.self::MEMOIRE_TAMPON, 'w+b');

        try {
            $this->chiffrement->chiffrerFlux($contents, $chiffre);
            rewind($chiffre);
            $this->interne->writeStream($path, $chiffre, $this->avecTypeMime($path, $config));
        } finally {
            if (is_resource($chiffre)) {
                fclose($chiffre);
            }
        }
    }

    public function read(string $path): string
    {
        $brut = $this->interne->read($path);

        return ChiffrementFichiers::estChiffre($brut) ? $this->chiffrement->dechiffrerChaine($brut) : $brut;
    }

    public function readStream(string $path)
    {
        $brut = $this->interne->readStream($path);
        $sortie = fopen('php://temp/maxmemory:'.self::MEMOIRE_TAMPON, 'w+b');

        try {
            $debut = (string) stream_get_contents($brut, ChiffrementFichiers::TAILLE_ENTETE);

            if (ChiffrementFichiers::estChiffre($debut)) {
                $this->chiffrement->dechiffrerFlux($brut, $sortie, $debut);
            } else {
                fwrite($sortie, $debut);
                stream_copy_to_stream($brut, $sortie);
            }
        } catch (\Throwable $e) {
            fclose($sortie);

            throw $e;
        } finally {
            if (is_resource($brut)) {
                fclose($brut);
            }
        }

        rewind($sortie);

        return $sortie;
    }

    // Taille du contenu en clair (en-tête de réponse HTTP d'un avatar, par
    // exemple), jamais celle du contenu chiffré.
    public function fileSize(string $path): FileAttributes
    {
        $attributs = $this->interne->fileSize($path);
        $debut = $this->debut($path);

        if (! ChiffrementFichiers::estChiffre($debut)) {
            return $attributs;
        }

        return new FileAttributes($path, $this->chiffrement->tailleClaire($debut, (int) $attributs->fileSize()));
    }

    public function fileExists(string $path): bool
    {
        return $this->interne->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->interne->directoryExists($path);
    }

    public function delete(string $path): void
    {
        $this->interne->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->interne->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->interne->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->interne->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->interne->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->interne->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->interne->lastModified($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->interne->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->interne->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->interne->copy($source, $destination, $config);
    }

    /**
     * @return string les premiers octets bruts (détection du chiffrement)
     */
    private function debut(string $path): string
    {
        $flux = $this->interne->readStream($path);

        try {
            return (string) stream_get_contents($flux, ChiffrementFichiers::TAILLE_ENTETE);
        } finally {
            if (is_resource($flux)) {
                fclose($flux);
            }
        }
    }

    // Le type MIME se déduit du contenu en clair : sur le contenu chiffré,
    // la détection automatique du stockage tomberait au hasard. On le fixe
    // d'après l'extension du fichier (pdf, jpg, png…).
    private function avecTypeMime(string $path, Config $config): Config
    {
        $type = (new ExtensionMimeTypeDetector)->detectMimeTypeFromPath($path) ?? 'application/octet-stream';

        return $config->withDefaults(['ContentType' => $type, 'mimetype' => $type]);
    }
}
