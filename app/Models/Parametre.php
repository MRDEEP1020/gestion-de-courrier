<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

// Module 1/5/7 — paramètres système configurables depuis l'UI (2026-09-23,
// voir DECISIONS.md "Paramètres système configurables") : remplace
// config/gec.php (fichier, modifiable seulement en éditant le code et en
// redéployant) par une ligne singleton en base, éditable par un
// administrateur sur "Paramètres système".
//
// Ligne UNIQUE (id=1, créée par la migration) — jamais d'autre ligne créée.
// Lue très souvent (App\Services\NumeroReferenceGenerator à CHAQUE
// enregistrement, App\Services\SlaCalculatorService à CHAQUE sauvegarde
// d'un courrier via Courrier::booted()) : mise en cache Redis (Règle n°3),
// invalidée explicitement à chaque modification depuis l'UI plutôt que
// relue en base à chaque appel.
class Parametre extends Model
{
    public const CLE_CACHE = 'gec.parametres';

    protected $fillable = [
        'numero_reference_prefixe',
        'numero_reference_chiffres_sequence',
        'sla_jours_defaut',
        'sla_seuil_risque_jours',
        'sla_relance_jours',
        'sla_escalade_jours',
        'sla_par_type',
        // "Group A" (2026-09-24) — anciennes public const codées en dur,
        // voir DECISIONS.md "Paramètres système configurables — Groupe A/B".
        'niveau_confidentialite_max',
        'scan_resolution_minimale',
        'ocr_confiance_minimale',
        'ocr_longueur_minimale_texte',
        'dashboard_delai_moyen_periode_jours',
    ];

    protected function casts(): array
    {
        return [
            'numero_reference_chiffres_sequence' => 'integer',
            'sla_jours_defaut' => 'integer',
            'sla_seuil_risque_jours' => 'integer',
            'sla_relance_jours' => 'integer',
            'sla_escalade_jours' => 'integer',
            'sla_par_type' => 'array',
            'niveau_confidentialite_max' => 'integer',
            'scan_resolution_minimale' => 'integer',
            'ocr_confiance_minimale' => 'integer',
            'ocr_longueur_minimale_texte' => 'integer',
            'dashboard_delai_moyen_periode_jours' => 'integer',
        ];
    }

    // Ne met en cache QUE le tableau d'attributs bruts, jamais l'objet
    // Eloquent complet : mis en cache (driver 'database' ou Redis), un
    // modèle sérialisé/désérialisé directement revient parfois en
    // `__PHP_Incomplete_Class` (constaté en testant contre la vraie base) —
    // piège Laravel connu, la même raison pour laquelle les Jobs en file
    // d'attente utilisent SerializesModels (classe+id) plutôt qu'une
    // sérialisation brute. Un tableau de scalaires ne casse jamais.
    public static function actuel(): self
    {
        $attributs = Cache::rememberForever(self::CLE_CACHE, fn () => self::query()->findOrFail(1)->getAttributes());

        $instance = new self;
        $instance->setRawAttributes($attributs, true);
        $instance->exists = true;

        return $instance;
    }

    // Appelé après toute modification depuis l'UI — jamais implicite dans
    // un observer de modèle (même principe que RefreshDashboardStatsJob :
    // explicite au point d'appel, pas caché dans un événement).
    public static function invaliderCache(): void
    {
        Cache::forget(self::CLE_CACHE);
    }

    // Délai SLA pour un type de document donné — repli sur le délai par
    // défaut si le type n'a pas de délai spécifique (voir sla_par_type).
    public function delaiSlaPour(?string $typeDocument): int
    {
        if ($typeDocument !== null && array_key_exists($typeDocument, $this->sla_par_type ?? [])) {
            return (int) $this->sla_par_type[$typeDocument];
        }

        return $this->sla_jours_defaut;
    }
}
