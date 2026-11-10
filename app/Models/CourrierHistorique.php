<?php

namespace App\Models;

use App\Concerns\EcritureSeule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourrierHistorique extends Model
{
    // Append-only (Règle n°5) : pas d'updated_at, et toute modification ou
    // suppression via le modèle lève une exception (EcritureSeule, 2026-10-30).
    use EcritureSeule;

    const UPDATED_AT = null;

    protected $fillable = ['courrier_id', 'auteur_id', 'action', 'commentaire'];

    public function courrier(): BelongsTo
    {
        return $this->belongsTo(Courrier::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    // Affichage de l'onglet "Historique" (2026-10-08, retour utilisateur —
    // "improve the design of this historique") : libellé FR lisible +
    // icône/couleur par action, à la place de l'ancien
    // str_replace('_', ' ', $action) brut (ex. "service valide dga") et du
    // même pictogramme horloge répété pour toute entrée. Liste close des
    // ~30 valeurs d'action réellement créées à travers le projet
    // (WorkflowService, RegistrationForm, ShowCourrier,
    // DossierClassementService, IndexCourrierJob, PieceJointeService,
    // ProcessDocumentOcr, SendMailAlertJob, CourrierList) — jamais
    // générée dynamiquement, donc une liste fermée reste exhaustive et sûre
    // à maintenir ; une valeur future non encore répertoriée retombe sur un
    // repli neutre plutôt que de planter. Couleurs limitées à la palette de
    // marque existante (voir app.css --color-brand-*), jamais une teinte
    // Tailwind nommée arbitraire.
    private const AFFICHAGE = [
        'creation' => ['libelle' => 'Courrier enregistré', 'icone' => 'document-plus', 'couleur' => 'blue'],
        'modification' => ['libelle' => 'Informations modifiées', 'icone' => 'pencil-square', 'couleur' => 'neutre'],
        'numerisation' => ['libelle' => 'Document numérisé', 'icone' => 'camera', 'couleur' => 'blue'],
        'transfert' => ['libelle' => 'Transféré au service', 'icone' => 'paper-airplane', 'couleur' => 'blue'],
        // 2026-10-23 — acceptation/refus par le service destinataire et remise
        // de l'original papier (voir DECISIONS.md "Transfert direct au service
        // et acceptation"). 'service_valide_dga' reste affiché pour les
        // courriers validés par la DGA avant la fin de ce routage.
        'transfert_accepte' => ['libelle' => 'Accepté par le service', 'icone' => 'check-circle', 'couleur' => 'success'],
        'transfert_refuse' => ['libelle' => 'Refusé par le service', 'icone' => 'x-circle', 'couleur' => 'warning'],
        'original_remis' => ['libelle' => 'Original papier remis', 'icone' => 'document-check', 'couleur' => 'success'],
        'reception_original_signee' => ['libelle' => 'Réception de l\'original signée', 'icone' => 'pencil-square', 'couleur' => 'success'],
        // 2026-10-23 — transmission par le responsable d'une entité (ex. une
        // Direction) à l'une de ses unités.
        'transmission' => ['libelle' => 'Transmis à une unité', 'icone' => 'arrow-down-right', 'couleur' => 'blue'],
        'service_valide_dga' => ['libelle' => 'Service validé par la DG', 'icone' => 'check-circle', 'couleur' => 'blue'],
        'confidentialite_modifiee_dga' => ['libelle' => 'Confidentialité modifiée', 'icone' => 'lock-closed', 'couleur' => 'navy'],
        'confidentiel_remis' => ['libelle' => 'Pli confidentiel remis', 'icone' => 'lock-open', 'couleur' => 'navy'],
        'affectation' => ['libelle' => 'Affecté', 'icone' => 'user-plus', 'couleur' => 'blue'],
        'reaffectation' => ['libelle' => 'Réaffecté', 'icone' => 'arrow-path', 'couleur' => 'blue'],
        'traitement_demarre' => ['libelle' => 'Traitement démarré', 'icone' => 'play', 'couleur' => 'blue'],
        'soumis_validation' => ['libelle' => 'Soumis pour validation', 'icone' => 'paper-airplane', 'couleur' => 'blue'],
        'validation_acceptee' => ['libelle' => 'Validé', 'icone' => 'check-badge', 'couleur' => 'success'],
        'validation_refusee' => ['libelle' => 'Renvoyé pour correction', 'icone' => 'arrow-uturn-left', 'couleur' => 'warning'],
        'mise_en_attente' => ['libelle' => 'Mis en attente d\'information', 'icone' => 'pause', 'couleur' => 'warning'],
        'reprise' => ['libelle' => 'Traitement repris', 'icone' => 'play-circle', 'couleur' => 'blue'],
        'rejet' => ['libelle' => 'Rejeté', 'icone' => 'x-circle', 'couleur' => 'danger'],
        'archivage_automatique' => ['libelle' => 'Archivé automatiquement', 'icone' => 'archive-box', 'couleur' => 'neutre'],
        'decharge_emise' => ['libelle' => 'Décharge émise (original emprunté)', 'icone' => 'arrow-up-tray', 'couleur' => 'neutre'],
        'decharge_rendue' => ['libelle' => 'Original rendu', 'icone' => 'arrow-down-tray', 'couleur' => 'success'],
        'classement_propose' => ['libelle' => 'Classement proposé automatiquement', 'icone' => 'sparkles', 'couleur' => 'blue'],
        'classement_valide' => ['libelle' => 'Classement validé', 'icone' => 'check', 'couleur' => 'success'],
        'classement_ignore' => ['libelle' => 'Classement ignoré', 'icone' => 'x-mark', 'couleur' => 'neutre'],
        'classement_retire' => ['libelle' => 'Retiré du classement automatique', 'icone' => 'folder-minus', 'couleur' => 'neutre'],
        'classement_dossier' => ['libelle' => 'Classé dans un dossier', 'icone' => 'folder', 'couleur' => 'blue'],
        'declassement_dossier' => ['libelle' => 'Retiré d\'un dossier', 'icone' => 'folder-minus', 'couleur' => 'neutre'],
        'courrier_ajoute' => ['libelle' => 'Ajouté à un dossier', 'icone' => 'folder-plus', 'couleur' => 'blue'],
        'courrier_retire' => ['libelle' => 'Retiré d\'un dossier', 'icone' => 'folder-minus', 'couleur' => 'neutre'],
        'ajout_piece_jointe' => ['libelle' => 'Pièce jointe ajoutée', 'icone' => 'paper-clip', 'couleur' => 'blue'],
        'mot_cle_ajoute' => ['libelle' => 'Mot-clé ajouté', 'icone' => 'tag', 'couleur' => 'blue'],
        'mot_cle_retire' => ['libelle' => 'Mot-clé retiré', 'icone' => 'tag', 'couleur' => 'neutre'],
        'suppression' => ['libelle' => 'Supprimé', 'icone' => 'trash', 'couleur' => 'danger'],
        'ocr_echec' => ['libelle' => 'Échec de l\'extraction du texte', 'icone' => 'exclamation-triangle', 'couleur' => 'danger'],
        'alerte_escalade' => ['libelle' => 'Alerte de retard envoyée', 'icone' => 'bell-alert', 'couleur' => 'danger'],
        // 2026-10-30 (Module 7, relances) — relance manuelle, et libellés des
        // alertes automatiques de SendMailAlertJob (affichées jusque-là brutes).
        'relance' => ['libelle' => 'Relance envoyée', 'icone' => 'bell-alert', 'couleur' => 'warning'],
        // 2026-10-30 — conformité de l'archivage (Module 9).
        'conservation_prolongee' => ['libelle' => 'Conservation prolongée', 'icone' => 'clock', 'couleur' => 'neutre'],
        'gel_juridique' => ['libelle' => 'Placé sous gel juridique', 'icone' => 'lock-closed', 'couleur' => 'warning'],
        'gel_juridique_leve' => ['libelle' => 'Gel juridique levé', 'icone' => 'lock-open', 'couleur' => 'neutre'],
        'destruction' => ['libelle' => 'Archive détruite (échéance de conservation)', 'icone' => 'trash', 'couleur' => 'danger'],
        'integrite_alerte' => ['libelle' => 'Alerte d\'intégrité du fichier', 'icone' => 'shield-exclamation', 'couleur' => 'danger'],
        'integrite_retablie' => ['libelle' => 'Intégrité du fichier confirmée', 'icone' => 'shield-check', 'couleur' => 'success'],
        'alerte_risque' => ['libelle' => 'Alerte automatique — bientôt en retard', 'icone' => 'bell', 'couleur' => 'warning'],
        'alerte_retard' => ['libelle' => 'Relance automatique — en retard', 'icone' => 'bell-alert', 'couleur' => 'danger'],
        // 2026-10-09 — extension du circuit générique (Signature DG / Trésorerie).
        'envoye_signature_dg' => ['libelle' => 'Envoyé en signature DG', 'icone' => 'paper-airplane', 'couleur' => 'navy'],
        'signe_dg_cloture' => ['libelle' => 'Signé par la DG — clôturé', 'icone' => 'identification', 'couleur' => 'success'],
        'signe_dg_tresorerie' => ['libelle' => 'Signé par la DG — transmis à la trésorerie', 'icone' => 'identification', 'couleur' => 'navy'],
        'traite_tresorerie' => ['libelle' => 'Traité par la trésorerie', 'icone' => 'banknotes', 'couleur' => 'success'],
    ];

    public function libelle(): string
    {
        return self::AFFICHAGE[$this->action]['libelle'] ?? str_replace('_', ' ', $this->action);
    }

    public function icone(): string
    {
        return self::iconePourAction($this->action);
    }

    // Classes de l'icône dans son badge circulaire — une seule méthode
    // appelée depuis la vue plutôt que d'y dupliquer un match() sur la
    // couleur à chaque usage.
    public function classesIcone(): string
    {
        return self::classesIconePourCouleur(self::couleurPourAction($this->action));
    }

    // 2026-10-09 — exposés en static (notifications cross-utilisateur,
    // Module 7 étendu) : mêmes icône/couleur que la frise Historique pour
    // la même action, une seule source de vérité (AFFICHAGE ci-dessus),
    // jamais dupliquée dans App\Notifications\CourrierNotification.
    public static function iconePourAction(string $action): string
    {
        return self::AFFICHAGE[$action]['icone'] ?? 'clock';
    }

    public static function couleurPourAction(string $action): string
    {
        return self::AFFICHAGE[$action]['couleur'] ?? 'neutre';
    }

    public static function classesIconePourCouleur(string $couleur): string
    {
        return match ($couleur) {
            'blue' => 'bg-brand-blue-pale text-brand-blue',
            'success' => 'bg-brand-success-light text-brand-success-dark',
            'warning' => 'bg-brand-warning-light text-brand-warning-dark',
            'danger' => 'bg-brand-danger-light text-brand-danger-dark',
            'navy' => 'bg-brand-navy/10 text-brand-navy',
            default => 'bg-brand-disabled-bg text-brand-text-secondary',
        };
    }
}
