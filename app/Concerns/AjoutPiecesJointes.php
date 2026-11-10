<?php

namespace App\Concerns;

use App\Models\Courrier;
use App\Services\PieceJointeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Throwable;

// Module 1 (2026-10-23, demande explicite de l'utilisateur : « l'agent doit
// pouvoir choisir plus d'un fichier pour enregistrer un courrier, comme une
// facture et un simple courrier envoyés par la même personne ») — plusieurs
// pièces jointes par courrier, à l'enregistrement comme à la modification.
// Le document PRINCIPAL reste unique (scan lu par l'OCR, voir Module 2) ;
// ces fichiers s'y ajoutent dans `pieces_jointes` (déjà un-à-plusieurs).
//
// L'agent peut choisir ses fichiers en plusieurs fois : le champ de
// sélection ($nouvellesPiecesJointes) est vidé après chaque choix, les
// fichiers retenus s'accumulent dans $piecesJointes, chacun retirable avant
// l'enregistrement. Exception à la Règle n°2 (propriétés primitives) :
// mécanisme d'upload natif de Livewire, sans alternative primitive. Le
// composant doit utiliser Livewire\WithFileUploads.
trait AjoutPiecesJointes
{
    public array $piecesJointes = [];

    public array $nouvellesPiecesJointes = [];

    protected static function maxPiecesJointes(): int
    {
        return 10;
    }

    protected static function reglesPieceJointe(): array
    {
        return ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];
    }

    // Chaque sélection est vérifiée avant d'être ajoutée à la liste : un
    // fichier refusé n'empêche jamais de choisir les suivants.
    public function updatedNouvellesPiecesJointes(): void
    {
        $this->resetErrorBag('piecesJointes');

        $validateur = Validator::make(
            ['fichiers' => $this->nouvellesPiecesJointes],
            ['fichiers.*' => self::reglesPieceJointe()],
            [],
            ['fichiers.*' => __('pièce jointe')],
        );

        if ($validateur->fails()) {
            $this->nouvellesPiecesJointes = [];
            $this->addError('piecesJointes', $validateur->errors()->first());

            return;
        }

        $this->piecesJointes = array_values(array_merge($this->piecesJointes, $this->nouvellesPiecesJointes));
        $this->nouvellesPiecesJointes = [];

        if (count($this->piecesJointes) > self::maxPiecesJointes()) {
            $this->piecesJointes = array_slice($this->piecesJointes, 0, self::maxPiecesJointes());
            $this->addError('piecesJointes', __(':n pièces jointes au maximum par courrier.', ['n' => self::maxPiecesJointes()]));
        }
    }

    public function retirerPieceJointe(int $index): void
    {
        unset($this->piecesJointes[$index]);
        $this->piecesJointes = array_values($this->piecesJointes);
        $this->resetErrorBag('piecesJointes');
    }

    // Règles revérifiées à l'enregistrement (Règle n°6 — jamais fait
    // confiance à l'état posté par le client).
    protected function reglesPiecesJointes(): array
    {
        return [
            'piecesJointes' => ['array', 'max:'.self::maxPiecesJointes()],
            'piecesJointes.*' => self::reglesPieceJointe(),
        ];
    }

    // Rattache chaque fichier au courrier (stockage S3, historique, copie de
    // secours — voir PieceJointeService). Un échec de stockage n'annule ni le
    // courrier ni les autres fichiers : retourne le nombre d'échecs.
    protected function attacherPiecesJointes(Courrier $courrier, PieceJointeService $service): int
    {
        $echecs = 0;

        foreach ($this->piecesJointes as $fichier) {
            try {
                $service->attacher($courrier, $fichier, Auth::id());
            } catch (Throwable $e) {
                report($e);
                $echecs++;
            }
        }

        return $echecs;
    }
}
