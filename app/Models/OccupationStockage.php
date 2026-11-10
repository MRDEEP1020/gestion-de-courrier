<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Module 9 — un calcul de l'occupation du stockage (2026-11-09, voir
// App\Jobs\CalculerOccupationStockageJob).
class OccupationStockage extends Model
{
    public $timestamps = false;

    // Ordre et libellés d'affichage des types de fichiers.
    public const TYPES = [
        'documents' => 'Documents principaux',
        'pieces_jointes' => 'Pièces jointes',
        'pdfa' => 'Copies d\'archive PDF/A',
        'apercus' => 'Aperçus sécurisés (pages filigranées)',
        'brouillons' => 'Scans en attente d\'enregistrement',
        'avatars' => 'Photos de profil',
        'autres' => 'Autres fichiers',
    ];

    protected $table = 'occupations_stockage';

    protected $fillable = [
        'calcule_le', 'fichiers', 'octets', 'par_type',
        'sauvegarde_fichiers', 'sauvegarde_octets', 'sauvegarde_manquants',
        'disque_libre', 'disque_total',
    ];

    protected function casts(): array
    {
        return [
            'calcule_le' => 'datetime',
            'par_type' => 'array',
        ];
    }

    // « 37,6 Mo » — unités françaises (octet, Ko, Mo, Go, To).
    public static function taille(?int $octets): string
    {
        if ($octets === null) {
            return '—';
        }

        $unites = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $rang = 0;
        $valeur = (float) $octets;

        while ($valeur >= 1024 && $rang < count($unites) - 1) {
            $valeur /= 1024;
            $rang++;
        }

        return number_format($valeur, $rang === 0 ? 0 : 1, ',', ' ').' '.$unites[$rang];
    }
}
