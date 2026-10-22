<?php

namespace App\Models;

use App\Services\SlaCalculatorService;
use App\Services\WorkflowService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Courrier extends Model
{
    use SoftDeletes;

    // Valeur par défaut EN MÉMOIRE, en plus du DEFAULT SQL de la migration —
    // un `Courrier::create()` sans "confidentialite" explicite laisse
    // l'attribut absent/`null` sur l'instance retournée tant qu'elle n'est
    // pas relue depuis la base (voir le même correctif déjà appliqué à
    // User, notes internes, "piège
    // trouvé en testant") — corrigé ici une fois pour toutes plutôt que de
    // continuer à l'expliciter dans chaque helper de test.
    protected $attributes = [
        'confidentialite' => 1,
        'confidentiel_direct' => false,
    ];

    // Module 5 (2026-09-23, voir DECISIONS.md "SLA et alertes") — date
    // limite recalculée à chaque changement d'une de ses sources, quel que
    // soit l'écran ou le service qui enregistre le courrier. Une nouvelle
    // date limite ré-arme les alertes (Module 7) : un délai prolongé doit
    // pouvoir à nouveau prévenir avant/après la nouvelle échéance.
    protected static function booted(): void
    {
        static::saving(function (Courrier $courrier) {
            if (! $courrier->exists || $courrier->isDirty(['echeance', 'sla_jours', 'type_document', 'date_mouvement'])) {
                $courrier->date_limite = SlaCalculatorService::calculerDateLimite(
                    $courrier->echeance,
                    $courrier->sla_jours,
                    $courrier->type_document,
                    $courrier->date_mouvement,
                );
            }
        });

        // Ré-armement par requête directe plutôt qu'en assignant null sur
        // l'instance : une instance chargée AVANT l'envoi d'une alerte (par
        // SendMailAlertJob, dans un autre processus) a déjà null en mémoire,
        // et Eloquent ne sauvegarderait alors pas ce "changement".
        static::saved(function (Courrier $courrier) {
            if ($courrier->wasChanged('date_limite')) {
                $reinitialisation = ['alerte_risque_le' => null, 'alerte_retard_le' => null, 'alerte_escalade_le' => null];
                static::query()->whereKey($courrier->getKey())->update($reinitialisation);
                $courrier->setRawAttributes(array_merge($courrier->getAttributes(), $reinitialisation), true);
            }
        });
    }

    // Module 5/7 — courrier encore actif dont l'échéance est dépassée.
    // 2026-09-24 (audit du tableau de bord, décision "fixed all as you see
    // fit" — voir DECISIONS.md "En retard : cohérence avec le chronomètre")
    // — reflète EXACTEMENT ce que chronoFin() affiche sur le badge
    // chronomètre, au lieu d'une comparaison à la journée près qui pouvait
    // contredire le badge du même courrier sur la même page :
    // - un délai fixé par le responsable (chrono_fin_le) est comparé à la
    //   MINUTE près, comme le chronomètre ;
    // - sans délai fixé, comportement STRICTEMENT INCHANGÉ : `whereDate(...,
    //   '<', today())` équivaut déjà à "la journée de date_limite est
    //   entièrement passée", exactement ce que chronoFin() calcule
    //   (date_limite->endOfDay()) pour ce cas — aucun effet sur les
    //   courriers qui n'utilisent pas le chronomètre.
    // Répercussion assumée : SendMailAlertJob (Module 7), qui réutilise ce
    // scope, peut désormais alerter dans l'heure suivant un délai fixé par
    // le responsable, plutôt qu'au plus tôt le lendemain — cohérent avec le
    // fait que ce délai a été choisi à l'heure près précisément pour ça.
    public function scopeEnRetard(Builder $query): Builder
    {
        return $query
            ->whereIn('statut', WorkflowService::statutsActifs())
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('chrono_fin_le')->where('chrono_fin_le', '<', now()))
                ->orWhere(fn ($q) => $q->whereNull('chrono_fin_le')->whereDate('date_limite', '<', today())));
    }

    // Module 10 (2026-10-09, carte tableau de bord "Courriers non
    // transférés") — courrier encore au tout premier statut du circuit,
    // jamais affecté/transféré depuis son enregistrement (voir
    // WorkflowService::TRANSITIONS['enregistre'], point d'entrée avant
    // toute transition).
    public function scopeNonTransfere(Builder $query): Builder
    {
        return $query->where('statut', 'enregistre');
    }

    // "confidentialite" est un ENTIER (1 à 5) depuis le 2026-09-21 —
    // demande explicite de l'utilisateur : "the niveau should be numbers
    // not confidential or what ever but numbers 1,2,3,4,5 etc". Comparé
    // directement à User::niveauConfidentialiteEffectif() dans
    // CourrierPolicy — plus de table de correspondance chaîne→entier
    // (voir la migration 2026_09_21_110000 pour l'historique : normale=1/
    // confidentiel=2/tres_confidentiel=3 avant cette date).
    protected $fillable = [
        'numero_reference',
        'numero_tampon_detecte',
        'reference_externe',
        'sens',
        'date_mouvement',
        'echeance',
        'date_limite',
        'chrono_debut_le',
        'chrono_fin_le',
        'chrono_arrete_le',
        'alerte_risque_le',
        'alerte_retard_le',
        'alerte_escalade_le',
        'sla_jours',
        'expediteur_nom',
        'expediteur_fonction',
        'expediteur_organisation',
        'expediteur_telephone',
        'expediteur_email',
        'expediteur_adresse',
        'expediteur_rc',
        'expediteur_niu',
        'destinataire',
        'note_interne',
        'dossier_reference',
        'objet',
        'type_document',
        'sous_type_sinistre',
        'mode_reception',
        'type_traitement',
        'service_id',
        'direction_origine_id',
        'service_responsable_id',
        'dossier_classement_id',
        'statut',
        'priorite',
        'confidentialite',
        'confidentiel_direct',
        'fichier_path',
        'texte_ocr',
        'ocr_statut',
        'ocr_confiance',
        'ocr_traite_le',
        'classement_statut',
        'type_document_propose',
        'service_propose_id',
        'destinataire_transfert_id',
        'classement_regle_id',
        'classement_propose_le',
        'classement_analyse_le',
    ];

    protected function casts(): array
    {
        return [
            'date_mouvement' => 'date',
            'echeance' => 'date',
            'date_limite' => 'date',
            'chrono_debut_le' => 'datetime',
            'chrono_fin_le' => 'datetime',
            'chrono_arrete_le' => 'datetime',
            'alerte_risque_le' => 'datetime',
            'alerte_retard_le' => 'datetime',
            'alerte_escalade_le' => 'datetime',
            'confidentialite' => 'integer',
            'confidentiel_direct' => 'boolean',
            'ocr_traite_le' => 'datetime',
            'classement_propose_le' => 'datetime',
            'classement_analyse_le' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    // Maquette "Modifier le courrier" (2026-09-18) — direction d'origine,
    // distincte de service_id (le service concerné/qui traite le courrier) :
    // net nouveau, jamais renseigné automatiquement, toujours nullable.
    public function directionOrigine(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'direction_origine_id');
    }

    // Maquette "Détail du courrier" (2026-09-18) — service ultimement
    // responsable/redevable du courrier, distinct de service_id (le
    // service concerné/qui le traite actuellement) : net nouveau,
    // toujours nullable.
    public function serviceResponsable(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_responsable_id');
    }

    // Module 3 — proposition du système, distincte de la valeur saisie/validée.
    public function servicePropose(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_propose_id');
    }

    // Module 3 — dossier de classement dans lequel ce courrier est rangé
    // (au plus un). Null = non classé, jamais restreint par le gate
    // d'accès par dossier (voir CourrierPolicy::accesDossierSuffisant()).
    public function dossierClassement(): BelongsTo
    {
        return $this->belongsTo(DossierClassement::class);
    }

    // Module 1/4 — la personne choisie par la réceptionniste dans la modale
    // "Transférer à" (voir DECISIONS.md "Destinataires de transfert") ;
    // null pour un courrier jamais transféré ou transféré avant ce
    // changement.
    public function destinataireTransfert(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_transfert_id');
    }

    public function regleClassement(): BelongsTo
    {
        return $this->belongsTo(RegleClassement::class, 'classement_regle_id');
    }

    // Module 3 — tags secondaires (source : regle | ocr | manuel).
    public function motsCles(): BelongsToMany
    {
        return $this->belongsToMany(MotCle::class, 'courrier_mot_cle')
            ->withPivot('source')
            ->orderBy('libelle');
    }

    // Module 5 — historique append-only, jamais reconstitué depuis updated_at.
    // Départage par id (2026-09-24, simulation du parcours réel) : création,
    // numérisation et proposition de classement sont écrites dans la même
    // seconde — sans ce second critère, leur ordre d'affichage était aléatoire.
    public function historiques(): HasMany
    {
        return $this->hasMany(CourrierHistorique::class)->latest('created_at')->latest('id');
    }

    // Module 6 — journal append-only des (ré)affectations, la plus récente en tête.
    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class)->latest('created_at');
    }

    // Affectation en cours (relation "ofMany" — une seule ligne, résolue par
    // une sous-requête corrélée) : utilisable dans whereHas()/with() sans les
    // pièges d'un ->first() sur une collection chargée en mémoire.
    public function affectationCourante(): HasOne
    {
        return $this->hasOne(Affectation::class)->latestOfMany();
    }

    // Pièces jointes (distinct de fichier_path, réservé au document principal
    // scanné du Module 2 — voir ARCHITECTURE.md).
    public function piecesJointes(): HasMany
    {
        return $this->hasMany(PieceJointe::class)->latest('created_at');
    }

    // Module 9 — historique des emprunts de l'original physique (jamais
    // supprimé, voir Decharge). La plus récente en tête, même patron que
    // affectations() ci-dessus.
    public function decharges(): HasMany
    {
        return $this->hasMany(Decharge::class)->latest('emprunte_le');
    }

    // Décharge en cours (pas encore rendue) — au plus une à la fois, un
    // original physique ne peut pas être emprunté deux fois simultanément
    // (voir WorkflowService::emettreDecharge()).
    public function dechargeActive(): HasOne
    {
        return $this->hasOne(Decharge::class)->whereNull('rendu_le')->latestOfMany('emprunte_le');
    }

    // Module 2/3 — texte OCR utilisable pour le classement/les mots-clés
    // uniquement s'il a été jugé lisible ("après un OCR réussi" — voir
    // DECISIONS.md Module 3). Un texte en echec_qualite est du bruit
    // potentiel : il reste affiché sur la fiche (transparence), mais ne doit
    // jamais alimenter une proposition de classement ni un tag automatique.
    public function texteOcrExploitable(): ?string
    {
        return $this->ocr_statut === 'reussi' ? $this->texte_ocr : null;
    }

    // Module 2 — l'extension d'origine est préservée telle quelle à
    // l'enregistrement (voir RegistrationForm::enregistrer()), jamais forcée
    // à ".pdf" : un scan JPG/PNG reste une image. Utilisé pour choisir le
    // bon aperçu (visionneuse PDF.js pour un PDF, affichage natif du
    // navigateur pour une image).
    public function estUnDocumentPdf(): bool
    {
        return $this->fichier_path !== null
            && Str::lower(pathinfo($this->fichier_path, PATHINFO_EXTENSION)) === 'pdf';
    }

    // Module 9 — utilisé par CourrierPolicy::view() : l'auteur de la création
    // n'est pas dupliqué en colonne sur `courriers`, l'historique en fait foi (Règle n°5).
    public function estCreeParUtilisateur(User $user): bool
    {
        return $this->historiques()
            ->where('action', 'creation')
            ->where('auteur_id', $user->id)
            ->exists();
    }

    // Module 3/9 (2026-09-24, voir DECISIONS.md "Dossier de classement : les
    // acteurs du circuit gardent l'accès") — personnes directement impliquées
    // dans CE courrier : son créateur, le collaborateur actuellement affecté,
    // le responsable de son service, et le destinataire de son transfert. Le
    // gate dossier (CourrierPolicy::accesDossierSuffisant(), scopeVisiblePar()
    // ci-dessous) ne s'applique pas à elles — sans quoi un collaborateur qui
    // range un courrier dans son dossier personnel le masquait à son propre
    // responsable. Ne DONNE aucun accès : les privilèges de portée de
    // CourrierPolicy::view() s'appliquent toujours ensuite.
    public function impliqueUtilisateur(User $user): bool
    {
        return $this->destinataire_transfert_id === $user->id
            || $this->service?->responsable_id === $user->id
            || $this->affectationCourante?->user_id === $user->id
            || $this->estCreeParUtilisateur($user);
    }

    // Module 5 — chronomètre de traitement (2026-09-24, voir DECISIONS.md
    // "Chronomètre de traitement") : délai fixé à la minute par le
    // responsable s'il y en a un, sinon la date limite SLA (fin de journée)
    // — tout courrier en circuit a donc un chronomètre, jamais inventé.
    public function chronoDebut(): ?CarbonInterface
    {
        return $this->chrono_debut_le ?? $this->date_mouvement?->copy()->startOfDay();
    }

    public function chronoFin(): ?CarbonInterface
    {
        return $this->chrono_fin_le ?? $this->date_limite?->copy()->endOfDay();
    }

    // 2026-09-24 — la DGA a validé le service de CE courrier (trace
    // immuable de l'historique, Règle n°5) : couvre aussi les courriers
    // transférés avant l'introduction de destinataire_transfert_id.
    public function estTransferePar(User $user): bool
    {
        return $this->destinataire_transfert_id === $user->id
            || $this->historiques()->where('action', 'service_valide_dga')->where('auteur_id', $user->id)->exists();
    }

    // Module 1/4 — segment "service" utilisé pour construire un chemin de
    // stockage (courriers/{annee}/{segment}/...) — voir RegistrationForm,
    // PieceJointeService, ScanForm, WorkflowService::validerService(). Un
    // courrier entrant en attente de validation DGA/ADJ n'a pas encore de
    // service (voir DECISIONS.md, synchronisation SRS-GEC.pdf) : ses fichiers
    // sont provisoirement rangés sous ce segment, puis déplacés vers le vrai
    // code service une fois `service_id` assigné par le DGA.
    public const SEGMENT_SERVICE_EN_ATTENTE = '_en_attente';

    // Libellé affiché de chaque statut — seul point de vérité (badge, filtre
    // "Tous les courriers", bordereau PDF). 'enregistre' est le 3e
    // sous-statut "Transféré" du SRS (voir WorkflowService, décision du
    // 2026-09-15 de ne pas ajouter de valeur d'enum) : affiché comme tel
    // plutôt que "Enregistre", qui laissait croire à un retour en arrière
    // après la validation DGA (demande de l'utilisateur, 2026-09-24).
    public const LIBELLES_STATUT = [
        'en_attente_de_transfert' => 'En attente de transfert',
        'en_cours_de_transfert' => 'En cours de transfert',
        'enregistre' => 'Transféré — à affecter',
        'affecte' => 'Affecté',
        'en_traitement' => 'En traitement',
        'en_validation' => 'En validation',
        'en_attente_information' => 'En attente d\'information',
        // 2026-10-09 — extension du circuit générique (Signature DG / Trésorerie).
        'en_attente_signature_dg' => 'En attente de signature DG',
        'en_attente_tresorerie' => 'En attente de traitement (trésorerie)',
        'traite' => 'Traité',
        'archive' => 'Archivé',
        'rejete' => 'Rejeté',
    ];

    public static function libelleStatut(?string $statut): string
    {
        return isset(self::LIBELLES_STATUT[$statut])
            ? __(self::LIBELLES_STATUT[$statut])
            : str_replace('_', ' ', (string) $statut);
    }

    public function segmentClassement(): string
    {
        return $this->service?->code ?? self::SEGMENT_SERVICE_EN_ATTENTE;
    }

    // Module Organisation v2 (2026-09-22, spec §18/§19) — QUATRIÈME gate
    // cumulatif, même principe opt-in que le gate dossier ci-dessus : un
    // utilisateur SANS périmètre assigné (organizationUnitsPerimetre vide)
    // n'est pas restreint par ce gate. Un courrier SANS service_id (entrant
    // en attente de validation DGA/ADJ, voir SEGMENT_SERVICE_EN_ATTENTE)
    // n'est jamais affecté par ce gate non plus — même principe que le gate
    // dossier : on ne restreint que ce qui porte réellement l'attribut
    // gaté, sinon validerService() (qui autorise justement sur le courrier
    // AVANT que service_id ne soit posé) serait cassée pour tout DGA ayant
    // un périmètre assigné. Sinon, l'utilisateur ne voit/n'agit que sur les
    // courriers dont le service (via le pont OrganizationUnit::service_id)
    // est sous l'une des entités accordées.
    public function estDansLePerimetreDe(User $user): bool
    {
        $perimetre = $user->organizationUnitsPerimetre;

        if ($perimetre->isEmpty() || $this->service_id === null) {
            return true;
        }

        return in_array($this->service_id, OrganizationUnit::idsServicesReelsSousArbre($perimetre), true);
    }

    // Module 8 — périmètre "que peut voir cet utilisateur", point de vérité
    // unique partagé par CourrierPolicy (par enregistrement),
    // CourrierList::portee() et DossierClassementList (au niveau requête) —
    // extrait de CourrierList::portee() le 2026-09-21 en ajoutant le
    // troisième gate (dossier de classement) pour ne plus tenir cette
    // logique synchronisée à la main à 3 endroits (déjà un risque réel
    // constaté lors de l'ajout de la confidentialité numérique le même
    // jour).
    //
    // 2026-09-23 — le bloc "périmètre par profil" est désormais basé sur les
    // PRIVILÈGES, miroir exact de CourrierPolicy::view() (voir DECISIONS.md
    // "Périmètre de visibilité unique") : auparavant il comparait le NOM du
    // profil, si bien qu'un utilisateur sans profil (ou d'un profil
    // personnalisé) n'était filtré par AUCUN bloc et voyait tous les
    // courriers dans les listes, alors que la Policy lui refusait chacun
    // individuellement. Aucun privilège de consultation => aucun résultat.
    // Ce scope est aussi, depuis ce même jour, le seul utilisé par
    // Dashboard, CourriersEnregistres et MesCourriers (WorkflowQueue, qui
    // l'utilisait aussi, a été supprimée le 2026-09-24).
    //
    // $avecTransfertsTraites (2026-09-24) : false pour une liste "à traiter"
    // (Dashboard::tachesDuJour()) — les courriers qu'une DGA a déjà
    // transférés restent consultables partout ailleurs, mais ne sont plus
    // "à traiter" pour elle.
    public function scopeVisiblePar(Builder $query, User $user, bool $avecTransfertsTraites = true): Builder
    {
        return $query
            ->where('confidentialite', '<=', $user->niveauConfidentialiteEffectif())
            ->unless($user->hasPrivilege('courriers.voir_tout'), fn ($q) => $q->where(function ($q) use ($user, $avecTransfertsTraites) {
                $q->whereRaw('1 = 0');

                if ($user->hasPrivilege('courriers.voir_service')) {
                    $q->orWhereHas('service', fn ($q) => $q->where('responsable_id', $user->id));
                }

                if ($user->hasPrivilege('courriers.voir_propre')) {
                    $q->orWhereHas('historiques', fn ($q) => $q->where('action', 'creation')->where('auteur_id', $user->id));
                }

                if ($user->hasPrivilege('courriers.voir_affecte')) {
                    $q->orWhereHas('affectationCourante', fn ($q) => $q->where('user_id', $user->id));
                }

                if ($user->hasPrivilege('courriers.voir_dga')) {
                    $q->orWhere(fn ($q) => $q->where('statut', 'en_cours_de_transfert')
                        ->where(fn ($q) => $q->whereNull('destinataire_transfert_id')->orWhere('destinataire_transfert_id', $user->id)));
                }

                // 2026-10-06 (entretien terrain, voir DECISIONS.md
                // "Délégation DGA/ADJ absents") : un délégataire actif voit
                // aussi les courriers en attente de validation adressés au(x)
                // DGA/ADJ qu'il couvre actuellement — indépendant du
                // privilège courriers.voir_dga (un RH délégataire ne l'a pas).
                $delegantsIds = $user->delegationsDgaActivesIds();

                if (! empty($delegantsIds)) {
                    $q->orWhere(fn ($q) => $q->where('statut', 'en_cours_de_transfert')
                        ->where(fn ($q) => $q->whereNull('destinataire_transfert_id')->orWhereIn('destinataire_transfert_id', $delegantsIds)));
                }

                // Pli confidentiel envoyé directement à cet utilisateur
                // (2026-09-24) — miroir de la branche équivalente de
                // CourrierPolicy::view().
                if ($user->hasPrivilege('courriers.voir_confidentiel_recu')) {
                    $q->orWhere(fn ($q) => $q->where('confidentiel_direct', true)->where('destinataire_transfert_id', $user->id));
                }

                // Courriers que CETTE DGA a transférés (2026-09-24) — miroir
                // de la branche voir_transferes de CourrierPolicy::view().
                if ($avecTransfertsTraites && $user->hasPrivilege('courriers.voir_transferes')) {
                    $q->orWhere('destinataire_transfert_id', $user->id)
                        ->orWhereHas('historiques', fn ($q) => $q->where('action', 'service_valide_dga')->where('auteur_id', $user->id));
                }
            }))
            // Module 3/9 — "les trois se cumulent" (DECISIONS.md 2026-09-16) :
            // gerer_tout court-circuite (même logique que
            // CourrierPolicy::accesDossierSuffisant()) ; sinon un courrier
            // CLASSÉ n'est visible que via ownership/partage du dossier ; un
            // courrier non classé n'est jamais affecté par ce bloc.
            // 2026-09-24 — sauf pour les acteurs du courrier lui-même, miroir
            // de impliqueUtilisateur() ci-dessus.
            ->when(! $user->hasPrivilege('dossiers_classement.gerer_tout'), fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('dossier_classement_id')
                ->orWhere('destinataire_transfert_id', $user->id)
                ->orWhereHas('service', fn ($q) => $q->where('responsable_id', $user->id))
                ->orWhereHas('affectationCourante', fn ($q) => $q->where('user_id', $user->id))
                ->orWhereHas('historiques', fn ($q) => $q->where('action', 'creation')->where('auteur_id', $user->id))
                ->orWhereHas('dossierClassement', fn ($q) => $q
                    ->where('cree_par_id', $user->id)
                    ->orWhere('responsable_id', $user->id)
                    ->orWhereHas('utilisateursAutorises', fn ($q) => $q->where('users.id', $user->id)))))
            // Module Organisation v2 — QUATRIÈME gate cumulatif, opt-in comme
            // le gate dossier ci-dessus (voir estDansLePerimetreDe()) : un
            // utilisateur sans périmètre assigné n'est pas restreint ici ; un
            // courrier sans service_id (en attente de validation DGA/ADJ)
            // n'est jamais affecté par ce gate non plus.
            ->when($user->organizationUnitsPerimetre->isNotEmpty(), fn ($q) => $q
                ->where(fn ($q) => $q
                    ->whereNull('service_id')
                    ->orWhereIn('service_id', OrganizationUnit::idsServicesReelsSousArbre($user->organizationUnitsPerimetre))));
    }
}
