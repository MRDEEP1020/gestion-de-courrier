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
        'notifications_defaut',
        // "Group A" (2026-09-24) — anciennes public const codées en dur,
        // voir DECISIONS.md "Paramètres système configurables — Groupe A/B".
        'niveau_confidentialite_max',
        'scan_resolution_minimale',
        'ocr_confiance_minimale',
        'ocr_longueur_minimale_texte',
        'dashboard_delai_moyen_periode_jours',
        // Modèle de décharge (2026-10-28) — voir modeleDecharge().
        'decharge_modele',
        // Format du numéro de décharge (2026-10-28) — voir TransfertService::genererNumeroDecharge().
        'decharge_numero_prefixe',
        'decharge_numero_chiffres',
        // Durée de conservation par défaut des archives (2026-10-30, Module 9).
        'conservation_annees_defaut',
        // Validité d'un mot de passe en jours, 0 = jamais (2026-11-11).
        'mot_de_passe_expiration_jours',
    ];

    // Repères remplacés à l'impression d'une décharge (voir
    // resources/views/pdf/decharge-remise.blade.php) — affichés comme aide
    // dans Paramètres système.
    public const REPERES_DECHARGE = [
        '{recu_par}' => 'personne qui a accepté le courrier pour le service',
        '{titre_chef}' => 'son titre (Responsable, Chef de département, Directeur…)',
        '{service}' => 'service destinataire',
        '{remis_par}' => 'agent qui remet l\'original',
        '{date_acceptation}' => 'date et heure de l\'acceptation dans GEC',
        '{reference}' => 'numéro de référence du courrier',
    ];

    protected function casts(): array
    {
        return [
            'numero_reference_chiffres_sequence' => 'integer',
            'conservation_annees_defaut' => 'integer',
            'mot_de_passe_expiration_jours' => 'integer',
            'sla_jours_defaut' => 'integer',
            'sla_seuil_risque_jours' => 'integer',
            'sla_relance_jours' => 'integer',
            'sla_escalade_jours' => 'integer',
            'sla_par_type' => 'array',
            'notifications_defaut' => 'array',
            'niveau_confidentialite_max' => 'integer',
            'scan_resolution_minimale' => 'integer',
            'ocr_confiance_minimale' => 'integer',
            'ocr_longueur_minimale_texte' => 'integer',
            'dashboard_delai_moyen_periode_jours' => 'integer',
            'decharge_modele' => 'array',
            'decharge_numero_chiffres' => 'integer',
        ];
    }

    // Numéro de décharge au format choisi (préfixe-année-séquence) — même
    // présentation que le numéro de référence des courriers.
    public function formaterNumeroDecharge(int $sequence, ?int $annee = null): string
    {
        $prefixe = $this->decharge_numero_prefixe ?: 'ACC';
        $chiffres = max(1, min(10, (int) ($this->decharge_numero_chiffres ?: 6)));

        return sprintf('%s-%d-%0'.$chiffres.'d', $prefixe, $annee ?? (int) now()->year, $sequence);
    }

    // Textes de la décharge de remise de l'original (2026-10-28, « je veux
    // qu'elle soit modifiable à partir de l'interface ») — modifiés par un
    // administrateur dans Paramètres système ; un champ jamais enregistré
    // garde le texte par défaut. Un champ facultatif vidé masque sa partie.
    public static function modeleDechargeParDefaut(): array
    {
        return [
            'societe' => "NSIA Assurances — Service Courrier\nGestion Électronique du Courrier (GEC)",
            'ville' => (string) config('app.ville'),
            'titre' => 'DÉCHARGE',
            'sous_titre' => 'REMISE DE L\'ORIGINAL D\'UN COURRIER',
            'formule' => 'Je soussigné(e), {recu_par}, {titre_chef} — {service}, reconnais avoir reçu de {remis_par} l\'original papier du courrier désigné ci-dessous, dont la réception par mon service a été acceptée dans GEC le {date_acceptation}.',
            'cadre_remis' => 'Remis par — Service Courrier',
            'cadre_recu' => 'Reçu par — {service}',
            'observations' => 'Observations / réserves éventuelles',
            'mention' => 'En foi de quoi, la présente décharge est délivrée pour servir et valoir ce que de droit.',
            'pied' => 'Toute remise est également tracée dans l\'historique du courrier.',
        ];
    }

    // Règles communes à l'enregistrement (ParametreSysteme) et à l'aperçu
    // PDF (ApercuModeleDechargeController), par champ du modèle.
    public static function reglesModeleDecharge(): array
    {
        return [
            'societe' => ['nullable', 'string', 'max:300'],
            'ville' => ['required', 'string', 'max:60'],
            'titre' => ['required', 'string', 'max:60'],
            'sous_titre' => ['nullable', 'string', 'max:120'],
            'formule' => ['required', 'string', 'max:1500'],
            'cadre_remis' => ['required', 'string', 'max:80'],
            'cadre_recu' => ['required', 'string', 'max:80'],
            'observations' => ['nullable', 'string', 'max:120'],
            'mention' => ['nullable', 'string', 'max:500'],
            'pied' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function modeleDecharge(): array
    {
        $parDefaut = self::modeleDechargeParDefaut();
        $enregistre = array_intersect_key($this->decharge_modele ?? [], $parDefaut);

        return array_map(fn ($valeur) => (string) $valeur, array_merge($parDefaut, $enregistre));
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
