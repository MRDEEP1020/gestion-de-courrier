<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Module 9 — empreinte SHA-256 d'un fichier stocké (2026-10-30, conformité de
// l'archivage) : scellée au premier passage, revérifiée chaque jour par
// VerifierIntegriteFichiersJob. Une empreinte qui ne correspond plus au
// contenu = fichier altéré sur le stockage.
class EmpreinteFichier extends Model
{
    public $timestamps = false;

    public const TYPE_COURRIER = 'courrier';

    public const TYPE_COURRIER_PDFA = 'courrier_pdfa';

    public const TYPE_PIECE_JOINTE = 'piece_jointe';

    public const STATUT_OK = 'ok';

    public const STATUT_ALTEREE = 'alteree';

    public const STATUT_MANQUANT = 'manquant';

    public const STATUT_DETRUIT = 'detruit';

    protected $table = 'empreintes_fichiers';

    protected $fillable = ['fichier_type', 'fichier_id', 'chemin', 'sha256', 'scelle_le', 'verifie_le', 'statut'];

    protected function casts(): array
    {
        return [
            'scelle_le' => 'datetime',
            'verifie_le' => 'datetime',
        ];
    }
}
