<?php

namespace App\Services;

use App\Models\Courrier;
use App\Models\Parametre;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class SlaCalculatorService
{
    // Module 5 — Suivi et traçabilité avec gestion des délais SLA.
    // Phase 1 : délai fixe par type de courrier, ajustable par courrier
    // (sla_jours) ou remplacé par une échéance explicite (echeance) — voir
    // App\Models\Parametre (page "Paramètres système") et DECISIONS.md "SLA
    // et alertes". Règles complexes (jours ouvrés, pause pendant "en attente
    // d'information") hors scope.
    //
    // 2026-09-23 — les délais viennent désormais de Parametre::actuel()
    // (éditable depuis l'UI, mis en cache) au lieu de config/gec.php (fichier
    // figé, modification = redéploiement).

    public const A_TEMPS = 'a_temps';

    public const A_RISQUE = 'a_risque';

    public const EN_RETARD = 'en_retard';

    // Délai applicable en jours calendaires : sla_jours du courrier, sinon
    // délai de son type, sinon délai par défaut.
    public static function delaiJours(?int $slaJours, ?string $typeDocument): int
    {
        if ($slaJours !== null) {
            return $slaJours;
        }

        return Parametre::actuel()->delaiSlaPour($typeDocument);
    }

    // Statique et sur valeurs brutes : utilisée aussi par la migration de
    // rattrapage (DB::table, sans modèle Eloquent).
    public static function calculerDateLimite(
        CarbonInterface|string|null $echeance,
        ?int $slaJours,
        ?string $typeDocument,
        CarbonInterface|string|null $dateMouvement,
    ): ?Carbon {
        if ($echeance !== null && $echeance !== '') {
            return Carbon::parse($echeance)->startOfDay();
        }

        if ($dateMouvement === null || $dateMouvement === '') {
            return null;
        }

        return Carbon::parse($dateMouvement)->startOfDay()->addDays(self::delaiJours($slaJours, $typeDocument));
    }

    // Un courrier clôturé (traité, archivé, rejeté) n'est jamais "en retard".
    public function calculerStatutDelai(Courrier $courrier): string
    {
        if ($courrier->date_limite === null || ! in_array($courrier->statut, WorkflowService::statutsActifs(), true)) {
            return self::A_TEMPS;
        }

        $aujourdhui = today();

        if ($courrier->date_limite->lt($aujourdhui)) {
            return self::EN_RETARD;
        }

        if ($courrier->date_limite->lte($aujourdhui->copy()->addDays(Parametre::actuel()->sla_seuil_risque_jours))) {
            return self::A_RISQUE;
        }

        return self::A_TEMPS;
    }
}
