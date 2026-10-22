<?php

namespace App\Jobs;

use App\Events\BrouillonOcrTermine;
use App\Models\CourrierBrouillon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Module 1/2 — OCR d'un brouillon (flux scan-first, voir DECISIONS.md "Flux
// scan-first"). Volontairement un Job À PART de ProcessDocumentOcr plutôt
// qu'une généralisation : ProcessDocumentOcr est typé sur Courrier, écrit un
// CourrierHistorique (FK NOT NULL — un brouillon n'en a pas encore) et
// diffuse sur courrier.{id}. Réutilise en revanche telles quelles les
// méthodes statiques de ProcessDocumentOcr, déjà indépendantes de Courrier —
// aucune logique Tesseract/EXIF/contrôle qualité dupliquée.
class ProcessBrouillonOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Même raison que ProcessDocumentOcr::$timeout (2026-09-18) : ocrFichier()
    // est partagé, donc soumis au même besoin de délai plus long depuis le
    // rendu Ghostscript à 600 dpi et le double passage Tesseract par page.
    public int $timeout = 300;

    public function __construct(public CourrierBrouillon $brouillon) {}

    public function handle(): void
    {
        $this->brouillon->update(['ocr_statut' => 'en_cours']);

        $extension = strtolower(pathinfo($this->brouillon->fichier_path, PATHINFO_EXTENSION) ?: 'pdf');
        $fichierTemporaire = tempnam(sys_get_temp_dir(), 'gec_brouillon_ocr_').'.'.$extension;

        try {
            file_put_contents($fichierTemporaire, Storage::disk('s3')->get($this->brouillon->fichier_path));

            ProcessDocumentOcr::corrigerOrientation($fichierTemporaire, $extension);

            [$texte, $confiance] = ProcessDocumentOcr::ocrFichier($fichierTemporaire, $extension);

            $lisible = mb_strlen($texte) >= ProcessDocumentOcr::longueurMinimaleTexte()
                && $confiance !== null
                && $confiance >= ProcessDocumentOcr::confianceMinimale();

            $this->brouillon->update([
                'texte_ocr' => $texte !== '' ? $texte : null,
                'ocr_statut' => $lisible ? 'reussi' : 'echec_qualite',
                'ocr_confiance' => $confiance,
                'numero_tampon_detecte' => ProcessDocumentOcr::extraireNumeroTampon($texte),
            ]);

            $this->notifier($lisible ? 'reussi' : 'echec_qualite', $confiance);
        } finally {
            if (file_exists($fichierTemporaire)) {
                unlink($fichierTemporaire);
            }
        }
    }

    // Règle n°1 — échec loggé de façon exploitable. Pas de CourrierHistorique
    // possible ici (pas encore un Courrier) : le log serveur fait foi, et
    // l'agent voit le brouillon rester en "echec" dans sa liste "en attente".
    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du traitement OCR d\'un brouillon', [
            'brouillon_id' => $this->brouillon->id,
            'erreur' => $exception?->getMessage(),
        ]);

        $this->brouillon->update(['ocr_statut' => 'echec']);

        $this->notifier('echec');
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    private function notifier(string $statut, ?int $confiance = null): void
    {
        try {
            BrouillonOcrTermine::dispatch($this->brouillon->id, $this->brouillon->cree_par_id, $statut, $confiance);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
