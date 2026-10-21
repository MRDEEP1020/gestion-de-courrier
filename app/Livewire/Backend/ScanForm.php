<?php

namespace App\Livewire\Backend;

use App\Jobs\ProcessDocumentOcr;
use App\Jobs\ReplicateFichierJob;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Parametre;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Numériser le document')]
class ScanForm extends Component
{
    use WithFileUploads;

    // Plus petit côté accepté pour une image (px). Un A4 scanné à 200 dpi
    // fait ~1650 px de large ; une photo de téléphone dépasse largement.
    // Configurable depuis l'UI ("Group A", 2026-09-24) — ex-`const
    // RESOLUTION_MINIMALE`, voir DECISIONS.md "Paramètres système
    // configurables — Groupe A/B".
    public static function resolutionMinimale(): int
    {
        return Parametre::actuel()->scan_resolution_minimale;
    }

    // Propriété : ID primitif uniquement (pas de modèle Eloquent complet) — Règle n°2.
    public int $courrierId;

    public string $numeroReference = '';

    // Exception à la Règle n°2 : mécanisme d'upload natif de Livewire.
    public $document = null;

    // Règle n°4 — le document scanné est en écriture unique une fois validé
    // par l'OCR (ocr_statut = 'reussi') ; un nouveau scan reste possible tant
    // que ce n'est pas le cas (contrôle qualité du spec Module 2).
    public bool $dejaValide = false;

    // Module 1/3 — voir le garde-fou dans numeriser() ci-dessous : reflété
    // ici pour bloquer le formulaire côté vue aussi, pas seulement à la soumission.
    public bool $estConfidentiel = false;

    public function mount(int $courrierId): void
    {
        $courrier = Courrier::findOrFail($courrierId);

        // Règle n°6 — jamais confiance en un ID client sans vérifier les droits côté serveur.
        // 'renumeriser' = update + courriers.numeriser (2026-09-23).
        $this->authorize('renumeriser', $courrier);

        $this->courrierId = $courrierId;
        $this->numeroReference = $courrier->numero_reference;
        $this->dejaValide = $courrier->ocr_statut === 'reussi';
        // "confidentialite" est un entier 1-5 depuis le 2026-09-21 — > 1
        // signifie "au-dessus du niveau le plus bas" (voir
        // User::NIVEAU_CONFIDENTIALITE_MAX).
        $this->estConfidentiel = $courrier->confidentialite > 1;
    }

    public function numeriser(): void
    {
        $courrier = Courrier::findOrFail($this->courrierId);

        $this->authorize('renumeriser', $courrier);

        if ($courrier->ocr_statut === 'reussi') {
            Flux::toast(variant: 'danger', text: __('Ce courrier a déjà un document validé — il ne peut pas être remplacé.'));

            return;
        }

        // Module 1/3 — "le champ confidentiel désactive tout le pipeline
        // OCR/extraction/classification" (specifications-modules-GEC.md) :
        // défense en profondeur, indépendante du mode confidentiel de
        // RegistrationForm (qui ne propose jamais cette action) — un courrier confidentiel ne
        // doit jamais être scanné, quel que soit le point d'entrée.
        if ($courrier->confidentialite > 1) {
            Flux::toast(variant: 'danger', text: __('Un courrier confidentiel ne peut pas être scanné — voir "Courrier confidentiel" dans le menu.'));

            return;
        }

        $this->validate([
            'document' => [
                'required', 'file', 'mimes:pdf,jpg,jpeg,png,tiff', 'max:20480',
                // Règle métier Module 2 "taille et résolution encadrées" : une image
                // trop petite (miniature, capture compressée) ne donnera rien à l'OCR.
                function (string $attribute, mixed $fichier, \Closure $fail) {
                    if (! str_starts_with((string) $fichier->getMimeType(), 'image/')) {
                        return;
                    }

                    [$largeur, $hauteur] = @getimagesize($fichier->getRealPath()) ?: [0, 0];

                    $resolutionMinimale = self::resolutionMinimale();
                    if (min($largeur, $hauteur) < $resolutionMinimale) {
                        $fail(__('Image trop petite (:l×:h px) : au moins :min px de côté sont nécessaires pour l\'OCR. Utilisez un scan ou une photo en pleine résolution.', [
                            'l' => $largeur, 'h' => $hauteur, 'min' => $resolutionMinimale,
                        ]));
                    }
                },
            ],
        ]);

        $courrier->loadMissing('service');

        $extension = $this->document->getClientOriginalExtension();

        // Règle n°4 — nommage prévisible par année/service/numéro de référence,
        // un seul document principal par courrier (contrairement aux pièces
        // jointes, qui sont en table séparée un-à-plusieurs). segmentClassement()
        // replie sur "_en_attente" tant qu'un courrier entrant n'a pas encore
        // de service (voir Courrier::segmentClassement() et DECISIONS.md).
        $chemin = sprintf(
            'courriers/%d/%s/%s.%s',
            now()->year,
            $courrier->segmentClassement(),
            $courrier->numero_reference,
            $extension,
        );

        Storage::disk('s3')->putFileAs(dirname($chemin), $this->document, basename($chemin));

        // Règle n°4 (complétée) — copie de secours asynchrone, jamais bloquante
        // (Règle n°1) ; voir DECISIONS.md "Stockage hybride".
        ReplicateFichierJob::dispatch($chemin)->onQueue('replication');

        $courrier->update([
            'fichier_path' => $chemin,
            'ocr_statut' => 'en_cours',
            'texte_ocr' => null,
        ]);

        // Règle n°5 — toute action crée une entrée d'historique immuable.
        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => Auth::id(),
            'action' => 'numerisation',
            'commentaire' => null,
        ]);

        // Règle n°1 — jamais de traitement OCR synchrone dans le cycle requête/réponse.
        ProcessDocumentOcr::dispatch($courrier)->onQueue('ocr');

        Flux::toast(variant: 'success', text: __('Document envoyé, traitement OCR en cours.'));

        $this->redirect(route('courriers.show', ['courrierId' => $this->courrierId]), navigate: true);
    }

    public function render()
    {
        return view('frontend::scanForm');
    }
}
