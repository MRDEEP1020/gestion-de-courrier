<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

// Page "Utilisateurs & Accès" (2026-09-21) — colonne "Dernière connexion" de
// la maquette : aucune donnée ne l'alimentait avant ce jour (voir
// le journal de développement). Écoute l'événement de connexion natif de Laravel/
// Fortify plutôt que d'ajouter ce champ dans chaque flux de login (mot de
// passe, passkey...) — un seul point d'écoute couvre tous les moyens de
// connexion.
class EnregistrerDerniereConnexion
{
    public function handle(Login $event): void
    {
        // forceFill() : `derniere_connexion_le` est délibérément ABSENT du
        // $fillable de User (Règle n°6 — jamais un champ que le formulaire
        // d'édition d'un admin pourrait remplir/manipuler), donc un simple
        // update() serait silencieusement ignoré ici.
        $event->user->forceFill(['derniere_connexion_le' => now()])->save();
    }
}
