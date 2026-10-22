<?php

namespace App\Services;

use App\Jobs\ProcessBrouillonOcr;
use App\Jobs\ReplicateFichierJob;
use App\Models\CourrierBrouillon;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Module 1/2 — création d'un CourrierBrouillon à partir d'un document
// uploadé, partagée entre tous les points d'entrée "scan d'abord"
// (ScanPremier::numeriser()/numeriserAutomatique(), RegistrationForm::
// numeriserAutomatique() pour le dossier surveillé — voir DECISIONS.md
// "Watcher automatique sur le formulaire d'enregistrement", 2026-09-09).
// authorize()/validate() restent dans CHAQUE composant Livewire appelant
// (Règle n°6 — ce service n'est pas un point d'entrée qui contournerait
// ces contrôles), mais les règles de validation (reglesValidation()) et le
// stockage/la création (creer()) sont partagés pour ne jamais diverger
// entre points d'entrée.
class BrouillonScanService
{
    public static function reglesValidation(int $resolutionMinimale): array
    {
        return [
            'required', 'file', 'mimes:pdf,jpg,jpeg,png,tiff', 'max:20480',
            function (string $attribute, mixed $fichier, Closure $fail) use ($resolutionMinimale) {
                if (! str_starts_with((string) $fichier->getMimeType(), 'image/')) {
                    return;
                }

                [$largeur, $hauteur] = @getimagesize($fichier->getRealPath()) ?: [0, 0];

                if (min($largeur, $hauteur) < $resolutionMinimale) {
                    $fail(__('Image trop petite (:l×:h px) : au moins :min px de côté sont nécessaires pour l\'OCR. Utilisez un scan ou une photo en pleine résolution.', [
                        'l' => $largeur, 'h' => $hauteur, 'min' => $resolutionMinimale,
                    ]));
                }
            },
        ];
    }

    // $source distingue un import manuel d'un import automatique via le
    // dossier surveillé (2026-09-22, demande explicite de l'utilisateur) —
    // défaut 'manuel' pour que les appelants existants qui ne le précisent
    // pas restent inchangés.
    public function creer(UploadedFile $document, string $source = CourrierBrouillon::SOURCE_MANUEL): CourrierBrouillon
    {
        $extension = $document->getClientOriginalExtension();

        // Chemin temporaire distinct de la convention courriers/{annee}/{service}/...
        // (service/numéro pas encore connus tant que le Courrier n'existe pas).
        $chemin = sprintf('brouillons/%s.%s', Str::uuid(), $extension);

        Storage::disk('s3')->putFileAs(dirname($chemin), $document, basename($chemin));

        // Règle n°4 (complétée) — copie de secours asynchrone dès le dépôt,
        // comme n'importe quel autre document (voir DECISIONS.md "Stockage hybride").
        ReplicateFichierJob::dispatch($chemin)->onQueue('replication');

        $brouillon = CourrierBrouillon::create([
            'fichier_path' => $chemin,
            'nom_original' => $document->getClientOriginalName(),
            'type_mime' => $document->getMimeType(),
            'taille' => $document->getSize(),
            'cree_par_id' => Auth::id(),
            'source' => $source,
        ]);

        // Règle n°1 — jamais de traitement OCR synchrone.
        ProcessBrouillonOcr::dispatch($brouillon)->onQueue('ocr');

        return $brouillon;
    }
}
