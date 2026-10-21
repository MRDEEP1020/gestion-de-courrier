<?php

namespace App\Livewire\Backend;

use App\Models\OrganizationUnit;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CompteUtilisateurNotification;
use App\Services\AvatarService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

// Page "Utilisateurs & Accès" reconstruite le 2026-09-21 depuis la maquette
// fournie par l'utilisateur — remplace l'ancienne page "deux boîtes"
// (assignation de privilèges + vue d'ensemble uniquement) par une vraie
// table d'utilisateurs + 3 modales (ajouter / modifier / gérer les
// permissions). Voir le journal de développement pour la liste complète des
// décisions prises avec l'utilisateur avant cette reconstruction — en
// particulier : le catalogue de privilèges devient FIXE (plus de page
// /admin/privileges), et /admin/profils (assignation par PROFIL) reste une
// page séparée, INCHANGÉE.
#[Title('Utilisateurs & Accès')]
class UserList extends Component
{
    use WithFileUploads, WithPagination;

    // ===== Table =====
    public string $recherche = '';

    // 2026-09-23, demande explicite de l'utilisateur : filtre désormais sur
    // le Département de l'organigramme plutôt que le service brut — voir
    // departementsFiltrables()/utilisateurs() ci-dessous.
    public ?int $departementFiltreId = null;

    // #[Url] (2026-09-23) : lien "Voir les utilisateurs" de la page Profils.
    #[Url]
    public ?int $profilFiltreId = null;

    public string $statutFiltre = '';

    public ?int $confidentialiteFiltreId = null;

    public string $colonneTri = 'name';

    public string $sensTri = 'asc';

    // ===== Modale "Ajouter un utilisateur" =====
    // Nom/Prénom séparés (2026-09-21, "i want the exact informations as
    // found that images ... i want a replica of it" — la maquette montre
    // deux champs distincts) — MAIS combinés dans la seule colonne
    // `users.name` existante à l'enregistrement : décision explicite de
    // l'utilisateur ("Cosmetic only — two boxes, one column"), pas de
    // colonne `prenom` séparée (`name` est lu dans des dizaines de fichiers
    // du projet, une vraie scission serait un chantier séparé).
    public string $ajoutNom = '';

    public string $ajoutPrenom = '';

    public string $ajoutEmail = '';

    public string $ajoutTelephone = '';

    public string $ajoutPoste = '';

    // 2026-09-23, demande explicite de l'utilisateur ("we assign them in a
    // department first before service") — remplace le sélecteur plat
    // unique ajoutServiceId par la même cascade Site → Département →
    // Service/Unité que RegistrationForm/ShowCourrier (Module "Organisation"
    // v2). Le service RÉEL (users.service_id, colonne INCHANGÉE, toujours
    // utilisée par le reste de l'application) est résolu via le pont —
    // voir resoudreServiceDepuisCascade() ci-dessous.
    public ?int $ajoutSiteSelectionneId = null;

    // 2026-10-09 — niveau Direction ajouté (OrganizationUnit::TYPE_DIRECTION),
    // OPTIONNEL comme Site l'était déjà pour Département.
    public ?int $ajoutDirectionSelectionneeId = null;

    public ?int $ajoutDepartementSelectionneId = null;

    public ?int $ajoutUniteSelectionneeId = null;

    public ?int $ajoutProfilId = null;

    public int $ajoutNiveauConfidentialite = 1;

    public bool $ajoutActif = true;

    public $ajoutPhoto = null;

    // ===== Modale "Modifier l'utilisateur" (également ciblée par la
    // modale "Gestion des permissions" et l'onglet "Destinataires de
    // transfert" — un seul utilisateur "en cours d'édition" à la fois,
    // contrairement à l'ancienne page qui distinguait un utilisateur
    // "sélectionné" (deux boîtes) d'un utilisateur "aperçu" (modale) sans
    // raison fonctionnelle réelle de les séparer). =====
    public ?int $utilisateurEditionId = null;

    // Même principe que ajoutNom/ajoutPrenom ci-dessus — scindés à
    // l'affichage (voir ouvrirEdition(), split naïf sur le premier espace
    // de `name`), recombinés à l'enregistrement.
    public string $editionNom = '';

    public string $editionPrenom = '';

    public string $editionEmail = '';

    public string $editionTelephone = '';

    public string $editionPoste = '';

    // Même cascade que ajoutSiteSelectionneId ci-dessus, dupliquée pour la
    // modale "Modifier" (même convention que le reste de ce composant —
    // ajout/édition ont toujours leurs propres propriétés séparées).
    public ?int $editionSiteSelectionneId = null;

    // 2026-10-09 — niveau Direction ajouté (OrganizationUnit::TYPE_DIRECTION),
    // OPTIONNEL comme Site l'était déjà pour Département.
    public ?int $editionDirectionSelectionneeId = null;

    public ?int $editionDepartementSelectionneId = null;

    public ?int $editionUniteSelectionneeId = null;

    // Cascade telle que préremplie à l'ouverture (2026-09-24) — permet à
    // enregistrerEdition() de savoir si l'administrateur l'a réellement
    // touchée (voir son commentaire).
    public array $editionCascadeInitiale = [];

    public ?int $editionProfilId = null;

    public int $editionNiveauConfidentialite = 1;

    public bool $editionActif = true;

    public $editionPhoto = null;

    // ===== Modale "Gestion des permissions" — toujours pour
    // $utilisateurEditionId, jamais un second utilisateur "sélectionné"
    // distinct. =====
    public string $modulePermissionSelectionne = 'courriers';

    public string $recherchePermission = '';

    // ===== Onglet "Destinataires de transfert" (voir DECISIONS.md) — même
    // principe "deux boîtes" que l'ancienne page, reciblé sur
    // $utilisateurEditionId. =====
    public string $rechercheDestinatairesDisponibles = '';

    public string $rechercheDestinatairesAssignes = '';

    public array $selectionDestinatairesDisponibles = [];

    public array $selectionDestinatairesAssignes = [];

    // Même principe, pour les SERVICES (2026-10-07, voir DECISIONS.md
    // "Destinataires de transfert — services") — un pli confidentiel est
    // parfois adressé à tout un service ("Direction Générale", "RH"),
    // pas seulement à une personne nommée.
    public string $rechercheServicesDisponibles = '';

