<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    // Même piège que Courrier/User/DossierClassement (voir leurs commentaires
    // respectifs) : Model::create() ne relit pas le DEFAULT SQL sur
    // l'instance en mémoire.
    protected $attributes = [
        'actif' => true,
    ];

    // 'code' : segment court et unique utilisé dans le numéro de référence
    // (Module 1, ex. GEC-2026-DIR-000123) — voir NumeroReferenceGenerator.
    // 'actif' : désactivation plutôt que suppression physique si le service
    // est déjà référencé (specifications-modules-GEC.md, "Configuration
    // administrateur — listes de référence").
    protected $fillable = ['nom', 'code', 'responsable_id', 'actif'];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function courriers(): HasMany
    {
        return $this->hasMany(Courrier::class);
    }

    // Module 4/6 — utilisateurs (tous profils) rattachés à ce service.
    public function utilisateurs(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
