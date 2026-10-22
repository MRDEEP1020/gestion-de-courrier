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

class BrouillonDocumentApercuController extends Controller
{
    // Module 1/2 — aperçu du document scanné AVANT la création du Courrier
    // (flux scan-first, panneau "Aperçu du document" du formulaire
    // d'enregistrement), affiché dans le navigateur (Content-Disposition:
    // inline) — même principe que CourrierDocumentApercuController, pour un
    // CourrierBrouillon plutôt qu'un Courrier. Droits : mêmes que
    // l'utilisation du brouillon (Règle n°6, CourrierBrouillonPolicy::utiliser).
    public function __invoke(CourrierBrouillon $brouillon): StreamedResponse
    {
        Gate::authorize('utiliser', $brouillon);

        if (! $brouillon->fichier_path) {
            throw new NotFoundHttpException;
        }

        // Pentest (2026-09-24) — même garde-fou que
        // CourrierDocumentApercuController/PieceJointeDownloadController,
        // corrigé le 2026-10-02 pour distinguer fichier absent (404) de
        // stockage S3/MinIO injoignable (503) — exists() peut LEVER une
        // exception, pas seulement retourner false (voir leur commentaire).
        try {
            if (! Storage::disk('s3')->exists($brouillon->fichier_path)) {
                Log::warning('Document de brouillon introuvable sur le stockage', ['brouillon_id' => $brouillon->id, 'fichier_path' => $brouillon->fichier_path]);

                throw new NotFoundHttpException;
            }

            return Storage::disk('s3')->response($brouillon->fichier_path);
        } catch (FilesystemException $e) {
            Log::error('Stockage S3 injoignable lors de la consultation du document de brouillon', ['brouillon_id' => $brouillon->id, 'fichier_path' => $brouillon->fichier_path, 'exception' => $e->getMessage()]);

            throw new ServiceUnavailableHttpException(null, 'Le stockage des documents est temporairement indisponible.', $e);
        }
    }
}
