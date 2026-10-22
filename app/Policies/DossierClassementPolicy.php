<?php

namespace App\Policies;

use App\Models\DossierClassement;
use App\Models\User;

// Module 3 — auto-découverte Laravel (App\Models\DossierClassement ->
// App\Policies\DossierClassementPolicy), aucune registration nécessaire
// (ce projet n'a pas d'AuthServiceProvider/Gate::policy(), voir CourrierPolicy).
class DossierClassementPolicy
{
    // Garde la page elle-même : quiconque peut créer, gérer tout, ou
    // simplement chercher des courriers (déjà utilisé par CourrierList)
    // peut ouvrir /dossiers-classement — le filtrage RÉEL par dossier se
    // fait ensuite via view() et Courrier::scopeVisiblePar().
    // 2026-09-23 : privilège de lecture dédié dossiers_classement.voir
    // (remplace le raccourci courriers.rechercher, mêmes profils par défaut).
    public function viewAny(User $user): bool
    {
        return $user->hasPrivilege('dossiers_classement.voir')
            || $user->hasPrivilege('dossiers_classement.creer')
            || $user->hasPrivilege('dossiers_classement.gerer_tout');
    }

    // Un dossier précis n'est visible qu'à son créateur/responsable, à un
    // utilisateur explicitement partagé (dossier_classement_user), ou à
    // gerer_tout — PAS un privilège de périmètre générique (voir
    // DECISIONS.md : "ce n'est PAS un privilège").
    public function view(User $user, DossierClassement $dossier): bool
    {
        if ($user->hasPrivilege('dossiers_classement.gerer_tout')) {
            return true;
        }

        if ($user->id === $dossier->cree_par_id || $user->id === $dossier->responsable_id) {
            return true;
        }

        return $dossier->utilisateursAutorises()->where('users.id', $user->id)->exists();
    }

    // $parent = null pour un dossier racine. Créer un SOUS-dossier exige en
    // plus de pouvoir VOIR le parent (sinon on pourrait créer "à l'aveugle"
    // dans un dossier qu'on ne devrait pas savoir exister).
    public function create(User $user, ?DossierClassement $parent = null): bool
    {
        if (! $user->hasPrivilege('dossiers_classement.creer') && ! $user->hasPrivilege('dossiers_classement.gerer_tout')) {
            return false;
        }

        return $parent === null || $user->hasPrivilege('dossiers_classement.gerer_tout') || $this->view($user, $parent);
    }

    // Renommer/modifier — même esprit que courriers.modifier_tout vs
    // modifier_propre : gerer_tout, ou créateur du dossier.
    // 2026-09-23 : un privilège par action sur SES dossiers (modifier,
    // partager, supprimer) au lieu de dériver de "créer" ; gerer_tout
    // couvre toujours tous les dossiers.
    public function update(User $user, DossierClassement $dossier): bool
    {
        return $this->actionSurSonDossier($user, $dossier, 'dossiers_classement.modifier');
    }

    private function actionSurSonDossier(User $user, DossierClassement $dossier, string $privilege): bool
    {
        return $user->hasPrivilege('dossiers_classement.gerer_tout')
            || ($user->hasPrivilege($privilege) && $user->id === $dossier->cree_par_id);
    }

    // Déplacer — même autorisation que update() sur le dossier déplacé ;
    // le nouveau parent (nullable = racine) doit en plus être VISIBLE. La
    // prévention de cycle (dossier déplacé dans un de ses propres
    // descendants) n'est PAS une question d'autorisation — c'est une règle
    // métier vérifiée par l'appelant via DossierClassement::estDescendantDe()
    // (même séparation que WorkflowService : Policy = qui, composant = règle).
    public function deplacer(User $user, DossierClassement $dossier, ?DossierClassement $nouveauParent = null): bool
    {
        if (! $this->update($user, $dossier)) {
            return false;
        }

        return $nouveauParent === null || $user->hasPrivilege('dossiers_classement.gerer_tout') || $this->view($user, $nouveauParent);
    }

    // Partager — "le créateur du dossier définit qui y a accès" (spec) :
    // même autorisation que update(), jamais ouvert à un simple
    // bénéficiaire d'un partage existant.
    public function partager(User $user, DossierClassement $dossier): bool
    {
        return $this->actionSurSonDossier($user, $dossier, 'dossiers_classement.partager');
    }

    // Supprimer — même autorisation que update() ; le blocage "dossier non
    // vide" (sous-dossiers ou courriers présents) est une règle métier
    // vérifiée par le composant AVANT d'appeler ->delete(), pas ici.
    public function delete(User $user, DossierClassement $dossier): bool
    {
        return $this->actionSurSonDossier($user, $dossier, 'dossiers_classement.supprimer');
    }
}
