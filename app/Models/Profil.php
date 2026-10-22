<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profil extends Model
{
    use HasFactory;

    // 5 profils phase 1 : Agent, Collaborateur, Responsable de service, Administrateur, DGA
    // Nommé "Profil" (pas "Role") pour rester cohérent avec le vocabulaire métier
    // de Nsia Assurances — voir DECISIONS.md

    protected $fillable = ['nom'];

    // Système de privilèges (2026-09-15, voir DECISIONS.md) : tous les
    // utilisateurs de ce profil héritent de ces privilèges — voir
    // User::hasPrivilege().
    public function privileges(): BelongsToMany
    {
        return $this->belongsToMany(Privilege::class);
    }

    // Page "Profils" (2026-09-23) : nombre d'utilisateurs par profil.
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
