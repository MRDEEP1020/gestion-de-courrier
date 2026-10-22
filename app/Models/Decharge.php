<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Module 9 — "Décharge" (reçu d'emprunt d'un original physique archivé,
// specifications-modules-GEC.md point 5). Jamais supprimée (Règle n°5) :
// une décharge "rendue" garde sa ligne, seul `rendu_le` se renseigne.
class Decharge extends Model
{
    protected $fillable = [
        'numero_reference',
        'courrier_id',
        'emprunteur_id',
        'emis_par_id',
        'lieu_rangement',
        'motif',
        'emprunte_le',
        'rendu_le',
    ];

    protected function casts(): array
    {
        return [
            'emprunte_le' => 'datetime',
            'rendu_le' => 'datetime',
        ];
    }

    // Numéro de reçu dérivé de l'id auto-incrémenté (voir commentaire de la
    // migration) — généré ici plutôt que confié à l'appelant, pour qu'un
    // seul point du code sache comment ce numéro est construit.
    protected static function booted(): void
    {
        static::created(function (self $decharge) {
            $decharge->update([
                'numero_reference' => 'DECH-'.$decharge->created_at->year.'-'.str_pad((string) $decharge->id, 6, '0', STR_PAD_LEFT),
            ]);
        });
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function emprunteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emprunteur_id');
    }

    public function emisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emis_par_id');
    }

    public function estRendue(): bool
    {
        return $this->rendu_le !== null;
    }
}
