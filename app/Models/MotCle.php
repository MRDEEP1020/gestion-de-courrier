<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MotCle extends Model
{
    protected $table = 'mots_cles';

    protected $fillable = ['libelle'];

    public function courriers(): BelongsToMany
    {
        return $this->belongsToMany(Courrier::class, 'courrier_mot_cle')->withPivot('source');
    }
}
