<?php

namespace App\Http\Controllers;

use App\Services\ApercuSecurise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApercuManifesteController extends Controller
{
    // Module 9 — aperçu sécurisé (2026-11-09) : liste des pages d'un document
    // pour la visionneuse (resources/js/document-preview.js), ou « en
    // préparation ». Remplace les anciennes routes qui servaient le fichier
    // original (voir DECISIONS.md « Fichiers : chiffrement, aperçu sécurisé,
    // aucun téléchargement »).
    public function __invoke(Request $request, string $type, int $id, ApercuSecurise $apercus): JsonResponse
    {
        return response()
            ->json($apercus->manifeste($request->user(), $type, $id))
            ->header('Cache-Control', 'no-store, private');
    }
}
