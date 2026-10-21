<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    // Bascule FR/EN demandée par l'utilisateur (2026-09-07) — voir
    // SetLocale::LOCALES_DISPONIBLES et DECISIONS.md. Une langue non reconnue
    // est simplement ignorée (retour à la page précédente sans erreur),
    // plutôt qu'une 404 — un lien mal formé ne doit jamais bloquer l'agent.
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        if (in_array($locale, SetLocale::LOCALES_DISPONIBLES, true)) {
            $request->session()->put('locale', $locale);
        }

        return back();
    }
}
