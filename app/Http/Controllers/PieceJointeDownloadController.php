<?php

namespace App\Http\Controllers;

use App\Models\PieceJointe;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class PieceJointeDownloadController extends Controller
{
    // Module 1 — téléchargement d'une pièce jointe : consultation du
    // courrier + courriers.telecharger (2026-09-23, CourrierPolicy::telecharger).
    public function __invoke(PieceJointe $pieceJointe): StreamedResponse
    {
        Gate::authorize('telecharger', $pieceJointe->courrier);
        Gate::authorize('voirPiecesJointes', $pieceJointe->courrier);

        // Même garde-fou que CourrierDocumentApercuController/
        // CourrierDocumentDownloadController (2026-09-24), corrigé le
        // 2026-10-02 pour distinguer fichier absent (404) de stockage
        // S3/MinIO injoignable (503) — exists() peut LEVER une exception,
        // pas seulement retourner false (voir son commentaire).
        try {
            if (! Storage::disk('s3')->exists($pieceJointe->fichier_path)) {
                Log::warning('Pièce jointe introuvable sur le stockage', ['piece_jointe_id' => $pieceJointe->id, 'fichier_path' => $pieceJointe->fichier_path]);

                throw new NotFoundHttpException;
            }

            return Storage::disk('s3')->download($pieceJointe->fichier_path, $pieceJointe->nom_original);
        } catch (FilesystemException $e) {
            Log::error('Stockage S3 injoignable lors du téléchargement de la pièce jointe', ['piece_jointe_id' => $pieceJointe->id, 'fichier_path' => $pieceJointe->fichier_path, 'exception' => $e->getMessage()]);

            throw new ServiceUnavailableHttpException(null, 'Le stockage des documents est temporairement indisponible.', $e);
        }
    }
}
