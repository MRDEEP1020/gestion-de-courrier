<?php

namespace App\Jobs;

use App\Models\Parametre;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// Module 10 — recalcule et met en cache le délai moyen de traitement (seul
// indicateur du tableau de bord qui agrège l'historique : les compteurs du
// jour/en retard restent de simples COUNT indexés, calculés à l'affichage).
// Planifié toutes les 15 minutes (bootstrap/app.php) — Règle n°1 : jamais
// recalculé dans le cycle requête/réponse. Voir DECISIONS.md "SLA et alertes".
class RefreshDashboardStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CLE_CACHE = 'gec.dashboard.delai_moyen';

    public int $tries = 3;

    // Délai = date de clôture ('validation_acceptee' OU 'confidentiel_remis'
    // dans l'historique append-only, Règle n°5 — deux actions distinctes qui
    // amènent toutes deux un courrier au statut 'traite', voir
    // WorkflowService::valider()/cloturerConfidentiel()) moins date de
    // réception/envoi (date_mouvement), sur les courriers clôturés ces N
    // derniers jours (N configurable depuis l'UI, "Group A" 2026-09-24 —
    // ex-`const PERIODE_JOURS`, voir DECISIONS.md "Paramètres système
    // configurables — Groupe A/B"). Stocké en somme + nombre PAR SERVICE
    // pour que le tableau de bord puisse faire une moyenne pondérée sur le
    // seul périmètre de l'utilisateur — un pli confidentiel (service_id
    // toujours null) n'est donc compté que pour un compte ayant
    // "Voir tous les courriers" (Administrateur), jamais pour un
    // Responsable de service scopé à ses services : comportement attendu,
    // pas une omission.
    // 'confidentiel_remis' ajouté le 2026-09-24 (audit du tableau de bord,
    // demande explicite de l'utilisateur "find bugs") : un pli confidentiel
    // clôturé par son destinataire était jusque-là invisible de ce calcul,
    // alors qu'il est bien "traité" — voir DECISIONS.md "Délai moyen de
    // traitement : inclut les plis confidentiels".
    public function handle(): void
    {
        $parService = [];

        DB::table('courrier_historiques')
            ->join('courriers', 'courriers.id', '=', 'courrier_historiques.courrier_id')
            ->whereIn('courrier_historiques.action', ['validation_acceptee', 'confidentiel_remis'])
            ->where('courrier_historiques.created_at', '>=', now()->subDays(Parametre::actuel()->dashboard_delai_moyen_periode_jours))
            ->whereNull('courriers.deleted_at')
            ->select('courrier_historiques.id', 'courriers.service_id', 'courriers.date_mouvement', 'courrier_historiques.created_at')
            ->chunkById(500, function ($lignes) use (&$parService) {
                foreach ($lignes as $ligne) {
                    $jours = max(0, (int) Carbon::parse($ligne->date_mouvement)->startOfDay()
                        ->diffInDays(Carbon::parse($ligne->created_at)->startOfDay()));
                    $cle = (int) $ligne->service_id;
                    $parService[$cle]['jours'] = ($parService[$cle]['jours'] ?? 0) + $jours;
                    $parService[$cle]['nombre'] = ($parService[$cle]['nombre'] ?? 0) + 1;
                }
            }, 'courrier_historiques.id', 'id');

        Cache::forever(self::CLE_CACHE, [
            'par_service' => $parService,
            'calcule_le' => now()->toIso8601String(),
        ]);
    }

    // Moyenne pondérée (en jours) sur les services donnés, ou sur tous si null.
    public static function delaiMoyen(array $stats, ?array $serviceIds = null): ?float
    {
        $lignes = $serviceIds === null
            ? $stats['par_service']
            : array_intersect_key($stats['par_service'], array_flip($serviceIds));

        $nombre = array_sum(array_column($lignes, 'nombre'));

        return $nombre > 0 ? round(array_sum(array_column($lignes, 'jours')) / $nombre, 1) : null;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du job RefreshDashboardStatsJob', [
            'erreur' => $exception?->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