    public string $rechercheServicesAssignes = '';

    public array $selectionServicesDisponibles = [];

    public array $selectionServicesAssignes = [];

    // ===== Onglet "Périmètre d'accès" (Module "Organisation" v2, spec §19)
    // — même patron "deux boîtes" que "Destinataires de transfert" ci-dessus,
    // mais sur les entités OrganizationUnit plutôt que sur des utilisateurs :
    // opt-in (un utilisateur sans périmètre n'est pas restreint, voir
    // Courrier::estDansLePerimetreDe()), donc cette liste "assignés" est
    // généralement vide pour la plupart des utilisateurs.
    public string $recherchePerimetreDisponibles = '';

    public string $recherchePerimetreAssignes = '';

    public array $selectionPerimetreDisponibles = [];

    public array $selectionPerimetreAssignes = [];

    // 2026-09-23 (menus pilotés par privilège, voir DECISIONS.md) — deux
    // niveaux sur cette page :
    //   - 'gererUtilisateurs' (utilisateurs.gerer) : accès à la page,
    //     coordonnées, activation, mot de passe, destinataires de transfert ;
    //   - 'gerer' (privileges.gerer) EN PLUS : créer un compte, changer
    //     profil/niveau de confidentialité, périmètre et permissions.
    public function mount(): void
    {
        $this->authorize('gererUtilisateurs', Privilege::class);

        // 2026-09-24 — "Gérer cet utilisateur" (page Organisation) ouvre
        // directement la modale de CE compte (?modifier={id}) au lieu de la
        // seule liste ; ouvrirEdition() revérifie les droits sur le compte.
        if ($id = request()->integer('modifier')) {
            $this->ouvrirEdition($id);
        }
    }

    // Sans privileges.gerer, un compte qui le détient (ou un Administrateur)
    // reste intouchable : sinon changer son email puis réinitialiser son mot
    // de passe suffirait à en prendre le contrôle.
    public function peutGererCompte(User $cible): bool
    {
        if (Auth::user()->can('gerer', Privilege::class)) {
            return true;
        }

        return $cible->profil?->nom !== 'Administrateur' && ! $cible->hasPrivilege('privileges.gerer');
    }

    private function autoriserCompte(User $cible): void
    {
        $this->authorize('gererUtilisateurs', Privilege::class);

        abort_unless($this->peutGererCompte($cible), 403);
    }

    public function updatingRecherche(): void
    {
        $this->resetPage();
    }

    public function updatingDepartementFiltreId(): void
    {
        $this->resetPage();
    }

    // Cascade Site → Direction → Département → Service/Unité (ajout ET
    // édition, niveau Direction ajouté le 2026-10-09) — un changement de
    // niveau vide les niveaux dépendants ET recalcule le service_id réel
    // résolu, même patron que ShowCourrier/RegistrationForm (Module
    // "Organisation" v2).
    public function updated($nom): void
    {
        if ($nom === 'ajoutSiteSelectionneId') {
            $this->reset('ajoutDirectionSelectionneeId', 'ajoutDepartementSelectionneId', 'ajoutUniteSelectionneeId');
        } elseif ($nom === 'ajoutDirectionSelectionneeId') {
            $this->reset('ajoutDepartementSelectionneId', 'ajoutUniteSelectionneeId');
        } elseif ($nom === 'ajoutDepartementSelectionneId') {
            $this->reset('ajoutUniteSelectionneeId');
        } elseif ($nom === 'editionSiteSelectionneId') {
            $this->reset('editionDirectionSelectionneeId', 'editionDepartementSelectionneId', 'editionUniteSelectionneeId');
        } elseif ($nom === 'editionDirectionSelectionneeId') {
            $this->reset('editionDepartementSelectionneId', 'editionUniteSelectionneeId');
        } elseif ($nom === 'editionDepartementSelectionneId') {
            $this->reset('editionUniteSelectionneeId');
        }
    }

    public function updatingProfilFiltreId(): void
    {
        $this->resetPage();
    }

    public function updatingStatutFiltre(): void
    {
        $this->resetPage();
    }

    public function updatingConfidentialiteFiltreId(): void
    {
        $this->resetPage();
    }

    public function reinitialiserFiltres(): void
    {
        $this->reset(['recherche', 'departementFiltreId', 'profilFiltreId', 'statutFiltre', 'confidentialiteFiltreId']);
        $this->resetPage();
    }

    public function trierPar(string $colonne): void
    {
        if ($this->colonneTri === $colonne) {
            $this->sensTri = $this->sensTri === 'asc' ? 'desc' : 'asc';
        } else {
            $this->colonneTri = $colonne;
            $this->sensTri = 'asc';
        }
    }

