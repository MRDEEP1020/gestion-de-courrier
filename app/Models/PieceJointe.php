<?php

namespace App\Models;

use App\Jobs\PreparerApercuJob;
use App\Jobs\ScellerFichierJob;
use App\Services\ApercuSecurise;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PieceJointe extends Model
{
    // Écriture unique (Règle n°4 CONVENTIONS.md) : pas d'updated_at, jamais modifiée en place.
    const UPDATED_AT = null;

    // Module 9 (2026-10-30, conformité de l'archivage) — empreinte SHA-256
    // scellée en file d'attente dès l'ajout de la pièce jointe.
    protected static function booted(): void
    {
        // Scellée à l'ajout, revérifiée à son nouveau chemin après un
        // déplacement (acceptation par le service), comme le document
        // principal (Courrier::booted()).
        // created/updated plutôt que saved + wasRecentlyCreated : ce dernier
        // reste vrai pour toute la vie de l'instance.
        $sceller = fn (PieceJointe $pieceJointe) => ScellerFichierJob::dispatch(EmpreinteFichier::TYPE_PIECE_JOINTE, $pieceJointe->id)->afterCommit();

        static::created(function (PieceJointe $pieceJointe) use ($sceller) {
            if (config('services.archivage.scellement_auto')) {
                $sceller($pieceJointe);
            }

            // 2026-11-09 — une pièce jointe se consulte en aperçu sécurisé
            // (jamais téléchargée) : pages préparées dès l'ajout.
            if (config('services.archivage.apercu_auto')) {
                PreparerApercuJob::dispatch(ApercuSecurise::TYPE_PIECE_JOINTE, $pieceJointe->id)->afterCommit();
            }
        });

        static::updated(function (PieceJointe $pieceJointe) use ($sceller) {
            if (config('services.archivage.scellement_auto') && $pieceJointe->wasChanged('fichier_path')) {
                $sceller($pieceJointe);
            }
        });
    }

    // Eloquent déduirait "piece_jointes" du nom de classe ; la table voulue
    // (ARCHITECTURE.md) est "pieces_jointes".
    protected $table = 'pieces_jointes';

    // brouillon_origine_id (2026-11-09) : scan d'origine, voir
    // App\Concerns\RangementScansLocaux.
    protected $fillable = ['courrier_id', 'fichier_path', 'nom_original', 'type_mime', 'taille', 'brouillon_origine_id'];

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }
}
