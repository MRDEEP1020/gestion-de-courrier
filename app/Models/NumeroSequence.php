<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumeroSequence extends Model
{
    // Compteur global par année, consommé par NumeroReferenceGenerator
    // (Module 1) — voir migration 2026_09_15_140100 (le compteur était par
    // service à l'origine, devenu global-par-année ; service_id supprimé).
    protected $fillable = ['annee', 'dernier_numero'];
}
