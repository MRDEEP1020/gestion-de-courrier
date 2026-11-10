<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

// Module 9 — garde-fous de déploiement (2026-10-30, conformité de
// l'archivage, voir DECISIONS.md) : deux pannes silencieuses rendues visibles
// (Sécurité & Audit, bandeau administrateur).
//   1. Le planificateur ne tourne pas : alertes SLA, relances, archivage,
//      statistiques, contrôles d'intégrité et PDF/A s'arrêtent sans erreur
//      (constaté du 20 au 30/10).
//   2. La copie de secours est sur le même serveur que les originaux : une
//      panne de ce serveur emporte les deux.
class EtatSysteme
{
    public const CLE_BATTEMENT = 'gec.planificateur.dernier_passage';

    // Au-delà, le planificateur est considéré arrêté (il bat chaque minute).
    public const MINUTES_TOLEREES = 10;

    public static function dernierBattement(): ?Carbon
    {
        $le = Cache::get(self::CLE_BATTEMENT);

        return $le ? Carbon::parse($le) : null;
    }

    public static function planificateurActif(): bool
    {
        $dernier = self::dernierBattement();

        return $dernier !== null && $dernier->gt(now()->subMinutes(self::MINUTES_TOLEREES));
    }

    // 'absente' (aucun disque de secours configuré), 'meme_serveur' (même
    // adresse que le stockage principal) ou 'separee'.
    public static function etatSauvegarde(): string
    {
        $principal = config('filesystems.disks.s3');
        $secours = config('filesystems.disks.s3_backup');

        if (blank($secours['bucket'] ?? null)) {
            return 'absente';
        }

        $hote = fn (?string $adresse) => strtolower((string) parse_url((string) $adresse, PHP_URL_HOST)).':'.(parse_url((string) $adresse, PHP_URL_PORT) ?? '');

        // Sans adresse explicite, les deux disques visent le même service
        // cloud : séparés seulement s'ils sont dans des régions différentes.
        $meme = filled($principal['endpoint'] ?? null) || filled($secours['endpoint'] ?? null)
            ? $hote($principal['endpoint'] ?? null) === $hote($secours['endpoint'] ?? null)
            : ($principal['region'] ?? null) === ($secours['region'] ?? null);

        return $meme ? 'meme_serveur' : 'separee';
    }
}
