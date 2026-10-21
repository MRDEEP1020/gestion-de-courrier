<?php

namespace App\Http\Controllers;

use App\Models\CourrierBrouillon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class BrouillonDocumentDownloadController extends Controller
{
    // Module 1/2 — téléchargement du document scanné AVANT la création du
    // Courrier (bouton "Télécharger" de la modale "Aperçu du courrier") —
    // même principe que CourrierDocumentDownloadController (attachment, nom
    // de fichier propre), pour un CourrierBrouillon. Droits : mêmes que
    // l'utilisation du brouillon + courriers.telecharger (2026-09-23,
    // CourrierBrouillonPolicy::telecharger).
    public function __invoke(CourrierBrouillon $brouillon): StreamedResponse
    {
        Gate::authorize('telecharger', $brouillon);

        if (! $brouillon->fichier_path) {
            throw new NotFoundHttpException;
        }

        // Pentest (2026-09-24) — même garde-fou que
        // CourrierDocumentDownloadController/PieceJointeDownloadController,
        // corrigé le 2026-10-02 pour distinguer fichier absent (404) de
        // stockage S3/MinIO injoignable (503) — exists() peut LEVER une
        // exception, pas seulement retourner false (voir leur commentaire).
        try {
            if (! Storage::disk('s3')->exists($brouillon->fichier_path)) {
                Log::warning('Document de brouillon introuvable sur le stockage', ['brouillon_id' => $brouillon->id, 'fichier_path' => $brouillon->fichier_path]);

                throw new NotFoundHttpException;
            }

            return Storage::disk('s3')->download(
                $brouillon->fichier_path,
                $brouillon->nom_original,
            );
        } catch (FilesystemException $e) {
            Log::error('Stockage S3 injoignable lors du téléchargement du document de brouillon', ['brouillon_id' => $brouillon->id, 'fichier_path' => $brouillon->fichier_path, 'exception' => $e->getMessage()]);

            throw new ServiceUnavailableHttpException(null, 'Le stockage des documents est temporairement indisponible.', $e);
        }
    }
}
