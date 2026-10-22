<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Module 1/4 — délégation DGA/ADJ → RH quand les deux sont absents en même
// temps (2026-10-06, entretien terrain réceptionniste, voir DECISIONS.md
// "Délégation DGA/ADJ absents"). Nominative (delegant → delegataire) plutôt
// qu'un simple interrupteur global : CourrierPolicy::validerService() doit
// pouvoir faire correspondre un courrier déjà adressé à un DGA précis
// (`destinataire_transfert_id`) à son délégataire actif. Désactivée en
// place (Règle n°5, même esprit que Decharge) : jamais supprimée.
class DelegationDga extends Model
{
    protected $table = 'delegations_dga';

    protected $fillable = [
        'delegant_id',
        'delegataire_id',
        'motif',
        'debut_le',
        'fin_le',
        'actif',
        'active_par_id',
        'desactive_par_id',
        'desactive_le',
    ];

    protected function casts(): array
    {
        return [
            'debut_le' => 'datetime',
            'fin_le' => 'datetime',
            'desactive_le' => 'datetime',
            'actif' => 'boolean',
        ];
    }

    public function delegant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegant_id');
    }

    public function delegataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegataire_id');
    }

    public function activePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'active_par_id');
    }

    public function desactivePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desactive_par_id');
    }

    public function scopeActives(Builder $query): Builder
    {
        return $query->where('actif', true)
            ->where('debut_le', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('fin_le')->orWhere('fin_le', '>=', now()));
    }

    // Utilisé par User::delegationsDgaActivesIds() (CourrierPolicy::
    // validerService(), Courrier::scopeVisiblePar()) — les DGA/ADJ dont CE
    // délégataire couvre actuellement l'absence.
    public static function delegantsIdsPour(int $delegataireId): array
    {
        return static::query()->actives()
            ->where('delegataire_id', $delegataireId)
            ->pluck('delegant_id')
            ->all();
    }
}
