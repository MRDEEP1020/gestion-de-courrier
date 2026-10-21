<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

// Module 3 — "Dossiers de classement" (specifications-modules-GEC.md) :
// PREMIER modèle auto-référencé (parent_id) de ce projet. Voir
// DECISIONS.md "Module 3 — dossiers de classement" (2026-09-16).
class DossierClassement extends Model
{
    use SoftDeletes;

    protected $table = 'dossiers_classement';

    // Même piège que Courrier/User (voir leurs commentaires respectifs) :
    // Model::create() ne relit pas le DEFAULT SQL sur l'instance en mémoire.
    protected $attributes = [
        'statut' => 'actif',
    ];

    protected $fillable = [
        'nom',
        'description',
        'parent_id',
        'service_id',
        'responsable_id',
        'cree_par_id',
        'statut',
        'reference_localisation_physique',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function courriers(): HasMany
    {
        return $this->hasMany(Courrier::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }

    // Module 3/9 — partage par utilisateur précis, PAS un privilège (voir
    // PrivilegeSeeder.php, commentaire au-dessus de dossiers_classement.creer).
    public function utilisateursAutorises(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dossier_classement_user');
    }

    // Module 5 — historique append-only, même principe que Courrier::historiques().
    public function historiques(): HasMany
    {
        return $this->hasMany(DossierClassementHistorique::class)->latest('created_at');
    }

    // Compte les documents de ce dossier ET de tous ses descendants, sans
    // requête supplémentaire — parcourt une Collection déjà chargée en
    // mémoire (voir DossierClassementList::tousLesDossiersAccessibles(),
    // chargée avec ->withCount('courriers')). Jamais de requête récursive :
    // le volume attendu (dossiers créés à la main par service) reste petit,
    // et charger l'arbre entier une fois est toujours moins coûteux qu'une
    // requête par nœud affiché.
    public function compterDocumentsDescendants(Collection $tousLesDossiers): int
    {
        $total = $this->courriers_count ?? 0;

        foreach ($tousLesDossiers->where('parent_id', $this->id) as $enfant) {
            $total += $enfant->compterDocumentsDescendants($tousLesDossiers);
        }

        return $total;
    }

    // Anti-cycle pour "Déplacer" — un dossier ne doit jamais pouvoir devenir
    // son propre sous-dossier. Règle métier vérifiée par le composant avant
    // le déplacement, pas une question d'autorisation (voir
    // DossierClassementPolicy::deplacer()).
    public function estDescendantDe(int $cibleId, Collection $tousLesDossiers): bool
    {
        foreach ($tousLesDossiers->where('parent_id', $this->id) as $enfant) {
            if ($enfant->id === $cibleId || $enfant->estDescendantDe($cibleId, $tousLesDossiers)) {
                return true;
            }
        }

        return false;
    }

    // Fil d'Ariane pour le panneau "Détails du dossier" — même principe,
    // aucune requête supplémentaire.
    public function cheminComplet(Collection $tousLesDossiers): string
    {
        $segments = [$this->nom];
        $courant = $this;

        while ($courant->parent_id !== null) {
            $courant = $tousLesDossiers->firstWhere('id', $courant->parent_id);

            if ($courant === null) {
                break;
            }

            array_unshift($segments, $courant->nom);
        }

        return implode(' / ', $segments);
    }
}
