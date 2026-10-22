<?php

namespace App\Services;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\DossierClassement;
use App\Models\DossierClassementHistorique;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// Module 3/9 — filer/retirer un courrier d'un dossier de classement. Même
// esprit que WorkflowService (transaction + historique immuable, Règle n°5)
// mais SANS machine à états : la classification n'a rien à voir avec
// Courrier::statut. Pas d'autorisation ici (même principe que
// WorkflowService : "l'autorisation est la responsabilité de l'appelant") —
// chaque composant (ShowCourrier/CourrierList/DossierClassementList) vérifie
// CourrierPolicy::classer() sur le courrier ET DossierClassementPolicy::view()
// sur le dossier AVANT d'appeler.
class DossierClassementService
{
    public function classer(Courrier $courrier, DossierClassement $dossier, User $auteur): void
    {
        DB::transaction(function () use ($courrier, $dossier, $auteur) {
            $courrier->update(['dossier_classement_id' => $dossier->id]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'classement_dossier',
                'commentaire' => "Classé dans « {$dossier->nom} »",
            ]);

            DossierClassementHistorique::create([
                'dossier_classement_id' => $dossier->id,
                'auteur_id' => $auteur->id,
                'action' => 'courrier_ajoute',
                'commentaire' => $courrier->numero_reference,
            ]);
        });
    }

    public function retirer(Courrier $courrier, User $auteur): void
    {
        if ($courrier->dossier_classement_id === null) {
            return;
        }

        $ancienDossierId = $courrier->dossier_classement_id;

        DB::transaction(function () use ($courrier, $ancienDossierId, $auteur) {
            $courrier->update(['dossier_classement_id' => null]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'declassement_dossier',
                'commentaire' => null,
            ]);

            DossierClassementHistorique::create([
                'dossier_classement_id' => $ancienDossierId,
                'auteur_id' => $auteur->id,
                'action' => 'courrier_retire',
                'commentaire' => $courrier->numero_reference,
            ]);
        });
    }
}
