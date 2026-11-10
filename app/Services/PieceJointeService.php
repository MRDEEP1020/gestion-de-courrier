<?php

namespace App\Services;

use App\Jobs\ReplicateFichierJob;
use App\Models\Courrier;
use App\Models\CourrierBrouillon;
use App\Models\CourrierHistorique;
use App\Models\PieceJointe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PieceJointeService
{
    // Module 1 — attache un fichier déjà numérique à un courrier (ex. pièce
    // jointe d'un email). Distinct du document principal scanné (Module 2,
    // `courriers.fichier_path`) : un courrier peut avoir 0 à N pièces jointes
    // (voir ARCHITECTURE.md — table `pieces_jointes`).
    // Utilisé aussi bien par RegistrationForm que EditForm pour ne pas
    // dupliquer la logique de nommage/stockage/traçabilité.
    public function attacher(Courrier $courrier, UploadedFile $fichier, int $auteurId): PieceJointe
    {
        $courrier->loadMissing('service');

        $extension = $fichier->getClientOriginalExtension();

        // Règle n°4 — disque S3-compatible uniquement, nommage prévisible par
        // année/service/numéro de référence (segmentClassement() replie sur
        // "_en_attente" tant qu'un courrier entrant n'a pas encore de service,
        // voir Courrier::segmentClassement() et DECISIONS.md). Un identifiant
        // unique différencie plusieurs pièces jointes d'un même courrier ; le
        // nom d'origine est conservé dans `nom_original` pour l'affichage.
        $chemin = sprintf(
            'courriers/%d/%s/%s/%s.%s',
            now()->year,
            $courrier->segmentClassement(),
            $courrier->numero_reference,
            Str::uuid(),
            $extension,
        );

        Storage::disk('s3')->putFileAs(
            dirname($chemin),
            $fichier,
            basename($chemin),
        );

        // Règle n°4 (complétée) — copie de secours asynchrone, jamais dans ce
        // cycle requête/réponse (Règle n°1) ; voir DECISIONS.md "Stockage hybride".
        ReplicateFichierJob::dispatch($chemin)->onQueue('replication');

        $pieceJointe = $courrier->piecesJointes()->create([
            'fichier_path' => $chemin,
            'nom_original' => $fichier->getClientOriginalName(),
            'type_mime' => $fichier->getMimeType(),
            'taille' => $fichier->getSize(),
        ]);

        // Règle n°5 — traçabilité immuable de l'ajout.
        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => $auteurId,
            'action' => 'ajout_piece_jointe',
            'commentaire' => $fichier->getClientOriginalName(),
        ]);

        return $pieceJointe;
    }

    // Module 1/2 (2026-10-23, demande explicite de l'utilisateur : plusieurs
    // documents scannés pour un même courrier, ex. la lettre ET la facture) —
    // un document scanné en attente (brouillon) devient une pièce jointe du
    // courrier : même chemin que attacher() (Règle n°4), même trace
    // d'historique, même copie de secours. Ligne verrouillée et marquée
    // finalisée AVANT la copie (même principe que
    // RegistrationForm::finaliserBrouillon()) : jamais rattaché deux fois.
    // En cas d'échec, le brouillon redevient "en attente" (il réapparaît dans
    // la liste des documents scannés) et l'exception remonte à l'appelant.
    // Retourne null si le brouillon a déjà été utilisé entre-temps.
    public function attacherDepuisBrouillon(Courrier $courrier, CourrierBrouillon $brouillon, int $auteurId): ?PieceJointe
    {
        $verrouille = DB::transaction(function () use ($brouillon) {
            $ligne = CourrierBrouillon::whereKey($brouillon->id)->lockForUpdate()->first();

            if (! $ligne || $ligne->finalise_le !== null) {
                return null;
            }

            $ligne->update(['finalise_le' => now()]);

            return $ligne;
        });

        if ($verrouille === null) {
            return null;
        }

        try {
            $courrier->loadMissing('service');

            $chemin = sprintf(
                'courriers/%d/%s/%s/%s.%s',
                now()->year,
                $courrier->segmentClassement(),
                $courrier->numero_reference,
                Str::uuid(),
                pathinfo($verrouille->fichier_path, PATHINFO_EXTENSION) ?: 'pdf',
            );

            Storage::disk('s3')->copy($verrouille->fichier_path, $chemin);

            if (! Storage::disk('s3')->exists($chemin)) {
                throw new RuntimeException('Copie du document scanné introuvable au chemin final après coup.');
            }
        } catch (Throwable $e) {
            $verrouille->update(['finalise_le' => null]);

            throw $e;
        }

        $pieceJointe = $courrier->piecesJointes()->create([
            'fichier_path' => $chemin,
            'nom_original' => $verrouille->nom_original,
            'type_mime' => $verrouille->type_mime,
            'taille' => $verrouille->taille,
            'brouillon_origine_id' => $verrouille->id,
        ]);

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => $auteurId,
            'action' => 'ajout_piece_jointe',
            'commentaire' => $verrouille->nom_original,
        ]);

        ReplicateFichierJob::dispatch($chemin)->onQueue('replication');

        // Fichier déjà copié à son emplacement définitif : le brouillon et son
        // fichier provisoire disparaissent (même nettoyage que finaliserBrouillon()).
        Storage::disk('s3')->delete($verrouille->fichier_path);

        if (filled(config('filesystems.disks.s3_backup.bucket')) && Storage::disk('s3_backup')->exists($verrouille->fichier_path)) {
            Storage::disk('s3_backup')->delete($verrouille->fichier_path);
        }

        $verrouille->delete();

        return $pieceJointe;
    }
}
