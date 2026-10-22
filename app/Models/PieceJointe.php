<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PieceJointe extends Model
{
    // Écriture unique (Règle n°4 CONVENTIONS.md) : pas d'updated_at, jamais modifiée en place.
    const UPDATED_AT = null;

    // Eloquent déduirait "piece_jointes" du nom de classe ; la table voulue
    // (ARCHITECTURE.md) est "pieces_jointes".
    protected $table = 'pieces_jointes';

    protected $fillable = ['courrier_id', 'fichier_path', 'nom_original', 'type_mime', 'taille'];

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }
}
