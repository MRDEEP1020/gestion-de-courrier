<?php

namespace App\Services;

use App\Jobs\ReplicateFichierJob;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\PieceJointe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
}
