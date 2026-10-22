<?php

namespace App\Http\Controllers;

use App\Models\Courrier;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class CourrierDocumentApercuController extends Controller
{
    // Module 2 — aperçu du document principal scanné, affiché dans le
    // navigateur (Content-Disposition: inline) plutôt que téléchargé —
    // distinct de CourrierDocumentDownloadController (attachment, nom de
    // fichier propre). Mêmes droits que la consultation du courrier
    // (Règle n°6). PDF/JPG/PNG uniquement (seuls types acceptés à l'upload,
    // voir ScanForm/CourrierForm) : tous nativement affichables par le
    // navigateur, pas de conversion nécessaire.
    public function __invoke(Courrier $courrier): StreamedResponse
    {
        Gate::authorize('view', $courrier);

        if (! $courrier->fichier_path) {
            throw new NotFoundHttpException;
        }

        // 2026-10-02 (retour réel de l'utilisateur, capture d'écran : "500
        // en récupérant le document") — Storage::exists() ne se contente
        // PAS de renvoyer false pour un fichier absent, il peut aussi LEVER
        // une League\Flysystem\FilesystemException (UnableToCheckFileExistence)
        // si le disque S3/MinIO lui-même est injoignable (voir
        // storage/logs/laravel.log, "Unable to check existence for..." —
        // bug introduit par le garde-fou du 2026-09-24 ci-dessous, qui
        // supposait à tort que exists() ne pouvait que retourner false).
        // Distingue maintenant les deux cas : fichier absent (404, warning,
        // comportement normal si le courrier n'a jamais eu de document) vs
        // stockage injoignable (503, error, problème d'infrastructure —
        // jamais présenté à l'agent comme "ce document n'existe pas").
        try {
            if (! Storage::disk('s3')->exists($courrier->fichier_path)) {
                Log::warning('Document principal introuvable sur le stockage', ['courrier_id' => $courrier->id, 'fichier_path' => $courrier->fichier_path]);

                throw new NotFoundHttpException;
            }

            return Storage::disk('s3')->response($courrier->fichier_path);
        } catch (FilesystemException $e) {
            Log::error('Stockage S3 injoignable lors de la consultation du document', ['courrier_id' => $courrier->id, 'fichier_path' => $courrier->fichier_path, 'exception' => $e->getMessage()]);

            throw new ServiceUnavailableHttpException(null, 'Le stockage des documents est temporairement indisponible.', $e);
        }
    }
}
