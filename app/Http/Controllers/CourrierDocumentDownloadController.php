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

class CourrierDocumentDownloadController extends Controller
{
    // Module 2 — téléchargement du document principal scanné (fichier_path,
    // distinct des pièces jointes). Consultation du courrier +
    // courriers.telecharger (2026-09-23, CourrierPolicy::telecharger).
    public function __invoke(Courrier $courrier): StreamedResponse
    {
        Gate::authorize('telecharger', $courrier);

        if (! $courrier->fichier_path) {
            throw new NotFoundHttpException;
        }

        // Même garde-fou que CourrierDocumentApercuController (2026-09-24),
        // corrigé le 2026-10-02 pour distinguer fichier absent (404) de
        // stockage S3/MinIO injoignable (503) — exists() peut LEVER une
        // exception, pas seulement retourner false (voir son commentaire).
        try {
            if (! Storage::disk('s3')->exists($courrier->fichier_path)) {
                Log::warning('Document principal introuvable sur le stockage', ['courrier_id' => $courrier->id, 'fichier_path' => $courrier->fichier_path]);

                throw new NotFoundHttpException;
            }

            return Storage::disk('s3')->download(
                $courrier->fichier_path,
                $courrier->numero_reference.'.'.pathinfo($courrier->fichier_path, PATHINFO_EXTENSION),
            );
        } catch (FilesystemException $e) {
            Log::error('Stockage S3 injoignable lors du téléchargement du document', ['courrier_id' => $courrier->id, 'fichier_path' => $courrier->fichier_path, 'exception' => $e->getMessage()]);

            throw new ServiceUnavailableHttpException(null, 'Le stockage des documents est temporairement indisponible.', $e);
        }
    }
}
