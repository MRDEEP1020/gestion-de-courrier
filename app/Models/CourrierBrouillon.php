<?php

namespace App\Models;

use App\Jobs\PreparerApercuJob;
use App\Services\ApercuSecurise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourrierBrouillon extends Model
{
    // Module 1/2 — document scanné avant la création du Courrier (flux
    // scan-first). Voir DECISIONS.md "Flux scan-first" : table isolée, ne
    // touche à aucune garantie des Modules 3/4/8/9/10.

    // 2026-09-22 — trace l'origine d'un brouillon (demande explicite de
    // l'utilisateur : table admin des documents importés spécifiquement via
    // le dossier surveillé, distincte de la liste générale de
    // RegistrationForm::brouillonsEnAttente()). Voir
    // BrouillonScanService::creer() pour les points d'écriture.
    public const SOURCE_MANUEL = 'manuel';

    public const SOURCE_DOSSIER_SURVEILLE = 'dossier_surveille';

    protected $fillable = [
        'fichier_path', 'nom_original', 'type_mime', 'taille',
        'ocr_statut', 'texte_ocr', 'ocr_confiance', 'numero_tampon_detecte',
        'cree_par_id', 'source', 'finalise_le',
    ];

    // 2026-11-09 — aperçu sécurisé du document scanné (pages filigranées,
    // voir App\Services\ApercuSecurise), préparé dès le dépôt, avant l'OCR
    // dans la même file : prêt quand le formulaire se pré-remplit.
    protected static function booted(): void
    {
        static::created(function (CourrierBrouillon $brouillon) {
            if (config('services.archivage.apercu_auto') && $brouillon->fichier_path) {
                PreparerApercuJob::dispatch(ApercuSecurise::TYPE_BROUILLON, $brouillon->id)->afterCommit();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'finalise_le' => 'datetime',
        ];
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}
