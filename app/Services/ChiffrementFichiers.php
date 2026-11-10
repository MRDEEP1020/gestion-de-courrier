<?php

namespace App\Services;

use RuntimeException;

// Module 9 — chiffrement des fichiers au repos (2026-11-09, demande « le
// cryptage partout », voir DECISIONS.md « Fichiers : chiffrement, aperçu
// sécurisé, aucun téléchargement »).
//
// AES-256-GCM par blocs (1 Mio par défaut), construction « STREAM » : le
// nonce de chaque bloc = préfixe aléatoire du fichier ‖ numéro du bloc ‖
// drapeau « dernier bloc ». Chiffrement et déchiffrement en flux, à mémoire
// constante, et toute modification, troncature (bloc final retiré) ou
// permutation de blocs fait échouer le déchiffrement. Clé dédiée
// (GEC_FICHIERS_CLE), distincte d'APP_KEY et de la base : une copie du
// stockage ou de la sauvegarde seule est illisible.
//
// Format : « GECF » ‖ version (1 octet) ‖ identifiant de clé (1) ‖ log2 de la
// taille de bloc (1) ‖ préfixe de nonce (7) = 14 octets, authentifiés avec
// chaque bloc (données associées) ; puis chaque bloc chiffré suivi de son
// étiquette de 16 octets. L'identifiant de clé permet d'en changer plus
// tard sans perdre les anciens fichiers (GEC_FICHIERS_CLES_PRECEDENTES).
class ChiffrementFichiers
{
    public const MAGIE = 'GECF';

    public const VERSION = 1;

    public const TAILLE_ENTETE = 14;

    public const TAILLE_ETIQUETTE = 16;

    private const ALGORITHME = 'aes-256-gcm';

    /**
     * @param  array<int, string>  $cles  identifiant (1 à 255) => clé brute de 32 octets
     */
    public function __construct(
        private array $cles,
        private ?int $cleCourante,
        private int $log2Bloc = 20,
    ) {}

    public static function depuisConfiguration(): self
    {
        $config = config('services.chiffrement');
        $cles = [];

        foreach (explode(',', (string) ($config['cles_precedentes'] ?? '')) as $entree) {
            if (str_contains($entree, '=')) {
                [$id, $valeur] = explode('=', trim($entree), 2);

                if (($brute = self::decoderCle($valeur)) !== null) {
                    $cles[(int) $id] = $brute;
                }
            }
        }

        $courante = null;

        if (($brute = self::decoderCle((string) ($config['cle'] ?? ''))) !== null) {
            $courante = (int) ($config['cle_id'] ?? 1);
            $cles[$courante] = $brute;
        }

        return new self($cles, $courante);
    }

    // « base64:… » (format de php artisan gec:cle-fichiers), 32 octets.
    private static function decoderCle(string $valeur): ?string
    {
        $valeur = trim($valeur);
        $brute = base64_decode(str_starts_with($valeur, 'base64:') ? substr($valeur, 7) : $valeur, true);

        return $brute !== false && strlen($brute) === 32 ? $brute : null;
    }

    public function cleConfiguree(): bool
    {
        return $this->cleCourante !== null && isset($this->cles[$this->cleCourante]);
    }

    public static function estChiffre(string $debut): bool
    {
        return strlen($debut) >= 5 && str_starts_with($debut, self::MAGIE) && ord($debut[4]) === self::VERSION;
    }

    /**
     * @param  resource  $entree
     * @param  resource  $sortie
     */
    public function chiffrerFlux($entree, $sortie): void
    {
        if (! $this->cleConfiguree()) {
            throw new CleChiffrementAbsente('Aucune clé de chiffrement des fichiers (GEC_FICHIERS_CLE) : fichier non enregistré.');
        }

        $cle = $this->cles[$this->cleCourante];
        $prefixe = random_bytes(7);
        $entete = self::MAGIE.chr(self::VERSION).chr($this->cleCourante).chr($this->log2Bloc).$prefixe;
        $tailleBloc = 1 << $this->log2Bloc;

        $this->ecrire($sortie, $entete);

        // Un bloc d'avance : on ne sait qu'un bloc est le dernier qu'en
        // constatant qu'il n'y a rien après lui.
        $courant = $this->lire($entree, $tailleBloc);

        for ($index = 0; ; $index++) {
            $suivant = strlen($courant) === $tailleBloc ? $this->lire($entree, $tailleBloc) : '';
            $dernier = $suivant === '';

            $this->ecrire($sortie, $this->scellerBloc($cle, $prefixe, $index, $dernier, $courant, $entete));

            if ($dernier) {
                return;
            }

            $courant = $suivant;
        }
    }

