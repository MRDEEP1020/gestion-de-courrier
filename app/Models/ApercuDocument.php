<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Module 9 — pages préparées pour l'aperçu sécurisé d'un document (2026-11-09,
// voir App\Services\ApercuSecurise).
class ApercuDocument extends Model
{
    public $timestamps = false;

    public const STATUT_PRET = 'pret';

    public const STATUT_NON_SUPPORTE = 'non_supporte';

    protected $table = 'apercus_documents';

    protected $fillable = ['fichier_type', 'fichier_id', 'statut', 'pages', 'genere_le'];

    protected function casts(): array
    {
        return [
            'pages' => 'array',
            'genere_le' => 'datetime',
        ];
    }
}
