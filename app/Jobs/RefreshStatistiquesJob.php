<?php

namespace App\Jobs;

use App\Models\Courrier;
use App\Models\TransfertService;
use App\Services\WorkflowService;
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

// Module 10 étendu — « Dashboard statistiques » façon Power BI (2026-10-30,
// demandes utilisateur « now the statistic », puis « shouldn't it be like a
// Power BI dashboard showing essential info », toutes les sections et le
// filtrage au clic retenus — voir DECISIONS.md). Règle n°1 : TOUT le calcul
// lourd est ici (jointures historique/affectations/transferts, délais,
// étape, ancienneté), jamais à l'affichage. Reconstruit la table de faits
// statistiques_courriers (une ligne par courrier : reçus sur 24 mois + tous
// ceux encore en cours) ; la page n'y fait que des totaux indexés.
// Planifié toutes les 15 minutes (bootstrap/app.php), demandé une fois si
// aucun calcul n'a encore eu lieu (voir Statistiques::calculeLe()).
class RefreshStatistiquesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Date du dernier calcul (petite valeur, seule chose mise en cache).
    public const CLE_CACHE = 'gec.statistiques.calcule_le';

    public const MOIS_CONSERVES = 24;

    // Toute action qui amène un courrier au statut 'traite' (voir
    // WorkflowService::valider()/cloturerConfidentiel()/signerEtCloturer()/
    // traiterTresorerie()).
    public const ACTIONS_CLOTURE = ['validation_acceptee', 'confidentiel_remis', 'signe_dg_cloture', 'traite_tresorerie'];

    public int $tries = 3;

    public function handle(): void
    {
        $debut = now()->startOfMonth()->subMonths(self::MOIS_CONSERVES - 1);
        $clotures = $this->premieresDates(self::ACTIONS_CLOTURE);
        $rejets = $this->premieresDates(['rejet']);
        $actifs = WorkflowService::statutsActifs();

        // Remplacement complet et atomique (la page ne voit jamais une table
        // à moitié reconstruite), inséré par paquets de 500 : mémoire
        // constante quel que soit le volume.
        DB::transaction(function () use ($debut, $clotures, $rejets, $actifs) {
            DB::table('statistiques_courriers')->delete();

            Courrier::query()
                ->where(fn ($q) => $q->where('date_mouvement', '>=', $debut)->orWhereIn('statut', $actifs))
                ->with(['affectationCourante', 'dernierTransfert'])
                ->chunkById(500, function ($courriers) use ($clotures, $rejets, $actifs) {
                    DB::table('statistiques_courriers')->insert($courriers->map(fn (Courrier $courrier) => $this->ligne(
                        $courrier,
                        $clotures[$courrier->id] ?? null,
                        $rejets[$courrier->id] ?? null,
                        in_array($courrier->statut, $actifs, true),
                    ))->all());
                });
        });

        Cache::forever(self::CLE_CACHE, now()->toIso8601String());
    }

    // Première date de chaque courrier pour ces actions de l'historique
    // (append-only, Règle n°5).
    private function premieresDates(array $actions): array
    {
        return DB::table('courrier_historiques')
            ->whereIn('action', $actions)
            ->groupBy('courrier_id')
            ->selectRaw('courrier_id, min(created_at) as le')
            ->pluck('le', 'courrier_id')
            ->all();
    }

    private function ligne(Courrier $courrier, ?string $clotureLe, ?string $rejeteLe, bool $enCours): array
    {
        $cloture = $clotureLe ? Carbon::parse($clotureLe) : null;
        $reception = $courrier->date_mouvement;

        // Même échéance que Courrier::scopeEnRetard() : le délai fixé par le
        // responsable s'il existe, sinon la date limite SLA.
        $dansLesDelais = match (true) {
            $cloture === null => null,
            $courrier->chrono_fin_le !== null => $cloture->lte($courrier->chrono_fin_le),
            $courrier->date_limite !== null => $cloture->copy()->startOfDay()->lte($courrier->date_limite->startOfDay()),
            default => null,
        };

        $enRetard = $enCours && ($courrier->chrono_fin_le !== null
            ? $courrier->chrono_fin_le->lt(now())
            : ($courrier->date_limite !== null && $courrier->date_limite->lt(today())));

        $sortie = collect([$cloture, $rejeteLe ? Carbon::parse($rejeteLe) : null])->filter()->min();

        return [
            'courrier_id' => $courrier->id,
            'service_id' => (int) $courrier->service_id,
            'confidentialite' => (int) $courrier->confidentialite,
            'confidentiel_direct' => (bool) $courrier->confidentiel_direct,
            'sens' => $courrier->sens,
            'type_document' => mb_substr((string) $courrier->type_document, 0, 255),
            'priorite' => (string) $courrier->priorite,
            'mode_reception' => (string) $courrier->mode_reception,
            'expediteur' => mb_substr(trim((string) ($courrier->expediteur_organisation ?: $courrier->expediteur_nom)), 0, 160),
            'date_reception' => $reception->toDateString(),
            'mois_reception' => $reception->format('Y-m'),
            'date_cloture' => $cloture?->toDateString(),
            'mois_cloture' => $cloture?->format('Y-m'),
            'jours_traitement' => $cloture ? max(0, (int) $reception->copy()->startOfDay()->diffInDays($cloture->copy()->startOfDay())) : null,
            'evaluable' => $dansLesDelais !== null,
            'dans_les_delais' => (bool) $dansLesDelais,
            'date_sortie' => $sortie?->toDateString(),
            'en_cours' => $enCours,
            'etape' => $enCours ? $this->etape($courrier) : '',
            'en_retard' => $enRetard,
            'anciennete_jours' => $enCours ? max(0, (int) $reception->copy()->startOfDay()->diffInDays(today())) : 0,
            'collaborateur_id' => $courrier->affectationCourante?->user_id,
        ];
    }

    // Étape du circuit d'un courrier en cours — 'enregistre' est découpé
    // comme sur la fiche : remise de l'original, signature de la réception,
    // puis « à affecter » (ordre remise → signature → affectation, 2026-10-29).
    private function etape(Courrier $courrier): string
    {
        if ($courrier->statut === 'enregistre') {
            $transfert = $courrier->dernierTransfert;

            return match (true) {
                (bool) $courrier->confidentiel_direct => 'remise_confidentiel',
                $transfert?->statut === TransfertService::ACCEPTE && $transfert->original_remis_le === null => 'remise_original',
                $transfert?->statut === TransfertService::ACCEPTE && $transfert->reception_signee_le === null => 'signature_reception',
                default => 'a_affecter',
            };
        }

        return match ($courrier->statut) {
            'en_attente_de_transfert' => 'a_transferer',
            'en_cours_de_transfert' => 'attente_acceptation',
            'affecte' => 'a_demarrer',
            'en_traitement' => 'en_traitement',
            'en_validation' => 'en_validation',
            'en_attente_information' => 'attente_information',
            'en_attente_signature_dg' => 'signature_dg',
            'en_attente_tresorerie' => 'tresorerie',
            default => $courrier->statut,
        };
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du job RefreshStatistiquesJob', [
            'erreur' => $exception?->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
