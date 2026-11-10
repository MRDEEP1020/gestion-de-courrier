<?php

namespace App\Concerns;

use App\Models\Courrier;
use App\Models\PieceJointe;
use Illuminate\Support\Facades\Auth;

// Module 1/2 — rangement, sur le poste de numérisation, des scans importés
// depuis le dossier surveillé (2026-11-09, demande « les fichiers scannés et
// enregistrés restent sur la machine de l'utilisateur, rangés à partir du
// logiciel », voir DECISIONS.md « Scans conservés et rangés sur le poste de
// numérisation »). Le serveur ne touche jamais au poste : il dit seulement
// à la page (resources/js/scan-watcher.js) quel courrier chaque scan est
// devenu, et c'est le navigateur qui déplace le fichier dans
// « Courriers enregistrés/{année}/{mois}/{référence} ».
// Partagé par les deux pages qui importent (Numérisation, Nouveau courrier).
trait RangementScansLocaux
{
    /**
     * @param  array<int|string>  $brouillonIds  scans importés par CE poste
     * @return list<array{brouillonId: int, reference: string, annee: string, mois: string, piece: bool}>
     */
    public function infosRangement(array $brouillonIds): array
    {
        $ids = collect($brouillonIds)->map(fn ($id) => (int) $id)->filter()->unique()->take(200)->values();
        $utilisateur = Auth::user();

        if ($ids->isEmpty() || $utilisateur === null) {
            return [];
        }

        $infos = [];

        // Règle n°6 — seulement les courriers que la personne peut voir : un
        // scan devenu un courrier hors de son périmètre reste où il est.
        foreach (Courrier::with('service')->whereIn('brouillon_origine_id', $ids)->get() as $courrier) {
            if ($utilisateur->can('view', $courrier)) {
                $infos[] = $this->infoRangement($courrier->brouillon_origine_id, $courrier, false);
            }
        }

        foreach (PieceJointe::with('courrier.service')->whereIn('brouillon_origine_id', $ids)->get() as $piece) {
            if ($piece->courrier && $utilisateur->can('view', $piece->courrier)) {
                $infos[] = $this->infoRangement($piece->brouillon_origine_id, $piece->courrier, true);
            }
        }

        return $infos;
    }

    private function infoRangement(int $brouillonId, Courrier $courrier, bool $piece): array
    {
        return [
            'brouillonId' => $brouillonId,
            'reference' => $courrier->numero_reference,
            'annee' => $courrier->created_at->format('Y'),
            'mois' => $courrier->created_at->format('m'),
            'piece' => $piece,
        ];
    }
}
