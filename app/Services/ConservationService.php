<?php

namespace App\Services;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\DureeConservation;
use App\Models\EmpreinteFichier;
use App\Models\Parametre;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

// Module 9 — durées de conservation des archives (2026-10-30, conformité de
// l'archivage, voir DECISIONS.md « Archivage : conformité ») : échéance =
// date d'archivage + durée du type de document (sinon durée par défaut de
// Paramètres système) + prolongations. JAMAIS de destruction automatique :
// un administrateur (archives.gerer_conservation) prolonge, place sous gel
// juridique ou détruit, toujours avec un motif tracé dans l'historique
// (Règle n°5). La destruction garde la fiche (suppression douce) et son
// historique comme procès-verbal, et supprime les fichiers — c'est l'unique
// suppression d'archive prévue, réservée à un rôle administrateur (CONVENTIONS.md
// Règle n°5).
class ConservationService
{
    public function anneesPour(?string $typeDocument): int
    {
        $parType = $typeDocument ? DureeConservation::where('type_document', $typeDocument)->value('annees') : null;

        return (int) ($parType ?? Parametre::actuel()->conservation_annees_defaut ?? 10);
    }

    public function echeance(Courrier $courrier): ?CarbonInterface
    {
        if ($courrier->archive_le === null) {
            return null;
        }

        return $courrier->archive_le->copy()->startOfDay()
            ->addYears($this->anneesPour($courrier->type_document) + (int) $courrier->prolongation_annees);
    }

    // Recalcule l'échéance (sans relancer les règles de date limite SLA du
    // modèle : seule la colonne de conservation change).
    public function appliquer(Courrier $courrier): void
    {
        $courrier->forceFill(['conserver_jusqua' => $this->echeance($courrier)?->toDateString()])->saveQuietly();
    }

    public function prolonger(Courrier $courrier, User $auteur, int $annees, string $motif): void
    {
        DB::transaction(function () use ($courrier, $auteur, $annees, $motif) {
            $courrier->forceFill(['prolongation_annees' => (int) $courrier->prolongation_annees + $annees])->saveQuietly();
            $this->appliquer($courrier);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'conservation_prolongee',
                'commentaire' => "Conservation prolongée de {$annees} an(s), jusqu'au {$courrier->conserver_jusqua?->format('d/m/Y')} — {$motif}",
            ]);
        });
    }

    public function geler(Courrier $courrier, User $auteur, bool $gel, string $motif): void
    {
        DB::transaction(function () use ($courrier, $auteur, $gel, $motif) {
            $courrier->forceFill(['gel_juridique' => $gel])->saveQuietly();

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => $gel ? 'gel_juridique' : 'gel_juridique_leve',
                'commentaire' => $motif,
            ]);
        });
    }

    public function peutEtreDetruit(Courrier $courrier): bool
    {
        return $courrier->statut === 'archive'
            && ! $courrier->trashed()
            && ! $courrier->gel_juridique
            && $courrier->conserver_jusqua !== null
            && $courrier->conserver_jusqua->lte(today());
    }

    public function detruire(Courrier $courrier, User $auteur, string $motif): void
    {
        $courrier->loadMissing('piecesJointes');

        if (! $this->peutEtreDetruit($courrier)) {
            throw new RuntimeException("Destruction impossible : {$courrier->numero_reference} n'est pas une archive arrivée à échéance, ou est sous gel juridique.");
        }

        $fichiers = array_values(array_filter(array_merge(
            [$courrier->fichier_path, $courrier->fichier_pdfa_path],
            $courrier->piecesJointes->pluck('fichier_path')->all(),
        )));

        DB::transaction(function () use ($courrier, $auteur, $motif, $fichiers) {
            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'destruction',
                'commentaire' => sprintf('Archive détruite à l\'échéance de conservation (%s) — %d fichier(s) supprimé(s) — %s',
                    $courrier->conserver_jusqua->format('d/m/Y'), count($fichiers), $motif),
            ]);

            EmpreinteFichier::query()
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->whereIn('fichier_type', [EmpreinteFichier::TYPE_COURRIER, EmpreinteFichier::TYPE_COURRIER_PDFA])->where('fichier_id', $courrier->id))
                    ->orWhere(fn ($q) => $q->where('fichier_type', EmpreinteFichier::TYPE_PIECE_JOINTE)->whereIn('fichier_id', $courrier->piecesJointes->pluck('id'))))
                ->update(['statut' => EmpreinteFichier::STATUT_DETRUIT, 'verifie_le' => now()]);

            $courrier->delete();
        });

        // Fichiers supprimés après l'enregistrement de la destruction : un
        // échec de stockage est journalisé, la décision reste tracée.
        foreach ($fichiers as $chemin) {
            foreach (['s3', 's3_backup'] as $disque) {
                if ($disque === 's3_backup' && blank(config('filesystems.disks.s3_backup.bucket'))) {
                    continue;
                }

                try {
                    Storage::disk($disque)->delete($chemin);
                } catch (Throwable $e) {
                    Log::error('Fichier d\'archive détruite non supprimé du stockage', ['disque' => $disque, 'chemin' => $chemin, 'erreur' => $e->getMessage()]);
                }
            }
        }
    }
}
