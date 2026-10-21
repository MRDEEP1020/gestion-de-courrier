<?php

namespace App\Policies;

use App\Models\CourrierBrouillon;
use App\Models\User;

class CourrierBrouillonPolicy
{
    // Module 1/2 — "panier personnel" : un brouillon n'est utilisable que par
    // l'agent qui l'a scanné, ou qui a le privilège `brouillons.utiliser_tout`
    // (Règle n°6 — vérifié côté serveur à l'ouverture du formulaire pré-rempli
    // ET à la finalisation, jamais fait confiance à l'ID passé en query
    // string). 2026-09-15 — Système de privilèges (voir DECISIONS.md) : la
    // branche "propriétaire" reste un `||` en dur, ce n'est pas un privilège
    // assignable (tout le monde peut utiliser SES PROPRES brouillons).
    //
    // 2026-10-08 — troisième branche pour un brouillon venant du DOSSIER
    // SURVEILLÉ (retour utilisateur réel : l'import est attribué à
    // Auth::id() de QUELLE QUE SOIT la session active au moment précis de
    // l'import — pas forcément celle de l'agent qui ouvre ensuite
    // "Enregistrer un courrier" sur le même poste partagé). Volontairement
    // PAS brouillons.utiliser_tout (explicitement refusé par l'utilisateur,
    // "not the whole thing the admin can see" — ce privilège couvrirait
    // AUSSI les brouillons scannés manuellement par d'autres agents, hors
    // sujet ici) : un import "dossier_surveille" est par nature une
    // ressource du POSTE, pas personnelle à qui était connecté à cet
    // instant précis — courriers.creer (déjà le pré-requis de base pour
    // atteindre ce formulaire) suffit, pas un privilège à part.
    public function utiliser(User $user, CourrierBrouillon $brouillon): bool
    {
        return $user->hasPrivilege('brouillons.utiliser_tout')
            || $user->id === $brouillon->cree_par_id
            || ($brouillon->source === CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE && $user->hasPrivilege('courriers.creer'));
    }

    // Télécharger le fichier d'un brouillon (2026-09-23) : l'utiliser ET
    // avoir courriers.telecharger — même privilège que pour un courrier.
    public function telecharger(User $user, CourrierBrouillon $brouillon): bool
    {
        return $user->hasPrivilege('courriers.telecharger') && $this->utiliser($user, $brouillon);
    }
}
