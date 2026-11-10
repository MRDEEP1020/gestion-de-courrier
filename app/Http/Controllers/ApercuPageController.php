<?php

namespace App\Http\Controllers;

use App\Services\ApercuSecurise;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ApercuPageController extends Controller
{
    // Module 9 — une page de l'aperçu sécurisé, filigranée au nom de la
    // personne connectée (2026-11-09). Jamais mise en cache (navigateur ni
    // intermédiaire), affichée en ligne, sans nom de fichier.
    public function __invoke(Request $request, string $type, int $id, int $page, ApercuSecurise $apercus): Response
    {
        return response($apercus->page($request->user(), $type, $id, $page), 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
