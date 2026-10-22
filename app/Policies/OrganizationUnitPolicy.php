<?php

namespace App\Policies;

use App\Models\OrganizationUnit;
use App\Models\User;

// Module "Organisation" v2 (2026-09-22, spec §18) — auto-découverte Laravel
// (App\Models\OrganizationUnit -> App\Policies\OrganizationUnitPolicy),
// aucune registration nécessaire (ce projet n'a pas d'AuthServiceProvider/
// Gate::policy(), voir CourrierPolicy/DossierClassementPolicy).
//
// 'organisation.manage_structure' est un privilège "tout faire" qui court-
// circuite create/update/deplacer/deactivate — 'organisation.create'/
// 'update'/'deactivate' restent des clés séparées pour permettre une
// délégation plus fine si besoin (ex. un profil autorisé à désactiver sans
// pouvoir déplacer toute la structure).
class OrganizationUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPrivilege('organisation.view');
    }

    public function view(User $user, OrganizationUnit $unite): bool
    {
        return $this->viewAny($user);
    }

    // $parent = null pour un nœud racine. Créer un enfant exige en plus de
    // pouvoir VOIR le parent (même patron que DossierClassementPolicy::create()) —
    // sinon on pourrait créer "à l'aveugle" sous un nœud qu'on ne devrait pas
    // savoir exister. 'organisation.view' est global dans cette version
    // (pas de périmètre par branche pour la gestion de la structure elle-même,
    // non demandé), donc cette clause reste toujours vraie en pratique — gardée
    // pour la cohérence du patron et une éventuelle restriction future.
    public function create(User $user, ?OrganizationUnit $parent = null): bool
    {
        if (! $user->hasPrivilege('organisation.create') && ! $user->hasPrivilege('organisation.manage_structure')) {
            return false;
        }

        return $parent === null || $this->view($user, $parent);
    }

    public function update(User $user, OrganizationUnit $unite): bool
    {
        return $user->hasPrivilege('organisation.update') || $user->hasPrivilege('organisation.manage_structure');
    }

    // Déplacer un nœud — même autorisation que update() sur le nœud déplacé,
    // plus la visibilité de la nouvelle destination (nullable = racine). La
    // prévention de cycle est une règle métier vérifiée par le composant via
    // OrganizationUnit::estDescendantDe(), pas une question d'autorisation
    // (même séparation que DossierClassementPolicy::deplacer()).
    public function deplacer(User $user, OrganizationUnit $unite, ?OrganizationUnit $nouveauParent = null): bool
    {
        if (! $this->update($user, $unite)) {
            return false;
        }

        return $nouveauParent === null || $this->view($user, $nouveauParent);
    }

    // Spec §15 — "NE PAS supprimer... Préférer Désactiver" : JAMAIS de
    // suppression physique pour ce modèle (plus strict que Service/
    // DossierClassement, qui autorisent la suppression si vide) — pas
    // d'ability delete() du tout, seulement deactivate()/activer (même
    // ability, réversible dans les deux sens).
    public function deactivate(User $user, OrganizationUnit $unite): bool
    {
        return $user->hasPrivilege('organisation.deactivate') || $user->hasPrivilege('organisation.manage_structure');
    }

    // Onglet "Utilisateurs" du panneau de détails (spec §10) — rattacher/
    // retirer un utilisateur, changer son rôle dans l'unité.
    public function manageUsers(User $user, OrganizationUnit $unite): bool
    {
        return $user->hasPrivilege('organisation.manage_users') || $user->hasPrivilege('organisation.manage_structure');
    }
}
