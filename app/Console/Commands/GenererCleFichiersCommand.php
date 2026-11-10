<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenererCleFichiersCommand extends Command
{
    protected $signature = 'gec:cle-fichiers {--force : Remplacer une clé déjà présente (les fichiers chiffrés avec l\'ancienne deviennent illisibles)}';

    // Module 9 — clé de chiffrement des fichiers (2026-11-09, voir
    // DECISIONS.md « Fichiers : chiffrement, aperçu sécurisé, aucun
    // téléchargement »). Écrite dans .env comme key:generate, jamais
    // affichée (une clé dans un terminal finit dans un historique ou une
    // capture d'écran) : l'administrateur la recopie lui-même depuis .env
    // vers un coffre, hors du serveur.
    protected $description = 'Module 9 — crée la clé de chiffrement des fichiers (GEC_FICHIERS_CLE) dans .env, sans l\'afficher';

    public function handle(): int
    {
        $fichier = $this->laravel->environmentFilePath();

        if (! is_file($fichier)) {
            $this->error('Fichier .env introuvable.');

            return self::FAILURE;
        }

        $contenu = (string) file_get_contents($fichier);
        $existante = preg_match('/^GEC_FICHIERS_CLE=(.+)$/m', $contenu, $m) === 1 && trim($m[1]) !== '';

        if ($existante && ! $this->option('force')) {
            $this->warn('Une clé est déjà configurée : rien n\'a été changé. Elle déchiffre les fichiers existants, ne la remplacez pas.');

            return self::SUCCESS;
        }

        $ligne = 'GEC_FICHIERS_CLE=base64:'.base64_encode(random_bytes(32));
        $contenu = preg_match('/^GEC_FICHIERS_CLE=.*$/m', $contenu) === 1
            ? preg_replace('/^GEC_FICHIERS_CLE=.*$/m', $ligne, $contenu)
            : rtrim($contenu)."\n\n".$ligne."\n";

        file_put_contents($fichier, $contenu);

        $this->info('Clé de chiffrement des fichiers créée dans .env (GEC_FICHIERS_CLE).');
        $this->warn('Recopiez-la maintenant en lieu sûr, hors du serveur : la perdre rend tous les fichiers illisibles.');

        return self::SUCCESS;
    }
}