    /**
     * @param  resource  $entree
     * @param  resource  $sortie
     * @param  string|null  $entete  en-tête déjà lu par l'appelant (détection)
     */
    public function dechiffrerFlux($entree, $sortie, ?string $entete = null): void
    {
        $entete ??= $this->lire($entree, self::TAILLE_ENTETE);

        if (strlen($entete) !== self::TAILLE_ENTETE || ! self::estChiffre($entete)) {
            throw new FichierChiffreInvalide('En-tête de fichier chiffré absent ou inconnu.');
        }

        $idCle = ord($entete[5]);
        $log2 = ord($entete[6]);

        if (! isset($this->cles[$idCle])) {
            throw new FichierChiffreInvalide("Clé de chiffrement n°{$idCle} inconnue : fichier illisible avec la configuration actuelle.");
        }

        if ($log2 < 10 || $log2 > 26) {
            throw new FichierChiffreInvalide('Taille de bloc invalide dans l\'en-tête.');
        }

        $cle = $this->cles[$idCle];
        $prefixe = substr($entete, 7, 7);
        $tailleBlocChiffre = (1 << $log2) + self::TAILLE_ETIQUETTE;
        $courant = $this->lire($entree, $tailleBlocChiffre);

        for ($index = 0; ; $index++) {
            if (strlen($courant) < self::TAILLE_ETIQUETTE) {
                throw new FichierChiffreInvalide('Fichier chiffré tronqué.');
            }

            $suivant = strlen($courant) === $tailleBlocChiffre ? $this->lire($entree, $tailleBlocChiffre) : '';
            $dernier = $suivant === '';

            $this->ecrire($sortie, $this->ouvrirBloc($cle, $prefixe, $index, $dernier, $courant, $entete));

            if ($dernier) {
                return;
            }

            $courant = $suivant;
        }
    }

    public function chiffrerChaine(string $clair): string
    {
        return $this->viaFlux($clair, fn ($entree, $sortie) => $this->chiffrerFlux($entree, $sortie));
    }

    public function dechiffrerChaine(string $chiffre): string
    {
        return $this->viaFlux($chiffre, fn ($entree, $sortie) => $this->dechiffrerFlux($entree, $sortie));
    }

    // Taille du contenu en clair, calculée sans déchiffrer (en-tête +
    // taille stockée) : les blocs sont de taille fixe, seul le dernier est
    // plus court, et chacun porte une étiquette de 16 octets.
    public function tailleClaire(string $entete, int $tailleChiffree): int
    {
        $tailleBlocChiffre = (1 << ord($entete[6])) + self::TAILLE_ETIQUETTE;
        $corps = $tailleChiffree - self::TAILLE_ENTETE;
        $blocs = max(1, intdiv($corps + $tailleBlocChiffre - 1, $tailleBlocChiffre));

        return $corps - $blocs * self::TAILLE_ETIQUETTE;
    }

    private function scellerBloc(string $cle, string $prefixe, int $index, bool $dernier, string $clair, string $entete): string
    {
        $etiquette = '';
        $chiffre = openssl_encrypt($clair, self::ALGORITHME, $cle, OPENSSL_RAW_DATA, $this->nonce($prefixe, $index, $dernier), $etiquette, $entete, self::TAILLE_ETIQUETTE);

        if ($chiffre === false) {
            throw new RuntimeException('Échec du chiffrement d\'un bloc.');
        }

        return $chiffre.$etiquette;
    }

    private function ouvrirBloc(string $cle, string $prefixe, int $index, bool $dernier, string $bloc, string $entete): string
    {
        $clair = openssl_decrypt(
            substr($bloc, 0, -self::TAILLE_ETIQUETTE),
            self::ALGORITHME,
            $cle,
            OPENSSL_RAW_DATA,
            $this->nonce($prefixe, $index, $dernier),
            substr($bloc, -self::TAILLE_ETIQUETTE),
            $entete,
        );

        if ($clair === false) {
            throw new FichierChiffreInvalide("Bloc n°{$index} altéré, déplacé ou fichier tronqué.");
        }

        return $clair;
    }

    private function nonce(string $prefixe, int $index, bool $dernier): string
    {
        if ($index > 0xFFFFFFFF) {
            throw new RuntimeException('Fichier trop volumineux pour le format de chiffrement.');
        }

        return $prefixe.pack('N', $index).($dernier ? "\x01" : "\x00");
    }

    /**
     * @param  resource  $flux
     */
    private function lire($flux, int $octets): string
    {
        $donnees = stream_get_contents($flux, $octets);

        if ($donnees === false) {
            throw new RuntimeException('Lecture du flux impossible.');
        }

        return $donnees;
    }

    /**
     * @param  resource  $flux
     */
    private function ecrire($flux, string $donnees): void
    {
        if (fwrite($flux, $donnees) !== strlen($donnees)) {
            throw new RuntimeException('Écriture du flux impossible.');
        }
    }

    private function viaFlux(string $donnees, callable $operation): string
    {
        $entree = fopen('php://memory', 'w+b');
        $sortie = fopen('php://memory', 'w+b');

        try {
            fwrite($entree, $donnees);
            rewind($entree);
            $operation($entree, $sortie);
            rewind($sortie);

            return (string) stream_get_contents($sortie);
        } finally {
            fclose($entree);
            fclose($sortie);
        }
    }
}
