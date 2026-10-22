<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

// Module "Organisation" v2 (2026-09-22, spec technique complète fournie par
// l'utilisateur) — hiérarchie dynamique Company → Site → Department →
// Service → Sub-service (Service est OPTIONNEL, spec §1). Même patron
// auto-référencé que DossierClassement (premier modèle de ce genre du
// projet) : parent()/enfants(), compterXDescendants()/estDescendantDe()/
// cheminComplet() opèrent sur une Collection déjà chargée, jamais de requête
// récursive.
//
// `service()` est un PONT vers la table `services` EXISTANTE (voir la
// migration) — pas remplacée, seulement reliée : un nœud type=service (ou
// department) peut être ponté à l'un des 14 services réels pour que la
// cascade de transfert de courrier (Phase 3) puisse résoudre un vrai
// `courriers.service_id` sans toucher au reste de la chaîne existante.
class OrganizationUnit extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_COMPANY = 'company';

    public const TYPE_SITE = 'site';

    // 2026-10-09 — retour utilisateur direct sur un vrai organigramme
    // (NSIA Assurances Cameroun) : "direction is not a department it is a
    // direction and a direction has a department under it". Le modèle à 5
    // niveaux ne distinguait pas "Direction" de "Département" (les deux
    // tombaient sur TYPE_DEPARTMENT) — une vraie Direction (ex. "Direction
    // Corporate") NE PEUT PAS avoir un Département comme enfant tant que
    // les deux partagent le même rang dans ORDRE_TYPES (la validation de
    // enregistrerNoeud() exige un rang STRICTEMENT croissant). Ajouté comme
    // 6ème niveau, entre Site et Département — jamais ponté à un service
    // réel (voir ServiceReelSynchroniseur::TYPES_SYNCHRONISES, inchangé),
    // purement structurel comme Company/Site.
    public const TYPE_DIRECTION = 'direction';

    public const TYPE_DEPARTMENT = 'department';

    public const TYPE_SERVICE = 'service';

    public const TYPE_SUB_SERVICE = 'sub_service';

    // Ordre de la hiérarchie — utilisé pour proposer le type par défaut d'un
    // enfant selon le type du parent (spec §7/§8 : "le type doit être
    // automatiquement proposé selon le parent").
    public const ORDRE_TYPES = [
        self::TYPE_COMPANY,
        self::TYPE_SITE,
        self::TYPE_DIRECTION,
        self::TYPE_DEPARTMENT,
        self::TYPE_SERVICE,
        self::TYPE_SUB_SERVICE,
    ];

    public const STATUT_ACTIF = 'active';

    public const STATUT_INACTIF = 'inactive';

    // Même piège que Courrier/User/DossierClassement/Service (voir leurs
    // commentaires respectifs) : Model::create() ne relit pas le DEFAULT SQL
    // sur l'instance en mémoire.
    protected $attributes = [
        'status' => self::STATUT_ACTIF,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'parent_id', 'service_id', 'name', 'code', 'type', 'description',
        'responsible_user_id', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // Pont vers le service réel existant (voir commentaire de classe).
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    // Rattachement de TRAVAIL (spec §5) — distinct du périmètre de
    // visibilité ci-dessous (spec §19).
    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_unit_user')
            ->withPivot(['is_primary', 'role_in_unit'])
            ->withTimestamps();
    }

    // Périmètre de VISIBILITÉ pour les courriers (spec §19) — un utilisateur
    // peut travailler dans une Sous-service mais avoir un périmètre élargi à
    // toute l'Agence. Distinct de utilisateurs() ci-dessus.
    public function utilisateursPerimetre(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_unit_perimetre_user');
    }

    public function estActif(): bool
    {
        return $this->status === self::STATUT_ACTIF;
    }

    // Même principe que DossierClassement::compterDocumentsDescendants() :
    // parcourt une Collection déjà chargée (voir
    // OrganisationIndex::tousLesNoeuds(), ->withCount('utilisateurs')),
    // jamais de requête récursive.
    public function compterUtilisateursDescendants(Collection $tousLesNoeuds): int
    {
        $total = $this->utilisateurs_count ?? 0;

        foreach ($tousLesNoeuds->where('parent_id', $this->id) as $enfant) {
            $total += $enfant->compterUtilisateursDescendants($tousLesNoeuds);
        }

        return $total;
    }

    // Compte via le pont service_id (courriers_count chargé sur le Service
    // ponté, voir OrganisationIndex::tousLesNoeuds() ->with('service' =>
    // withCount('courriers'))) — un nœud sans pont contribue 0 directement,
    // mais ses descendants comptent quand même.
    public function compterCourriersDescendants(Collection $tousLesNoeuds): int
    {
        $total = $this->service?->courriers_count ?? 0;

        foreach ($tousLesNoeuds->where('parent_id', $this->id) as $enfant) {
            $total += $enfant->compterCourriersDescendants($tousLesNoeuds);
        }

        return $total;
    }

    // Anti-cycle pour "Déplacer" — un nœud ne doit jamais pouvoir devenir son
    // propre descendant (même principe que DossierClassement::estDescendantDe()).
    public function estDescendantDe(int $cibleId, Collection $tousLesNoeuds): bool
    {
        foreach ($tousLesNoeuds->where('parent_id', $this->id) as $enfant) {
            if ($enfant->id === $cibleId || $enfant->estDescendantDe($cibleId, $tousLesNoeuds)) {
                return true;
            }
        }

        return false;
    }

    // Fil d'Ariane — pour le panneau de détails ET pour la recherche globale
    // (spec §12 : "afficher le chemin hiérarchique du résultat").
    public function cheminComplet(Collection $tousLesNoeuds): string
    {
        $segments = [$this->name];
        $courant = $this;

        while ($courant->parent_id !== null) {
            $courant = $tousLesNoeuds->firstWhere('id', $courant->parent_id);

            if ($courant === null) {
                break;
            }

            array_unshift($segments, $courant->name);
        }

        return implode(' / ', $segments);
    }

    // Type par défaut du PROCHAIN niveau sous ce nœud — spec §7/§8 : "le
    // type doit être automatiquement proposé selon le parent". Si ce nœud
    // est déjà au dernier niveau (sub_service), pas de niveau suivant.
    public function typeEnfantPropose(): ?string
    {
        $index = array_search($this->type, self::ORDRE_TYPES, true);

        return $index !== false ? (self::ORDRE_TYPES[$index + 1] ?? null) : null;
    }

    // Périmètre de visibilité (spec §19/§18 "jamais seulement le frontend") —
    // aplatit tous les service_id pontés du sous-arbre de CHAQUE nœud
    // accordé, pour un usage dans un whereIn('service_id', ...) en une seule
    // requête (voir Courrier::estDansLePerimetreDe()).
    public static function idsServicesReelsSousArbre(Collection $noeudsAccordes): array
    {
        $tousLesNoeuds = self::query()->get(['id', 'parent_id', 'service_id']);

        $collecter = function (int $id) use (&$collecter, $tousLesNoeuds): array {
            $noeud = $tousLesNoeuds->firstWhere('id', $id);

            if ($noeud === null) {
                return [];
            }

            $ids = $noeud->service_id ? [$noeud->service_id] : [];

            foreach ($tousLesNoeuds->where('parent_id', $id) as $enfant) {
                $ids = array_merge($ids, $collecter($enfant->id));
            }

            return $ids;
        };

        return $noeudsAccordes->flatMap(fn (self $noeud) => $collecter($noeud->id))->unique()->values()->all();
    }

    // Même principe qu'idsServicesReelsSousArbre() ci-dessus, mais aplatit
    // les IDS DES NŒUDS eux-mêmes (pas leur service ponté) — utilisé par le
    // filtre "Département" d'UserList pour matcher un rattachement de
    // travail direct (organization_unit_user) n'importe où dans le
    // sous-arbre, pas seulement un pont service_id.
    public static function idsSousArbre(Collection $noeudsAccordes): array
    {
        $tousLesNoeuds = self::query()->get(['id', 'parent_id']);

        $collecter = function (int $id) use (&$collecter, $tousLesNoeuds): array {
            $ids = [$id];

            foreach ($tousLesNoeuds->where('parent_id', $id) as $enfant) {
                $ids = array_merge($ids, $collecter($enfant->id));
            }

            return $ids;
        };

        return $noeudsAccordes->flatMap(fn (self $noeud) => $collecter($noeud->id))->unique()->values()->all();
    }

    // Page "Utilisateurs & Accès" (2026-09-23, demande explicite de
    // l'utilisateur : "on the table... it should be now department...
    // since we assign them in a department first before service") — un
    // utilisateur reste rattaché à un `services.id` RÉEL (users.service_id,
    // colonne inchangée, toujours utilisée par tout le reste de
    // l'application — affectation, scopeVisiblePar, etc.), mais l'AFFICHAGE
    // remonte désormais jusqu'au Département ancêtre (ou lui-même s'il est
    // déjà Département) dans l'organigramme, plutôt que le nom brut du
    // service. Remonte les ancêtres sur une Collection déjà chargée (même
    // principe que cheminComplet()), jamais de requête récursive.
    public function departementOuAncetreDepartement(Collection $tousLesNoeuds): ?self
    {
        $courant = $this;

        while ($courant !== null) {
            if ($courant->type === self::TYPE_DEPARTMENT) {
                return $courant;
            }

            $courant = $courant->parent_id !== null ? $tousLesNoeuds->firstWhere('id', $courant->parent_id) : null;
        }

        return null;
    }

    // Carte service_id → nom du Département ancêtre, pour afficher la
    // colonne "Département" de la table utilisateurs sans requête par ligne
    // (Règle n°3). Un service réel non encore rattaché à l'organigramme
    // (la plupart, à ce jour — seuls Sinistres/Informatique existent) n'a
    // simplement pas d'entrée ici, affiché "—" côté vue plutôt qu'une
    // valeur fabriquée.
    public static function departementLabelParServiceId(Collection $tousLesNoeuds): array
    {
        $carte = [];

        foreach ($tousLesNoeuds as $noeud) {
            if ($noeud->service_id === null) {
                continue;
            }

            $departement = $noeud->departementOuAncetreDepartement($tousLesNoeuds);

            if ($departement !== null) {
                $carte[$noeud->service_id] = $departement->name;
            }
        }

        return $carte;
    }

    // Enfants actifs d'un nœud (ou racines si $parentId est null), pour la
    // cascade de transfert de courrier (Phase 3) — optionnellement filtrés
    // par type.
    public static function enfantsActifsDe(?int $parentId, ?string $type = null)
    {
        return self::query()
            ->where('parent_id', $parentId)
            ->where('status', self::STATUT_ACTIF)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
