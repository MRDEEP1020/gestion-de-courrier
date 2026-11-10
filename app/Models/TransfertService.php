<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service et
// acceptation") — un transfert d'un courrier vers un service par l'accueil.
// Le service l'accepte (numéro de décharge, preuve avant la remise de
// l'original papier) ou le refuse avec un motif ; l'accueil marque ensuite
// l'original comme remis. Jamais une transition de circuit à lui seul : le
// statut du courrier est piloté par WorkflowService, cette ligne n'en
// garde que le détail.
class TransfertService extends Model
{
    public const EN_ATTENTE = 'en_attente';

    public const ACCEPTE = 'accepte';

    public const REFUSE = 'refuse';

    protected $table = 'transferts_service';

    // Même piège que Courrier/User (voir leurs commentaires) : create() ne
    // relit pas le DEFAULT SQL sur l'instance en mémoire.
    protected $attributes = [
        'statut' => self::EN_ATTENTE,
    ];

    protected $fillable = [
        'courrier_id',
        'service_id',
        'transfere_par_id',
        'statut',
        'decide_par_id',
        'decide_le',
        'motif_refus',
        'numero_decharge',
        'original_remis_par_id',
        'original_remis_le',
        // Signature électronique de la réception de l'original (2026-10-28).
        'reception_signee_par_id',
        'reception_signee_le',
    ];

    protected function casts(): array
    {
        return [
            'decide_le' => 'datetime',
            'original_remis_le' => 'datetime',
            'reception_signee_le' => 'datetime',
        ];
    }

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function transferePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transfere_par_id');
    }

    public function decidePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par_id');
    }

    public function originalRemisPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_remis_par_id');
    }

    public function receptionSigneePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reception_signee_par_id');
    }

    public function estAccepte(): bool
    {
        return $this->statut === self::ACCEPTE;
    }

    public function estRefuse(): bool
    {
        return $this->statut === self::REFUSE;
    }

    // Numéro de décharge dérivé de l'id (unique par construction), attribué
    // seulement à l'acceptation — un transfert refusé n'en a jamais. Même
    // patron que Decharge::booted() pour les emprunts d'originaux archivés.
    // 2026-10-28 — préfixe et nombre de chiffres réglables dans Paramètres
    // système (Parametre::formaterNumeroDecharge()) ; la séquence reste l'id du
    // transfert, donc unique quel que soit le format choisi.
    public function genererNumeroDecharge(): string
    {
        return Parametre::actuel()->formaterNumeroDecharge($this->id);
    }
}
