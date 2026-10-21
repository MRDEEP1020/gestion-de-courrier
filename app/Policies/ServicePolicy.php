<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

// Configuration administrateur — listes de référence
// (specifications-modules-GEC.md, "transversal") : la liste des
// services/directions doit être gérable par l'Administrateur, sans
// intervention développeur, au même titre que les règles de classement
// (voir RegleClassementPolicy, même forme).
class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPrivilege('services.gerer');
    }

    // 2026-09-22 — module "Organisation" : $parent accepté pour rester
    // appelable comme $this->authorize('create', [Service::class, $parent])
    // (même convention que DossierClassementPolicy::create()), mais
    // 'services.gerer' reste un privilège GLOBAL — pas de restriction par
    // branche de l'arbre (non demandé, ne pas fabriquer).
    public function create(User $user, ?Service $parent = null): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Service $service): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->viewAny($user);
    }
}
