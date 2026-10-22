<?php

namespace App\Policies;

use App\Models\RegleClassement;
use App\Models\User;

class RegleClassementPolicy
{
    // Module 3 — "les règles de classement automatique doivent être paramétrables
    // par un administrateur". 2026-09-15 — Système de privilèges (voir
    // DECISIONS.md) : `regles_classement.gerer`, assigné à Administrateur par
    // défaut, mais désormais assignable à d'autres profils/utilisateurs sans
    // changer de code.
    // 2026-09-23 ("chaque action / lecture = un privilège") : consulter
    // (regles_classement.voir), gérer (créer/modifier/activer/supprimer,
    // regles_classement.gerer — qui implique la consultation) et réanalyser
    // (regles_classement.reanalyser) sont trois droits distincts.
    public function viewAny(User $user): bool
    {
        return $user->hasPrivilege('regles_classement.voir') || $user->hasPrivilege('regles_classement.gerer');
    }

    public function create(User $user): bool
    {
        return $user->hasPrivilege('regles_classement.gerer');
    }

    public function update(User $user, RegleClassement $regle): bool
    {
        return $user->hasPrivilege('regles_classement.gerer');
    }

    public function delete(User $user, RegleClassement $regle): bool
    {
        return $user->hasPrivilege('regles_classement.gerer');
    }

    public function reanalyser(User $user): bool
    {
        return $user->hasPrivilege('regles_classement.reanalyser');
    }
}
