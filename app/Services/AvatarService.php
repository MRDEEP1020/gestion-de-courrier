<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Page "Utilisateurs & Accès" (2026-09-21, maquette fournie par
// l'utilisateur) — photo de profil optionnelle. Même schéma que
// PieceJointeService (Règle n°4 : disque S3-compatible uniquement, nom
// prévisible + identifiant unique), sans la traçabilité CourrierHistorique
// ni la copie de secours asynchrone (ReplicateFichierJob) : cette dernière
// existe pour la conformité réglementaire des courriers, pas pour une simple
// photo de profil remplaçable.
class AvatarService
{
    public function televerser(User $user, UploadedFile $fichier): string
    {
        $ancienChemin = $user->photo_path;

        $extension = $fichier->getClientOriginalExtension();
        $chemin = sprintf('avatars/%d/%s.%s', $user->id, Str::uuid(), $extension);

        Storage::disk('s3')->putFileAs(dirname($chemin), $fichier, basename($chemin));

        $user->update(['photo_path' => $chemin]);

        if ($ancienChemin && Storage::disk('s3')->exists($ancienChemin)) {
            Storage::disk('s3')->delete($ancienChemin);
        }

        return $chemin;
    }
}
