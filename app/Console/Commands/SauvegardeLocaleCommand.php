<?php

namespace App\Console\Commands;

use App\Jobs\SauvegarderDocumentsLocalementJob;
use App\Services\SauvegardeLocale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

// 2026-11-11 — met à jour tout de suite la copie de secours sur le PC (sans
// attendre le passage de chaque heure), par exemple juste après l'avoir
// configurée.
class SauvegardeLocaleCommand extends Command
{
    protected $signature = 'gec:sauvegarde-locale';

    protected $description = 'Met à jour la copie de secours des courriers sur le PC (ZIP chiffrés rangés par dossier)';

    public function handle(SauvegardeLocale $sauvegarde): int
    {
        if (! $sauvegarde->active()) {
            $this->error('Copie de secours désactivée : renseigner GEC_SAUVEGARDE_DOSSIER et GEC_SAUVEGARDE_MOT_DE_PASSE dans .env.');

            return self::FAILURE;
        }

        app()->call([new SauvegarderDocumentsLocalementJob, 'handle']);
        $etat = Cache::get(SauvegardeLocale::CLE_ETAT);

        $this->info("Copie à jour dans {$sauvegarde->racine()}");
        $this->table(
            ['Écrits', 'Déplacés', 'Supprimés', 'Inchangés', 'Copies personnelles', 'Erreurs'],
            [[$etat['ecrits'], $etat['deplaces'], $etat['supprimes'], $etat['inchanges'], $etat['personnes'], $etat['erreurs']]],
        );

        return $etat['erreurs'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
