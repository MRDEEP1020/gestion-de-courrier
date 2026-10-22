<?php

namespace App\Policies;

use App\Models\User;

// Système de privilèges (2026-09-15, voir DECISIONS.md "Système de
// privilèges") : gère l'accès à la page d'administration des privilèges
// elle-même.
class PrivilegePolicy
{
    // Garde-fou anti-verrouillage : le PROFIL "Administrateur" garde un
    // accès câblé en dur ici, en plus du privilège dynamique
    // `privileges.gerer` — sans ça, un administrateur qui se retire ce
    // privilège par erreur depuis l'UI perdrait tout moyen de se le
    // redonner. C'est la SEULE exception au retrofit complet du contrôle
    // d'accès : toutes les autres abilities de l'application (CourrierPolicy,
    // CourrierBrouillonPolicy, RegleClassementPolicy) sont 100 % pilotées
    // par les privilèges, sans filet de sécurité caché.
    public function gerer(User $user): bool
    {
        return $user->profil?->nom === 'Administrateur' || $user->hasPrivilege('privileges.gerer');
    }

    // Page "Utilisateurs & Accès" (2026-09-23, menus pilotés par privilège) :
    // coordonnées, activation, mot de passe, destinataires de transfert.
    // Créer un compte ou changer profil/niveau/périmètre/permissions exige EN
    // PLUS gerer() ci-dessus — sinon ce privilège suffirait à se donner le
    // profil Administrateur. Même garde-fou anti-verrouillage que gerer().
    // Créer un profil (2026-09-23) : gérer les privilèges + profils.creer,
    // avec le même garde-fou Administrateur.
    public function creerProfil(User $user): bool
    {
        return $user->profil?->nom === 'Administrateur'
            || ($user->hasPrivilege('privileges.gerer') && $user->hasPrivilege('profils.creer'));
    }

    public function gererUtilisateurs(User $user): bool
    {
        return $this->gerer($user) || $user->hasPrivilege('utilisateurs.gerer');
    }
}