    // Règle n°3 — pagination systématique, jamais ::all() sur une table qui
    // va grossir (potentiellement toute l'entreprise, voir PRD.md §5).
    #[Computed]
    public function utilisateurs()
    {
        // Colonnes triables whitelistées (Règle n°6 — jamais un orderBy()
        // sur une colonne arbitraire).
        $colonnesTriables = ['name', 'email', 'derniere_connexion_le'];
        $colonne = in_array($this->colonneTri, $colonnesTriables, true) ? $this->colonneTri : 'name';

        return User::query()
            ->with(['profil', 'organizationUnits'])
            ->when($this->recherche !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->recherche}%")
                ->orWhere('email', 'like', "%{$this->recherche}%")))
            // Filtre par Département de l'organigramme (2026-09-23) — un
            // utilisateur correspond via le pont service_id (tout le
            // sous-arbre, même helper que le gate périmètre), un
            // rattachement de travail direct (organization_unit_user), OU
            // le fait d'être RESPONSABLE désigné d'un nœud du sous-arbre —
            // les trois mécanismes comptent, voir departementLabelDe().
            ->when($this->departementFiltreId, function ($q) {
                $departement = OrganizationUnit::find($this->departementFiltreId);

                if ($departement === null) {
                    return;
                }

                $sousArbre = collect([$departement]);
                $idsServices = OrganizationUnit::idsServicesReelsSousArbre($sousArbre);
                $idsNoeuds = OrganizationUnit::idsSousArbre($sousArbre);
                $idsResponsables = OrganizationUnit::query()->whereIn('id', $idsNoeuds)->whereNotNull('responsible_user_id')->pluck('responsible_user_id');

                $q->where(fn ($q) => $q
                    ->whereIn('service_id', $idsServices)
                    ->orWhereHas('organizationUnits', fn ($q) => $q->whereIn('organization_units.id', $idsNoeuds))
                    ->orWhereIn('id', $idsResponsables));
            })
            ->when($this->profilFiltreId, fn ($q) => $q->where('profil_id', $this->profilFiltreId))
            ->when($this->statutFiltre !== '', fn ($q) => $q->where('actif', $this->statutFiltre === 'actif'))
            ->when($this->confidentialiteFiltreId, fn ($q) => $q->where('niveau_confidentialite', $this->confidentialiteFiltreId))
            ->orderBy($colonne, $this->sensTri === 'desc' ? 'desc' : 'asc')
            ->paginate(10);
    }

    // 2026-09-23 — mêmes 3 niveaux que ShowCourrier::sitesDisponibles()/
    // departementsDisponibles()/unitesDisponibles() (dupliqués à
    // l'identique, même convention que partout ailleurs dans ce projet —
    // pas de composant Livewire partagé, Règle n°2), un jeu par modale
    // (ajout/édition) puisque leurs propriétés de sélection sont séparées.
    #[Computed]
    public function sitesDisponibles()
    {
        return OrganizationUnit::query()
            ->where('type', OrganizationUnit::TYPE_SITE)
            ->where('status', OrganizationUnit::STATUT_ACTIF)
            ->orderBy('name')
            ->get();
    }

    // 2026-10-09 — niveau Direction, OPTIONNEL comme le reste de la cascade.
    #[Computed]
    public function ajoutDirectionsDisponibles()
    {
        return OrganizationUnit::enfantsActifsDe($this->ajoutSiteSelectionneId, OrganizationUnit::TYPE_DIRECTION);
    }

    #[Computed]
    public function ajoutDepartementsDisponibles()
    {
        $parentId = $this->ajoutDirectionSelectionneeId ?? $this->ajoutSiteSelectionneId;

        return OrganizationUnit::enfantsActifsDe($parentId, OrganizationUnit::TYPE_DEPARTMENT);
    }

    #[Computed]
    public function ajoutUnitesDisponibles()
    {
        if ($this->ajoutDepartementSelectionneId === null) {
            return collect();
        }

        return OrganizationUnit::query()
            ->where('parent_id', $this->ajoutDepartementSelectionneId)
            ->whereIn('type', [OrganizationUnit::TYPE_SERVICE, OrganizationUnit::TYPE_SUB_SERVICE])
            ->where('status', OrganizationUnit::STATUT_ACTIF)
            ->orderBy('name')
            ->get();
    }

    // 2026-10-09 — niveau Direction, OPTIONNEL comme le reste de la cascade.
    #[Computed]
    public function editionDirectionsDisponibles()
    {
        return OrganizationUnit::enfantsActifsDe($this->editionSiteSelectionneId, OrganizationUnit::TYPE_DIRECTION);
    }

    #[Computed]
    public function editionDepartementsDisponibles()
    {
        $parentId = $this->editionDirectionSelectionneeId ?? $this->editionSiteSelectionneId;

        return OrganizationUnit::enfantsActifsDe($parentId, OrganizationUnit::TYPE_DEPARTMENT);
    }

    #[Computed]
    public function editionUnitesDisponibles()
    {
        if ($this->editionDepartementSelectionneId === null) {
            return collect();
        }

        return OrganizationUnit::query()
            ->where('parent_id', $this->editionDepartementSelectionneId)
            ->whereIn('type', [OrganizationUnit::TYPE_SERVICE, OrganizationUnit::TYPE_SUB_SERVICE])
            ->where('status', OrganizationUnit::STATUT_ACTIF)
            ->orderBy('name')
            ->get();
    }

    // Filtre de la table — un Département par ligne (pas de profondeur
    // Site/Sous-service, un filtre "par département" suffit ici).
    #[Computed]
    public function departementsFiltrables()
    {
        return OrganizationUnit::query()
            ->where('type', OrganizationUnit::TYPE_DEPARTMENT)
            ->where('status', OrganizationUnit::STATUT_ACTIF)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    // Une seule requête pour toute la page (Règle n°3), réutilisée par
    // departementLabelParServiceId() ET departementLabelDe() ci-dessous.
    // responsible_user_id inclus pour reconnaître un utilisateur RESPONSABLE
    // d'une entité (voir departementLabelDe()), pas seulement rattaché.
    #[Computed]
    public function tousLesNoeudsOrganisation(): Collection
    {
        return OrganizationUnit::query()->get(['id', 'parent_id', 'type', 'service_id', 'name', 'responsible_user_id']);
    }

    #[Computed]
    public function departementLabelParServiceId(): array
    {
        return OrganizationUnit::departementLabelParServiceId($this->tousLesNoeudsOrganisation);
    }

    // 2026-09-23, demande explicite de l'utilisateur ("i added one
    // collaborateur under a department but it doesn't show it on user
    // list", puis "what about those responsable too") — TROIS mécanismes
    // distincts déterminent le département d'un utilisateur, jamais
    // synchronisés entre eux jusqu'ici : (1) être le RESPONSABLE désigné
    // d'une entité (responsible_user_id, "Définir comme responsable" sur la
    // page Organisation) — le signal le plus explicite, vérifié en premier ;
    // (2) le rattachement de TRAVAIL réel (organization_unit_user, onglet
    // "Utilisateurs" de la page Organisation) ; (3) le pont service_id
    // (ajouté/modifié depuis CETTE page), en dernier recours.
    // `$utilisateur->organizationUnits` doit être eager-chargée par
    // l'appelant (voir utilisateurs()/utilisateurEnEdition() ci-dessous)
    // pour éviter un N+1 sur chaque ligne de la table.
    public function departementLabelDe(User $utilisateur): ?string
    {
        $tousLesNoeuds = $this->tousLesNoeudsOrganisation;

        $noeudResponsable = $tousLesNoeuds->firstWhere('responsible_user_id', $utilisateur->id);

        if ($noeudResponsable !== null) {
            $departement = $noeudResponsable->departementOuAncetreDepartement($tousLesNoeuds);

            if ($departement !== null) {
                return $departement->name;
            }
        }

        $membre = $utilisateur->organizationUnits->firstWhere('pivot.is_primary', true)
            ?? $utilisateur->organizationUnits->first();

        if ($membre !== null) {
            $noeudComplet = $tousLesNoeuds->firstWhere('id', $membre->id) ?? $membre;
            $departement = $noeudComplet->departementOuAncetreDepartement($tousLesNoeuds);

            if ($departement !== null) {
                return $departement->name;
            }
        }

        return $this->departementLabelParServiceId[$utilisateur->service_id] ?? null;
    }

    // Nœud RÉELLEMENT retenu (Service/Unité choisi, sinon le Département
    // lui-même — spec §1), résolu vers le service RÉEL pour users.service_id
    // — même patron que ShowCourrier/RegistrationForm.
    private function resoudreServiceDepuisCascade(?int $uniteId, ?int $departementId): ?int
    {
        $noeud = $uniteId
            ? OrganizationUnit::find($uniteId)
            : ($departementId ? OrganizationUnit::find($departementId) : null);

        return $noeud?->service_id;
    }

    // 2026-10-09 — retour utilisateur direct ("WHY IF I CHOSE DEPARTMENT AND
    // SITE AND ORGANISATION WHEN CREATING OR MODIFYING ET USER IT DOESN'T
    // AUTOMATICALLY ADD THEM ON THE ORGANISATIONS HIERACHARY") : la cascade
    // Site/Direction/Département/Service ne résolvait jusqu'ici QUE
    // users.service_id (le pont historique) — jamais le vrai rattachement de
    // travail organization_unit_user (celui que lit l'onglet "Utilisateurs"
    // de la page Organisation, voir departementLabelDe() ci-dessus pour le
    // même constat déjà fait une fois côté AFFICHAGE le 2026-09-23). Même
    // nœud retenu que resoudreServiceDepuisCascade() ci-dessus (Unité/Service
    // si choisi, sinon Département) — devient désormais AUSSI le
    // rattachement PRINCIPAL (is_primary) de l'utilisateur, remplaçant
    // l'ancien s'il pointait vers un nœud différent. $noeudId === null
    // (cascade explicitement vidée) détache le rattachement principal
    // existant plutôt que d'en laisser un périmé.
    private function synchroniserRattachementPrincipal(User $user, ?int $noeudId): void
    {
        $ancienPrincipal = $user->organizationUnits()->wherePivot('is_primary', true)->first();

        if ($ancienPrincipal && $ancienPrincipal->id !== $noeudId) {
            $user->organizationUnits()->detach($ancienPrincipal->id);
        }

        if ($noeudId === null) {
            return;
        }

        if ($user->organizationUnits()->where('organization_units.id', $noeudId)->exists()) {
            $user->organizationUnits()->updateExistingPivot($noeudId, ['is_primary' => true]);
        } else {
            $user->organizationUnits()->attach($noeudId, ['is_primary' => true]);
        }
    }

    // Préremplit la cascade d'édition avec la MÊME position que la colonne
    // "Département" de la table (2026-09-24, demande explicite de
    // l'utilisateur : "make the all modify modal to show the correct
    // informations") — même ordre que departementLabelDe() : entité dont il
    // est responsable, puis rattachement de travail (principal d'abord),
    // puis pont service_id. Auparavant seul le pont service_id était lu :
    // un responsable ou un utilisateur rattaché depuis la page Organisation
    // voyait une cascade vide alors que la table affichait son département.
    // Aucune position trouvée = cascade vide — jamais de donnée fabriquée.
    private function preselectionnerCascadeEdition(User $user): void
    {
        $this->reset('editionSiteSelectionneId', 'editionDirectionSelectionneeId', 'editionDepartementSelectionneId', 'editionUniteSelectionneeId');

        $membre = $user->organizationUnits->firstWhere('pivot.is_primary', true) ?? $user->organizationUnits->first();

        $courant = OrganizationUnit::where('responsible_user_id', $user->id)->first()
            ?? ($membre ? OrganizationUnit::find($membre->id) : null)
            ?? ($user->service_id ? OrganizationUnit::where('service_id', $user->service_id)->first() : null);

        $this->remplirCascadeEditionDepuis($courant);
    }

    private function cascadeEdition(): array
    {
        return [$this->editionSiteSelectionneId, $this->editionDirectionSelectionneeId, $this->editionDepartementSelectionneId, $this->editionUniteSelectionneeId];
    }

    // Remonte $courant PUIS ses ancêtres (même patron que
    // ShowCourrier::preselectionnerCascadeDepuisService()).
    private function remplirCascadeEditionDepuis(?OrganizationUnit $courant): void
    {
        while ($courant !== null) {
            match ($courant->type) {
                OrganizationUnit::TYPE_SUB_SERVICE, OrganizationUnit::TYPE_SERVICE => $this->editionUniteSelectionneeId = $courant->id,
                OrganizationUnit::TYPE_DEPARTMENT => $this->editionDepartementSelectionneId = $courant->id,
                OrganizationUnit::TYPE_DIRECTION => $this->editionDirectionSelectionneeId = $courant->id,
                OrganizationUnit::TYPE_SITE => $this->editionSiteSelectionneId = $courant->id,
                default => null,
            };

            $courant = $courant->parent;
        }
    }

    #[Computed]
    public function profils()
    {
        return Profil::query()->orderBy('nom')->get(['id', 'nom']);
    }

    // ===== Ajouter un utilisateur =====
    public function ouvrirAjout(): void
    {
        $this->authorize('gerer', Privilege::class);

        $this->reset(['ajoutNom', 'ajoutPrenom', 'ajoutEmail', 'ajoutTelephone', 'ajoutPoste', 'ajoutSiteSelectionneId', 'ajoutDirectionSelectionneeId', 'ajoutDepartementSelectionneId', 'ajoutUniteSelectionneeId', 'ajoutProfilId', 'ajoutPhoto']);
        $this->ajoutNiveauConfidentialite = 1;
        $this->ajoutActif = true;
        $this->resetValidation();

        Flux::modal('user-ajout')->show();
    }

    public function ajouter(AvatarService $avatars): void
    {
        $this->authorize('gerer', Privilege::class);

        $data = $this->validate([
            'ajoutNom' => ['required', 'string', 'max:255'],
            'ajoutPrenom' => ['required', 'string', 'max:255'],
            'ajoutEmail' => ['required', 'email', 'max:255', 'unique:users,email'],
            'ajoutTelephone' => ['nullable', 'string', 'max:30'],
            'ajoutPoste' => ['nullable', 'string', 'max:255'],
            'ajoutProfilId' => ['required', 'exists:profils,id'],
            'ajoutNiveauConfidentialite' => ['required', 'integer', 'between:1,'.User::niveauConfidentialiteMax()],
            'ajoutPhoto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ], [], [
            'ajoutNom' => __('nom'),
            'ajoutPrenom' => __('prénom'),
            'ajoutEmail' => __('email'),
            'ajoutProfilId' => __('profil'),
        ]);

        // Nullable (2026-09-22, demande explicite de l'utilisateur) : une
        // réceptionniste à l'accueil n'appartient à aucun département listé
        // — colonne déjà nullable en base
        // (2026_09_04_110000_add_service_id_to_users_table.php). Résolu via
        // la cascade Département/Service-Unité (2026-09-23) plutôt qu'un
        // sélecteur plat — voir resoudreServiceDepuisCascade().
        $user = User::create([
            'name' => trim("{$data['ajoutNom']} {$data['ajoutPrenom']}"),
            'email' => $data['ajoutEmail'],
            'telephone' => $data['ajoutTelephone'] ?: null,
            'poste' => $data['ajoutPoste'] ?: null,
            'service_id' => $this->resoudreServiceDepuisCascade($this->ajoutUniteSelectionneeId, $this->ajoutDepartementSelectionneId),
            'profil_id' => $data['ajoutProfilId'],
            'niveau_confidentialite' => $data['ajoutNiveauConfidentialite'],
            'actif' => $this->ajoutActif,
            // Mot de passe aléatoire, jamais connu/communiqué directement —
            // l'utilisateur définit le sien via le lien de réinitialisation
            // ci-dessous (flux RÉEL déjà existant, voir
            // FortifyServiceProvider::resetUserPasswordsUsing), plutôt que
            // de prétendre "envoyer ses identifiants" (aucune messagerie de
            // ce type n'existe dans ce projet, voir Module 7).
            'password' => Hash::make(Str::random(40)),
        ]);

        $this->synchroniserRattachementPrincipal($user, $this->ajoutUniteSelectionneeId ?: $this->ajoutDepartementSelectionneId);

        if ($this->ajoutPhoto) {
            $avatars->televerser($user, $this->ajoutPhoto);
        }

        Password::sendResetLink(['email' => $user->email]);

        unset($this->utilisateurs);
        Flux::modal('user-ajout')->close();
        Flux::toast(variant: 'success', text: __('Utilisateur créé — un email pour définir son mot de passe lui a été envoyé.'));
    }

    // ===== Modifier un utilisateur =====
    public function ouvrirEdition(int $id): void
    {
        $user = User::findOrFail($id);
        $this->autoriserCompte($user);

        // Split naïf sur le premier espace — aucune colonne `prenom`
        // séparée n'existe (décision explicite, voir ajoutNom/ajoutPrenom
        // plus haut) : reconstitué à l'identique par enregistrerEdition()
        // ci-dessous si ni le nom ni le prénom ne sont retouchés.
        $morceaux = explode(' ', $user->name, 2);

        $this->utilisateurEditionId = $user->id;
        $this->editionNom = $morceaux[0] ?? '';
        $this->editionPrenom = $morceaux[1] ?? '';
        $this->editionEmail = $user->email;
        $this->editionTelephone = $user->telephone ?? '';
        $this->editionPoste = $user->poste ?? '';
        $this->preselectionnerCascadeEdition($user);
        $this->editionCascadeInitiale = $this->cascadeEdition();
        $this->editionProfilId = $user->profil_id;
        $this->editionNiveauConfidentialite = $user->niveau_confidentialite;
        $this->editionActif = $user->actif;
        $this->editionPhoto = null;
        $this->resetValidation();
        $this->reset(['selectionDestinatairesDisponibles', 'selectionDestinatairesAssignes', 'selectionServicesDisponibles', 'selectionServicesAssignes', 'selectionPerimetreDisponibles', 'selectionPerimetreAssignes', 'recherchePerimetreDisponibles', 'recherchePerimetreAssignes']);

        Flux::modal('user-edition')->show();
    }

    public function enregistrerEdition(AvatarService $avatars): void
    {
        $user = User::findOrFail($this->utilisateurEditionId);
        $this->autoriserCompte($user);

        // Profil et niveau de confidentialité = droits d'accès : sans
        // privileges.gerer, les valeurs postées sont ignorées (champs
        // désactivés dans la vue, jamais fait confiance au client — Règle n°6).
        if (! Auth::user()->can('gerer', Privilege::class)) {
            $this->editionProfilId = $user->profil_id;
            $this->editionNiveauConfidentialite = $user->niveau_confidentialite;
        }

        $data = $this->validate([
            'editionNom' => ['required', 'string', 'max:255'],
            'editionPrenom' => ['required', 'string', 'max:255'],
            'editionEmail' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'editionTelephone' => ['nullable', 'string', 'max:30'],
            'editionPoste' => ['nullable', 'string', 'max:255'],
            'editionProfilId' => ['required', 'exists:profils,id'],
            'editionNiveauConfidentialite' => ['required', 'integer', 'between:1,'.User::niveauConfidentialiteMax()],
            'editionPhoto' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ], [], [
            'editionNom' => __('nom'),
            'editionPrenom' => __('prénom'),
            'editionEmail' => __('email'),
            'editionProfilId' => __('profil'),
        ]);

        // Nullable — même raison que ajouter() ci-dessus ; résolu via la
        // cascade Département/Service-Unité (2026-09-23).
        // 2026-09-24 — cascade NON touchée qui ne résout aucun service réel
        // (service pas encore dans l'organigramme, ex. agent "AC", ou entité
        // affichée non reliée à un service) : le service actuel est conservé.
        // Auparavant, corriger un simple téléphone effaçait ce service.
        $serviceResolu = $this->resoudreServiceDepuisCascade($this->editionUniteSelectionneeId, $this->editionDepartementSelectionneId);
        $cascadeModifiee = $this->cascadeEdition() !== $this->editionCascadeInitiale;

        if ($serviceResolu === null && ! $cascadeModifiee) {
            $serviceResolu = $user->service_id;
        }

        // Même garde que service_id ci-dessus : une cascade jamais touchée
        // (ex. correction d'un simple téléphone) ne doit RIEN changer au
        // rattachement organisationnel existant, quelle que soit sa source
        // (responsable désigné, rattachement réel déjà posé ailleurs...).
        if ($cascadeModifiee) {
            $this->synchroniserRattachementPrincipal($user, $this->editionUniteSelectionneeId ?: $this->editionDepartementSelectionneId);
        }

        // 2026-10-09 (audit "list me all") — capturé AVANT update() pour
        // savoir si profil/niveau/service/statut ont réellement changé :
        // une simple correction de téléphone ne doit jamais notifier "votre
        // compte a été modifié" pour rien.
        $actifAvant = $user->actif;
        $profilAvant = $user->profil_id;
        $niveauAvant = $user->niveau_confidentialite;

        $user->update([
            'name' => trim("{$data['editionNom']} {$data['editionPrenom']}"),
            'email' => $data['editionEmail'],
            'telephone' => $data['editionTelephone'] ?: null,
            'poste' => $data['editionPoste'] ?: null,
            'service_id' => $serviceResolu,
            'profil_id' => $data['editionProfilId'],
            'niveau_confidentialite' => $data['editionNiveauConfidentialite'],
            'actif' => $this->editionActif,
        ]);

        if ($this->editionPhoto) {
            $avatars->televerser($user, $this->editionPhoto);
        }

        if ($actifAvant !== $user->actif) {
            $user->notify(new CompteUtilisateurNotification($user->actif ? 'active' : 'desactive', envoyePar: Auth::user()));
        } elseif ($profilAvant !== $user->profil_id || $niveauAvant !== $user->niveau_confidentialite || $cascadeModifiee) {
            $user->notify(new CompteUtilisateurNotification('modifie', envoyePar: Auth::user()));
        }

        unset($this->utilisateurs, $this->utilisateurEnEdition);
        Flux::modal('user-edition')->close();
        Flux::toast(variant: 'success', text: __('Utilisateur modifié.'));
    }

    // Bascule directe depuis le menu d'actions de la table — pas besoin
    // d'ouvrir la modale pour ce cas simple.
    public function basculerActif(int $id): void
    {
        $user = User::findOrFail($id);
        $this->autoriserCompte($user);

        $user->update(['actif' => ! $user->actif]);

        // 2026-10-09 (audit "list me all") — un compte désactivé ne
        // l'apprenait jusqu'ici jamais (ne comprendrait pas pourquoi il ne
        // peut plus se connecter).
        $user->notify(new CompteUtilisateurNotification($user->actif ? 'active' : 'desactive', envoyePar: Auth::user()));

        unset($this->utilisateurs);
        Flux::toast(text: $user->actif ? __('Compte activé.') : __('Compte désactivé.'));
    }

    public function reinitialiserMotDePasse(int $id): void
    {
        $user = User::findOrFail($id);
        $this->autoriserCompte($user);

        Password::sendResetLink(['email' => $user->email]);

        Flux::toast(text: __('Email de réinitialisation envoyé.'));
    }

    // ===== Modale "Gestion des permissions" =====
    public function ouvrirPermissions(int $id): void
    {
        $this->authorize('gerer', Privilege::class);

        if ($this->utilisateurEditionId !== $id) {
            $this->ouvrirEdition($id);
        }

        $this->modulePermissionSelectionne = 'courriers';
        $this->recherchePermission = '';

        Flux::modal('user-edition')->close();
        Flux::modal('user-permissions')->show();
    }

    #[Computed]
    public function utilisateurEnEdition(): ?User
    {
        return $this->utilisateurEditionId
            ? User::with(['profil.privileges', 'privilegesDirectes', 'destinatairesTransfert:id', 'organizationUnitsPerimetre:id', 'organizationUnits', 'service'])->find($this->utilisateurEditionId)
            : null;
    }

    // Catalogue complet (Règle n°3 — table courte, ~26 lignes, pas de
    // pagination nécessaire ici, même principe que l'ancienne page).
    #[Computed]
    public function permissionsCatalogue()
    {
        return Privilege::orderBy('nom')->get();
    }

    // Modules réels (voir Privilege::MODULES) avec leur compte RÉEL de
    // privilèges — pas les 8 modules/compteurs fictifs de la maquette GPT.
    #[Computed]
    public function modules()
    {
        return $this->permissionsCatalogue
            ->groupBy(fn (Privilege $p) => $p->moduleCle())
            ->map(fn ($privileges, $cle) => [
                'cle' => $cle,
                'label' => Privilege::MODULES[$cle]['label'] ?? $cle,
                'icon' => Privilege::MODULES[$cle]['icon'] ?? 'squares-2x2',
                'total' => $privileges->count(),
            ])
            ->sortBy('label')
            ->values();
    }

    #[Computed]
    public function permissionsDuModule()
    {
        $recherche = trim(mb_strtolower($this->recherchePermission));

        return $this->permissionsCatalogue
            ->filter(fn (Privilege $p) => $p->moduleCle() === $this->modulePermissionSelectionne)
            ->filter(fn (Privilege $p) => $recherche === '' || str_contains(mb_strtolower($p->nom), $recherche) || str_contains(mb_strtolower($p->description ?? ''), $recherche))
            ->values();
    }

    // Résumé de droite — comptes RÉELS calculés depuis l'effectif de
    // l'utilisateur (profil ∪ individuel, même union que
    // User::privilegesCles()), jamais les chiffres inventés de la maquette.
    #[Computed]
    public function resumePermissions(): array
    {
        $utilisateur = $this->utilisateurEnEdition;

        if (! $utilisateur) {
            return ['modulesActifs' => 0, 'modulesTotal' => 0, 'lecture' => 0, 'ecriture' => 0, 'administratif' => 0];
        }

        $clesEffectives = $utilisateur->privilegesCles();
        $privilegesEffectifs = $this->permissionsCatalogue->whereIn('cle', $clesEffectives);

        return [
            'modulesActifs' => $privilegesEffectifs->map(fn (Privilege $p) => $p->moduleCle())->unique()->count(),
            'modulesTotal' => $this->modules->count(),
            'lecture' => $privilegesEffectifs->where('type', 'lecture')->count(),
            'ecriture' => $privilegesEffectifs->where('type', 'ecriture')->count(),
            'administratif' => $privilegesEffectifs->where('type', 'administratif')->count(),
        ];
    }

    // Une permission directe (pas héritée du profil, celles-là ne se
    // décochent pas ici — voir /admin/profils) par clic sur sa case.
    public function basculerPermission(int $privilegeId): void
    {
        $this->authorize('gerer', Privilege::class);

        if (! $this->utilisateurEditionId) {
            return;
        }

        $utilisateur = $this->utilisateurEnEdition;
        $dejaAssignee = $utilisateur->privilegesDirectes->pluck('id')->contains($privilegeId);

        if ($dejaAssignee) {
            $utilisateur->privilegesDirectes()->detach($privilegeId);
        } else {
            $utilisateur->privilegesDirectes()->syncWithoutDetaching([$privilegeId]);
        }

        unset($this->utilisateurEnEdition);
    }

    // "Tout sélectionner"/"Tout désélectionner" — scopés au module
    // actuellement affiché (celui visible à l'écran), jamais tout le
    // catalogue d'un coup, pour éviter qu'un clic touche des permissions
    // qu'on ne voit pas.
    public function toutSelectionnerModule(): void
    {
        $this->authorize('gerer', Privilege::class);

        if (! $this->utilisateurEditionId) {
            return;
        }

        $ids = $this->permissionsDuModule->pluck('id');
        $this->utilisateurEnEdition->privilegesDirectes()->syncWithoutDetaching($ids);

        unset($this->utilisateurEnEdition);
    }

    public function toutDeselectionnerModule(): void
    {
        $this->authorize('gerer', Privilege::class);

        if (! $this->utilisateurEditionId) {
            return;
        }

        $ids = $this->permissionsDuModule->pluck('id');
        $this->utilisateurEnEdition->privilegesDirectes()->detach($ids);

        unset($this->utilisateurEnEdition);
    }

    // ===== Onglet "Destinataires de transfert" (reprend exactement la
    // logique de l'ancienne page, reciblée sur $utilisateurEditionId — voir
    // DECISIONS.md "Destinataires de transfert"). =====
    #[Computed]
    public function destinatairesDisponibles()
    {
        $utilisateur = $this->utilisateurEnEdition;

        $utilisateurs = User::query()->orderBy('name')->get(['id', 'name', 'email'])
            ->reject(fn (User $candidat) => $candidat->id === $this->utilisateurEditionId)
            ->when($utilisateur, fn ($u) => $u->whereNotIn('id', $utilisateur->destinatairesTransfert->pluck('id')));

        return $this->filtrerUtilisateurs($utilisateurs, $this->rechercheDestinatairesDisponibles)->values();
    }

    #[Computed]
    public function destinatairesDeLutilisateurSelectionne()
    {
        $utilisateur = $this->utilisateurEnEdition;

        if (! $utilisateur) {
            return collect();
        }

        $utilisateurs = User::query()->whereIn('id', $utilisateur->destinatairesTransfert->pluck('id'))->orderBy('name')->get(['id', 'name', 'email']);

        return $this->filtrerUtilisateurs($utilisateurs, $this->rechercheDestinatairesAssignes)->values();
    }

    private function filtrerUtilisateurs($utilisateurs, string $recherche)
    {
        $recherche = trim($recherche);

        if ($recherche === '') {
            return $utilisateurs;
        }

        return $utilisateurs->filter(
            fn (User $u) => str_contains(mb_strtolower($u->name), mb_strtolower($recherche))
                || str_contains(mb_strtolower($u->email), mb_strtolower($recherche))
        );
    }

    // Destinataires de transfert : réglage de circuit, pas un droit d'accès
    // — relève de utilisateurs.gerer (2026-09-23). utilisateurEditionId est
    // une propriété publique, donc le compte ciblé est revérifié à chaque
    // action (Règle n°6).
    private function autoriserCompteEnEdition(): void
    {
        $this->authorize('gererUtilisateurs', Privilege::class);

        if ($this->utilisateurEditionId) {
            $this->autoriserCompte(User::findOrFail($this->utilisateurEditionId));
        }
    }

    public function ajouterDestinataire(int $destinataireId): void
    {
        $this->autoriserCompteEnEdition();

        if (! $this->utilisateurEditionId || $destinataireId === $this->utilisateurEditionId) {
            return;
        }

        $this->utilisateurEnEdition->destinatairesTransfert()->syncWithoutDetaching([$destinataireId]);

        unset($this->utilisateurEnEdition);
    }

    public function retirerDestinataire(int $destinataireId): void
    {
        $this->autoriserCompteEnEdition();

        if (! $this->utilisateurEditionId) {
            return;
        }

        $this->utilisateurEnEdition->destinatairesTransfert()->detach($destinataireId);

        unset($this->utilisateurEnEdition);
    }

    public function ajouterSelectionDestinataires(): void
    {
        $this->autoriserCompteEnEdition();

        $ids = collect($this->selectionDestinatairesDisponibles)->reject(fn ($id) => (int) $id === $this->utilisateurEditionId)->all();

        if (! $this->utilisateurEditionId || $ids === []) {
            return;
        }

        $this->utilisateurEnEdition->destinatairesTransfert()->syncWithoutDetaching($ids);

        $this->selectionDestinatairesDisponibles = [];
        unset($this->utilisateurEnEdition);
    }

    public function retirerSelectionDestinataires(): void
    {
        $this->autoriserCompteEnEdition();

        if (! $this->utilisateurEditionId || $this->selectionDestinatairesAssignes === []) {
            return;
        }

        $this->utilisateurEnEdition->destinatairesTransfert()->detach($this->selectionDestinatairesAssignes);

        $this->selectionDestinatairesAssignes = [];
        unset($this->utilisateurEnEdition);
    }

    // ===== Même onglet "Destinataires de transfert", section SERVICES
    // (2026-10-07) — strictement le même patron "deux boîtes" que les
    // méthodes ci-dessus, juste sur Service plutôt que User. =====
    #[Computed]
    public function servicesDisponibles()
    {
        $utilisateur = $this->utilisateurEnEdition;

        $services = Service::query()->orderBy('nom')->get(['id', 'nom'])
            ->when($utilisateur, fn ($s) => $s->whereNotIn('id', $utilisateur->destinatairesTransfertServices->pluck('id')));

        return $this->filtrerServices($services, $this->rechercheServicesDisponibles)->values();
    }

    #[Computed]
    public function servicesDeLutilisateurSelectionne()
    {
        $utilisateur = $this->utilisateurEnEdition;

        if (! $utilisateur) {
            return collect();
        }

        $services = Service::query()->whereIn('id', $utilisateur->destinatairesTransfertServices->pluck('id'))->orderBy('nom')->get(['id', 'nom']);

        return $this->filtrerServices($services, $this->rechercheServicesAssignes)->values();
    }

    private function filtrerServices($services, string $recherche)
    {
        $recherche = trim($recherche);

        if ($recherche === '') {
            return $services;
        }

        return $services->filter(fn (Service $s) => str_contains(mb_strtolower($s->nom), mb_strtolower($recherche)));
    }

    public function ajouterServiceDestinataire(int $serviceId): void
    {
        $this->autoriserCompteEnEdition();

        if (! $this->utilisateurEditionId) {
            return;
        }

        $this->utilisateurEnEdition->destinatairesTransfertServices()->syncWithoutDetaching([$serviceId]);

        unset($this->utilisateurEnEdition);
    }

    public function retirerServiceDestinataire(int $serviceId): void
    {
        $this->autoriserCompteEnEdition();

        if (! $this->utilisateurEditionId) {
            return;
        }

        $this->utilisateurEnEdition->destinatairesTransfertServices()->detach($serviceId);

        unset($this->utilisateurEnEdition);
    }

    public function ajouterSelectionServices(): void
    {
        $this->autoriserCompteEnEdition();

        if (! $this->utilisateurEditionId || $this->selectionServicesDisponibles === []) {
            return;
        }

        $this->utilisateurEnEdition->destinatairesTransfertServices()->syncWithoutDetaching($this->selectionServicesDisponibles);

        $this->selectionServicesDisponibles = [];
        unset($this->utilisateurEnEdition);
    }

    public function retirerSelectionServices(): void
    {
        $this->autoriserCompteEnEdition();

        if (! $this->utilisateurEditionId || $this->selectionServicesAssignes === []) {
            return;
        }

        $this->utilisateurEnEdition->destinatairesTransfertServices()->detach($this->selectionServicesAssignes);

        $this->selectionServicesAssignes = [];
        unset($this->utilisateurEnEdition);
    }

    // ===== Onglet "Périmètre d'accès" (Module "Organisation" v2, spec §19)
    // — même logique two-box que "Destinataires de transfert" ci-dessus, sur
    // organization_unit_perimetre_user au lieu de destinataires_transfert.
    // Chemin complet affiché (ex. "Nsia Abidjan / Sinistres / Sinistre
    // Santé") pour que le choix reste compréhensible même hors de l'arbre.
    #[Computed]
    public function toutesLesUnitesOrganisationActives()
    {
        return OrganizationUnit::query()
            ->where('status', OrganizationUnit::STATUT_ACTIF)
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);
    }

    #[Computed]
    public function perimetreDisponible()
    {
        $utilisateur = $this->utilisateurEnEdition;
        $toutes = $this->toutesLesUnitesOrganisationActives;

        $assignesIds = $utilisateur ? $utilisateur->organizationUnitsPerimetre->pluck('id') : collect();

        $unites = $toutes->reject(fn (OrganizationUnit $u) => $assignesIds->contains($u->id))
            ->map(fn (OrganizationUnit $u) => ['id' => $u->id, 'chemin' => $u->cheminComplet($toutes)]);

        return $this->filtrerUnitesOrganisation($unites, $this->recherchePerimetreDisponibles);
    }

    #[Computed]
    public function perimetreDeLutilisateurSelectionne()
    {
        $utilisateur = $this->utilisateurEnEdition;

        if (! $utilisateur) {
            return collect();
        }

        $toutes = $this->toutesLesUnitesOrganisationActives;
        $assignesIds = $utilisateur->organizationUnitsPerimetre->pluck('id');

        $unites = $toutes->filter(fn (OrganizationUnit $u) => $assignesIds->contains($u->id))
            ->map(fn (OrganizationUnit $u) => ['id' => $u->id, 'chemin' => $u->cheminComplet($toutes)]);

        return $this->filtrerUnitesOrganisation($unites, $this->recherchePerimetreAssignes);
    }

    private function filtrerUnitesOrganisation($unites, string $recherche)
    {
        $recherche = trim(mb_strtolower($recherche));

        if ($recherche === '') {
            return $unites->values();
        }

        return $unites->filter(fn (array $u) => str_contains(mb_strtolower($u['chemin']), $recherche))->values();
    }

    public function ajouterPerimetre(int $unitId): void
    {
        $this->authorize('gerer', Privilege::class);

        if (! $this->utilisateurEditionId) {
            return;
        }

        $this->utilisateurEnEdition->organizationUnitsPerimetre()->syncWithoutDetaching([$unitId]);

        unset($this->utilisateurEnEdition);
    }

    public function retirerPerimetre(int $unitId): void
    {
        $this->authorize('gerer', Privilege::class);

        if (! $this->utilisateurEditionId) {
            return;
        }

        $this->utilisateurEnEdition->organizationUnitsPerimetre()->detach($unitId);

        unset($this->utilisateurEnEdition);
    }

    public function ajouterSelectionPerimetre(): void
    {
        $this->authorize('gerer', Privilege::class);

        if (! $this->utilisateurEditionId || $this->selectionPerimetreDisponibles === []) {
            return;
        }

        $this->utilisateurEnEdition->organizationUnitsPerimetre()->syncWithoutDetaching($this->selectionPerimetreDisponibles);

        $this->selectionPerimetreDisponibles = [];
        unset($this->utilisateurEnEdition);
    }

    public function retirerSelectionPerimetre(): void
    {
        $this->authorize('gerer', Privilege::class);

        if (! $this->utilisateurEditionId || $this->selectionPerimetreAssignes === []) {
            return;
        }

        $this->utilisateurEnEdition->organizationUnitsPerimetre()->detach($this->selectionPerimetreAssignes);

        $this->selectionPerimetreAssignes = [];
        unset($this->utilisateurEnEdition);
    }

    public function render()
    {
        return view('frontend::userList');
    }
}
