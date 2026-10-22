<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    // Bascule FR/EN demandée par l'utilisateur (2026-09-07) : le choix est
    // gardé en session (pas de colonne "locale" sur users — un seul poste
    // partagé par plusieurs profils au pilote, la session suffit et évite
    // une migration). Français par défaut (APP_LOCALE, voir DECISIONS.md) si
    // rien n'est encore choisi.
    public const LOCALES_DISPONIBLES = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale', config('app.locale'));

        if (! in_array($locale, self::LOCALES_DISPONIBLES, true)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
