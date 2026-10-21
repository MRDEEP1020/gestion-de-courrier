<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Affectation extends Model
{
    // Append-only (Règle n°5) : chaque (ré)affectation est une nouvelle ligne, jamais une mise à jour.
    const UPDATED_AT = null;

    protected $fillable = ['courrier_id', 'user_id', 'affecte_par_id', 'motif_reaffectation'];

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function collaborateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function affectePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affecte_par_id');
    }
}
