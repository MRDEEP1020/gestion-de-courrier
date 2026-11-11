<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

// 2026-11-11 (« je veux que les mots de passe d'utilisateur expirent et ils
// devront le renouveler ») — tant que le mot de passe est à renouveler
// (jamais choisi par la personne, ou expiré : User::doitRenouvelerMotDePasse()),
// toute page de l'application mène à « Renouveler votre mot de passe ».
// Laissés passer : cette page, la déconnexion, les requêtes internes de
// Livewire (actions d'une page déjà ouverte — la page suivante, elle, est
// redirigée) et l'autorisation des canaux temps réel.
class ExigerMotDePasseAJour
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Requêtes Livewire reconnues par leur en-tête (Livewire::isLivewireRequest()),
        // pas par le nom de route : celle des actions s'appelle
        // « default-livewire.update » — avec « livewire.* » seul, le bouton
        // « Enregistrer » de la page de renouvellement était lui-même redirigé
        // vers cette page, en boucle (constaté le 2026-11-11 par l'utilisateur).
        if (
            $user === null
            || ! $user->doitRenouvelerMotDePasse()
            || Livewire::isLivewireRequest()
            || $request->routeIs('mot-de-passe.renouveler*', 'logout', '*livewire.*')
            || $request->is('broadcasting/*')
        ) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('Votre mot de passe doit être renouvelé.'));
        }

        // guest() garde la page demandée : elle s'ouvre une fois le
        // mot de passe renouvelé.
        return redirect()->guest(route('mot-de-passe.renouveler'));
    }
}
