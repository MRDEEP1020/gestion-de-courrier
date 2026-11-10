<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UtilisateurPhotoController extends Controller
{
    // 2026-10-26 (retour utilisateur : « l'image profil ne fonctionne pas »)
    // — la photo était affichée par un lien DIRECT vers le stockage
    // (http://127.0.0.1:9000/gec/avatars/…) : le bucket est privé (403), et un
    // lien http sur la page https://gecs.test serait de toute façon bloqué.
    // L'application la sert elle-même, comme l'aperçu des documents
    // (CourrierDocumentApercuController) : même origine, utilisateur connecté.
    // Toute personne connectée peut voir la photo d'un collègue (elle apparaît
    // dans les listes, l'affectation, l'organisation).
    public function __invoke(User $user): StreamedResponse
    {
        if (! $user->photo_path) {
            throw new NotFoundHttpException;
        }

        try {
            if (! Storage::disk('s3')->exists($user->photo_path)) {
                Log::warning('Photo de profil introuvable sur le stockage', ['user_id' => $user->id, 'photo_path' => $user->photo_path]);

                throw new NotFoundHttpException;
            }

            // Le chemin change à chaque nouvelle photo (identifiant unique,
            // voir AvatarService) : l'URL peut être mise en cache sans risque.
            return Storage::disk('s3')->response($user->photo_path, null, [
                'Cache-Control' => 'private, max-age=86400',
            ]);
        } catch (FilesystemException $e) {
            Log::error('Stockage S3 injoignable lors de l\'affichage d\'une photo de profil', ['user_id' => $user->id, 'exception' => $e->getMessage()]);

            throw new NotFoundHttpException;
        }
    }
}
