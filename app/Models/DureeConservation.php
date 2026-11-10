<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Module 9 — durée de conservation d'un type de document (2026-10-30,
// conformité de l'archivage) ; un type absent suit la durée par défaut de
// Paramètres système (Parametre::conservation_annees_defaut).
class DureeConservation extends Model
{
    protected $table = 'durees_conservation';

    protected $fillable = ['type_document', 'annees'];
}
