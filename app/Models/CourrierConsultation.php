<?php

namespace App\Models;

use App\Concerns\EcritureSeule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Throwable;

// Module 9 — journal des consultations (2026-10-30, conformité de
// l'archivage, voir DECISIONS.md) : qui a ouvert la fiche, prévisualisé,
// téléchargé ou imprimé un document, et quand. Écriture seule (EcritureSeule).
class CourrierConsultation extends Model
{
    use EcritureSeule;

    const UPDATED_AT = null;

    // 2026-11-09 — plus aucun téléchargement possible (aperçu sécurisé) :
    // les trois libellés « téléchargé(e) » restent pour les lignes déjà
    // inscrites, « impression » ne concerne que les documents générés
    // (bordereau, accusé de réception).
    public const ACTIONS = [
        'fiche' => 'Fiche consultée',
        'apercu' => 'Document consulté',
        'apercu_piece_jointe' => 'Pièce jointe consultée',
        'telechargement' => 'Document téléchargé',
        'telechargement_pdfa' => 'Copie d\'archive (PDF/A) téléchargée',
        'piece_jointe' => 'Pièce jointe téléchargée',
        'impression' => 'Bordereau ou accusé imprimé',
    ];

    public const SECONDES_REGROUPEMENT = 60;

    protected $fillable = ['courrier_id', 'user_id', 'action', 'piece_jointe_id', 'ip', 'navigateur'];

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelle(): string
    {
        return __(self::ACTIONS[$this->action] ?? $this->action);
    }

    // Une simple insertion (jamais lourde, Règle n°1) ; un échec du journal
    // ne doit jamais empêcher la consultation elle-même, mais reste tracé.
    // Une même consultation (personne, courrier, action, pièce) n'est
    // inscrite qu'une fois par minute : la visionneuse PDF interroge l'aperçu
    // plusieurs fois, en parallèle, pour un seul affichage (doublons à la
    // même seconde constatés sur la vraie base le 2026-11-06). Verrou
    // atomique du cache plutôt qu'une lecture de la table, que deux requêtes
    // simultanées verraient vide toutes les deux.
    public static function enregistrer(int $courrierId, string $action, ?int $pieceJointeId = null): void
    {
        try {
            $cle = 'gec.consultation.'.implode('.', [Auth::id() ?? 0, $courrierId, $action, $pieceJointeId ?? 0]);

            if (! Cache::add($cle, true, self::SECONDES_REGROUPEMENT)) {
                return;
            }

            self::create([
                'courrier_id' => $courrierId,
                'user_id' => Auth::id(),
                'action' => $action,
                'piece_jointe_id' => $pieceJointeId,
                'ip' => Request::ip(),
                'navigateur' => mb_substr((string) Request::userAgent(), 0, 255),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
