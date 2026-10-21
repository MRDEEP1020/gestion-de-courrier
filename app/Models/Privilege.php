<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

// Module 9 — "droits d'accès gérés finement" : catalogue de privilèges
// créables/assignables à un profil et/ou à des utilisateurs individuels
// sans changer de code — voir DECISIONS.md "Système de privilèges".
//
// 2026-09-21 — page "Utilisateurs & Accès" reconstruite depuis la maquette
// fournie par l'utilisateur : le catalogue de privilèges lui-même devient
// FIXE (plus de création/suppression via l'admin, voir le journal de développement) —
// seule leur ASSIGNATION par utilisateur change de présentation, regroupée
// par module avec une étiquette Lecture/Écriture/Administratif par ligne.
class Privilege extends Model
{
    use HasFactory;

    protected $fillable = ['cle', 'nom', 'description', 'type'];

    // Regroupement par module pour la modale "Gestion des permissions" — le
    // préfixe avant le premier point de `cle` (ex. "courriers.creer" →
    // "courriers"), jamais une nouvelle colonne : c'est déjà la convention
    // de nommage réelle des 26 privilèges seedés, pas la peine d'ajouter une
    // colonne redondante qui pourrait diverger de `cle`.
    public function moduleCle(): string
    {
        return Str::before($this->cle, '.');
    }

    // Libellé + icône par module — les 6 modules RÉELS présents dans
    // PrivilegeSeeder (pas les 8 modules fictifs de la maquette GPT, dont les
    // compteurs ne correspondent à aucune donnée réelle de ce projet).
    public const MODULES = [
        'courriers' => ['label' => 'Courriers', 'icon' => 'envelope'],
        'dossiers_classement' => ['label' => 'Dossiers de classement', 'icon' => 'folder'],
        'regles_classement' => ['label' => 'Règles de classement', 'icon' => 'adjustments-horizontal'],
        'brouillons' => ['label' => 'Brouillons', 'icon' => 'document'],
        'privileges' => ['label' => 'Privilèges', 'icon' => 'key'],
        'dashboard' => ['label' => 'Tableau de bord', 'icon' => 'chart-bar'],
        'services' => ['label' => 'Services', 'icon' => 'building-office-2'],
        'organisation' => ['label' => 'Organisation', 'icon' => 'building-office-2'],
        // 2026-09-23 — menus pilotés par privilège (voir PrivilegeSeeder).
        'utilisateurs' => ['label' => 'Utilisateurs', 'icon' => 'users'],
        'statistiques' => ['label' => 'Statistiques & Rapports', 'icon' => 'chart-bar'],
        'administration' => ['label' => 'Administration', 'icon' => 'cog-6-tooth'],
        'general' => ['label' => 'Général', 'icon' => 'home'],
        'profils' => ['label' => 'Profils', 'icon' => 'identification'],
    ];

    public function profils(): BelongsToMany
    {
        return $this->belongsToMany(Profil::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
