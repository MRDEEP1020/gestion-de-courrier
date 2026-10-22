<?php

namespace App\Jobs;

use App\Events\ClassementPropose;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\MotCle;
use App\Services\ClassificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class IndexCourrierJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Module 3 — Classement et indexation automatiques. Lancé à l'enregistrement
    // (sur objet/expéditeur) puis après un OCR réussi (sur le texte complet).
    // Jamais en synchrone (Règle n°1). L'index plein-texte (Module 8) viendra
    // se brancher ici.
    public function __construct(public Courrier $courrier) {}

    public function handle(ClassificationService $classification): void
    {
        // Calculée hors transaction, sur les champs métier (objet, expéditeur,
        // texte OCR) : ils ne font pas partie de la course décrite ci-dessous
        // (deux *analyses* concurrentes, pas deux modifications concurrentes du
        // courrier lui-même).
        $courrierPourClassement = $this->courrier->fresh(['motsCles']);
        $proposition = $classification->classer($courrierPourClassement);
        $texteOcr = $courrierPourClassement->texteOcrExploitable();
        $courrierId = $courrierPourClassement->id;

        $premiereAnalyse = false;
        $changement = false;

        DB::transaction(function () use ($courrierId, $classification, $proposition, $texteOcr, &$changement, &$premiereAnalyse) {
            // Relu et verrouillé DANS la transaction (pas avant) : deux jobs
            // d'analyse concurrents sur le même courrier (ex. job d'enregistrement
            // encore en file d'attente + job dispatché juste après par l'OCR) ne
            // doivent ni dupliquer l'entrée d'historique, ni laisser la proposition
            // la plus pauvre (objet seul) écraser la plus riche (texte OCR complet)
            // selon le seul hasard de l'ordre d'exécution — constat de revue,
            // corrigé le 2026-09-04.
            $courrier = Courrier::whereKey($courrierId)->lockForUpdate()->firstOrFail();

            $premiereAnalyse = $courrier->classement_analyse_le === null;

            // Tags : règles + mots-clés extraits du texte OCR, en une seule passe
            // (un même libellé peut venir des deux — la règle l'emporte). Jamais
            // d'écrasement d'un tag posé à la main (source "manuel").
            $this->attacher($courrier, [
                'regle' => $proposition['tags'],
                'ocr' => $classification->extraireMotsCles($texteOcr),
            ]);

            // Toujours horodater l'analyse : la fiche distingue ainsi « pas encore
            // analysé (job en attente) » de « analysé, aucune règle ne correspond ».
            $courrier->update(['classement_analyse_le' => now()]);

            if ($proposition['type_document'] === null && $proposition['service_id'] === null) {
                // Une proposition encore en attente de décision qui ne tient plus
                // (objet/expéditeur modifiés) est retirée ; une décision déjà
                // prise par l'agent (validé/ignoré) n'est jamais touchée.
                if ($courrier->classement_statut === 'propose') {
                    $courrier->update([
                        'classement_statut' => 'non_classe',
                        'type_document_propose' => null,
                        'service_propose_id' => null,
                        'classement_regle_id' => null,
                        'classement_propose_le' => null,
                    ]);

                    CourrierHistorique::create([
                        'courrier_id' => $courrier->id,
                        'auteur_id' => null,
                        'action' => 'classement_retire',
                        'commentaire' => 'Plus aucune règle ne correspond après modification du courrier',
                    ]);

                    $changement = true;
                }

                return;
            }

            $identique = $courrier->type_document_propose === $proposition['type_document']
                && (int) $courrier->service_propose_id === (int) $proposition['service_id'];

            // Une décision déjà prise par l'agent (validé/ignoré) n'est remise en
            // question que si la proposition change (ex. texte OCR arrivé après coup).
            if ($identique && $courrier->classement_statut !== 'non_classe') {
                return;
            }

            $courrier->update([
                'classement_statut' => 'propose',
                'type_document_propose' => $proposition['type_document'],
                'service_propose_id' => $proposition['service_id'],
                'classement_regle_id' => $proposition['regle_id'],
                'classement_propose_le' => now(),
            ]);

            // Règle n°5 — traçabilité immuable. Le service peut venir d'une règle
            // de mots-clés différente du type (ex. règle "Sinistre" pour le type,
            // historique de l'expéditeur pour le service) — le message distingue
            // les deux origines plutôt que d'attribuer les deux à une seule règle.
            $segmentService = match ($proposition['service_source']) {
                'historique' => ", service proposé d'après l'historique de cet expéditeur",
                'regle' => ', service proposé',
                default => '',
            };

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => null,
                'action' => 'classement_propose',
                'commentaire' => sprintf(
                    'Règle « %s » : %s%s',
                    $proposition['regle_nom'],
                    $proposition['type_document'] ? "type « {$proposition['type_document']} »" : 'type inchangé',
                    $segmentService,
                ),
            ]);

            $changement = true;
        });

        if ($changement || $premiereAnalyse) {
            try {
                ClassementPropose::dispatch($courrierId);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @param array<string, string[]> $libellesParSource  source => libellés, par ordre de priorité */
    private function attacher(Courrier $courrier, array $libellesParSource): void
    {
        // Libellés déjà présents (toutes sources), relus en base : la relation
        // chargée avant l'analyse ne doit pas servir de référence.
        $existants = $courrier->motsCles()->pluck('libelle')->all();

        foreach ($libellesParSource as $source => $libelles) {
            foreach ($libelles as $libelle) {
                $libelle = ClassificationService::libelle($libelle);

                if ($libelle === '' || in_array($libelle, $existants, true)) {
                    continue;
                }

                try {
                    $motCle = MotCle::firstOrCreate(['libelle' => $libelle]);
                    $courrier->motsCles()->attach($motCle->id, ['source' => $source]);
                } catch (UniqueConstraintViolationException) {
                    // Deux analyses du même courrier en parallèle (OCR terminé
                    // pendant l'indexation initiale) : le tag existe déjà, on passe.
                }

                $existants[] = $libelle;
            }
        }
    }

    // Règle n°1 — échec loggé de façon exploitable.
    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du classement automatique', [
            'courrier_id' => $this->courrier->id,
            'numero_reference' => $this->courrier->numero_reference,
            'erreur' => $exception?->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
