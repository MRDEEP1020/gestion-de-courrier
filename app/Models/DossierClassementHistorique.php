<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Module 3/5 — historique append-only d'un dossier de classement, copie
// conforme de CourrierHistorique (table dédiée, pas polymorphique).
class DossierClassementHistorique extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['dossier_classement_id', 'auteur_id', 'action', 'commentaire'];

    public function dossierClassement(): BelongsTo
    {
        return $this->belongsTo(DossierClassement::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
