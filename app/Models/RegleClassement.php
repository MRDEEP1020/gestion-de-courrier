<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegleClassement extends Model
{
    use HasFactory;

    // Module 3 — règle simple, éditable par un Administrateur (voir RegleClassementPolicy).
    protected $table = 'regles_classement';

    public const CHAMPS = ['objet', 'expediteur', 'texte_ocr'];

    protected $fillable = [
        'nom', 'mots_cles', 'champs', 'type_document_propose', 'service_propose_id', 'tags', 'priorite', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'mots_cles' => 'array',
            'champs' => 'array',
            'tags' => 'array',
            'actif' => 'boolean',
            'priorite' => 'integer',
        ];
    }

    public function servicePropose(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_propose_id');
    }
}
