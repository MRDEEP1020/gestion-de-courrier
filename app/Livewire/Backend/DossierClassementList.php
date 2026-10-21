<?php

namespace App\Livewire\Backend;

use App\Models\Courrier;
use App\Models\DossierClassement;
use App\Models\DossierClassementHistorique;
use App\Models\Service;
use App\Models\User;
use App\Notifications\DossierPartageNotification;
use App\Services\DossierClassementService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Module 3/9 — "Dossiers & Archives" (2026-09-21, reconstruit depuis une
// maquette fournie par l'utilisateur). Voir DECISIONS.md "Module 3 —
// dossiers de classement" : privilèges déjà seedés (2026-09-16), ceci est
// le modèle/migration/Policy/UI qui restaient à construire. Décisions
// confirmées avec l'utilisateur avant ce chantier : "Dossier surveillé" du
// menu = simple lien vers la fonctionnalité de scan existante (pas un
// nouveau concept) ; "Archives" = vrai mécanisme automatique (voir
// WorkflowService::archiverAutomatiquement() / ArchiverCourriersTraitesJob) ;
// partage = octroi par utilisateur (deux boîtes, même pattern que
// "Destinataires de transfert" de UserList) ; l'arborescence démarre VIDE
// (aucun dossier fabriqué).
#[Title('Dossiers & Archives')]
class DossierClassementList extends Component
{
    use WithPagination;

    // ===== Nœud sélectionné =====
    #[Url]
    public ?int $dossierId = null;

    // '' = dossier réel ($dossierId) ou racine ("Tous les dossiers") ;
    // 'generaux' = non classés ; 'archives' = statut archive.
    #[Url]
    public string $noeud = '';

    // Raccourci sidebar "Créer un dossier" (?creer=1) — ouvre directement
    // la modale de création à la racine, pas de page séparée.
    #[Url]
    public bool $creer = false;

    // ===== Modale "Créer un dossier" =====
    public ?int $dossierParentPourCreationId = null;

    public string $nomDossier = '';

    public string $descriptionDossier = '';

    public ?int $serviceIdDossier = null;

    public string $referenceLocalisationDossier = '';

    // ===== Modale "Renommer" =====
    public ?int $dossierEnRenommageId = null;

    public string $nomRenommage = '';

    public string $descriptionRenommage = '';

    // ===== Modale "Déplacer" =====
    public ?int $dossierADeplacerId = null;

    public ?int $nouveauParentId = null;

    // ===== Modale "Partager" (deux boîtes, même pattern que
    // UserList::$rechercheDestinataires*/$selectionDestinataires*) =====
    public ?int $dossierAPartagerId = null;

    public string $recherchePartageDisponibles = '';

    public string $recherchePartageAssignes = '';

    public array $selectionPartageDisponibles = [];

    public array $selectionPartageAssignes = [];

    // ===== Modale "Supprimer" =====
    public ?int $dossierASupprimerId = null;

    // ===== Modale "Ajouter des courriers" (2026-09-22, demande explicite de
    // l'utilisateur, "les trois" points d'entrée pour classer un courrier) =====
    public string $rechercheCourriersAAjouter = '';

    public array $courriersAAjouter = [];

    public function mount(): void
    {
        $this->authorize('viewAny', DossierClassement::class);

        // ?noeud=archives (lien de la sidebar) — ignoré sans le privilège.
        if (! $this->peutVoirNoeud($this->noeud)) {
            $this->noeud = '';
        }

        if ($this->creer && Auth::user()->can('create', DossierClassement::class)) {
            $this->ouvrirCreation(null);
        }
    }

    // Nœud virtuel "Archives" gouverné par dossiers_classement.archives
    // (2026-09-23, menus pilotés par privilège) — vérifié côté serveur, pas
    // seulement masqué dans la vue (Règle n°6).
    private function peutVoirNoeud(string $noeud): bool
    {
        return $noeud !== 'archives' || Auth::user()->hasPrivilege('dossiers_classement.archives');
    }

    // ===== Arborescence =====

    // Un seul dossier par utilisateur non-gerer_tout : créateur, responsable,
    // ou partagé explicitement (dossier_classement_user) — jamais un
    // privilège de périmètre générique (voir DossierClassementPolicy::view()).
    #[Computed]
    public function tousLesDossiersAccessibles(): Collection
    {
        $user = Auth::user();

        return DossierClassement::query()
            ->when(! $user->hasPrivilege('dossiers_classement.gerer_tout'), fn ($q) => $q->where(fn ($q) => $q
                ->where('cree_par_id', $user->id)
                ->orWhere('responsable_id', $user->id)
                ->orWhereHas('utilisateursAutorises', fn ($q) => $q->where('users.id', $user->id))))
            ->withCount('courriers')
            ->orderBy('nom')
            ->get();
    }

    // Une seule requête, regroupée en PHP — jamais de requête récursive par
    // nœud affiché (voir DossierClassement::compterDocumentsDescendants()).
    #[Computed]
    public function arbre(): array
    {
        $dossiers = $this->tousLesDossiersAccessibles;
        $parNiveauParent = $dossiers->groupBy('parent_id');

        $construire = function ($parentId) use (&$construire, $parNiveauParent) {
            return ($parNiveauParent->get($parentId) ?? collect())
                ->map(fn ($d) => ['dossier' => $d, 'enfants' => $construire($d->id)])
                ->values()->all();
        };

        return $construire(null);
    }

    public function selectionnerDossier(?int $id): void
    {
        $this->dossierId = $id;
        $this->noeud = '';
        $this->resetPage();
    }

    public function selectionnerNoeud(string $noeud): void
    {
        if (! $this->peutVoirNoeud($noeud)) {
            return;
        }

        $this->dossierId = null;
        $this->noeud = $noeud;
        $this->resetPage();
    }

    // ===== Panneau "Détails du dossier" =====

    #[Computed]
    public function dossierSelectionne(): ?DossierClassement
    {
        if ($this->dossierId === null) {
            return null;
        }

        $dossier = DossierClassement::with(['responsable', 'creePar'])->find($this->dossierId);

        // Règle n°6 — jamais confiance en un ID venu du client (même porté
        // par #[Url]) sans revérifier le droit d'accès côté serveur.
        return ($dossier !== null && Auth::user()->can('view', $dossier)) ? $dossier : null;
    }

    // ===== Contenu du dossier (table centrale) =====

    private function portee(): Builder
    {
        return Courrier::query()->visiblePar(Auth::user());
    }

    #[Computed]
    public function courriersDuNoeud()
    {
        if ($this->dossierId !== null && $this->dossierSelectionne === null) {
            // Sélection invalide/non autorisée — retombe sur "Tous les dossiers".
            $this->dossierId = null;
        }

        return $this->portee()
            ->when($this->noeud === 'generaux', fn ($q) => $q->whereNull('dossier_classement_id'))
            ->when($this->noeud === 'archives', fn ($q) => $q->where('statut', 'archive'))
            ->when($this->noeud === '' && $this->dossierId !== null, fn ($q) => $q->where('dossier_classement_id', $this->dossierId))
            ->with('service')
            ->latest('date_mouvement')
            ->paginate(10);
    }

    #[Computed]
    public function services()
    {
        return Service::query()->where('actif', true)->orderBy('nom')->get(['id', 'nom']);
    }

    // ===== Créer un dossier =====

    public function ouvrirCreation(?int $parentId = null): void
    {
        $parent = $parentId ? DossierClassement::findOrFail($parentId) : null;
        $this->authorize('create', [DossierClassement::class, $parent]);

        $this->reset(['nomDossier', 'descriptionDossier', 'serviceIdDossier', 'referenceLocalisationDossier']);
        $this->dossierParentPourCreationId = $parentId;
        $this->resetValidation();

        Flux::modal('dossier-creation')->show();
    }

    public function creerDossier(): void
    {
        $parent = $this->dossierParentPourCreationId ? DossierClassement::findOrFail($this->dossierParentPourCreationId) : null;
        $this->authorize('create', [DossierClassement::class, $parent]);

        $this->validate([
            'nomDossier' => ['required', 'string', 'max:255'],
            'descriptionDossier' => ['nullable', 'string'],
            'referenceLocalisationDossier' => ['nullable', 'string', 'max:255'],
        ], [], [
            'nomDossier' => __('nom'),
        ]);

        $dossier = DB::transaction(function () use ($parent) {
            $dossier = DossierClassement::create([
                'nom' => $this->nomDossier,
                'description' => $this->descriptionDossier ?: null,
                'parent_id' => $parent?->id,
                'service_id' => $this->serviceIdDossier ?: Auth::user()->service_id,
                'responsable_id' => Auth::id(),
                'cree_par_id' => Auth::id(),
                'reference_localisation_physique' => $this->referenceLocalisationDossier ?: null,
            ]);

            DossierClassementHistorique::create([
                'dossier_classement_id' => $dossier->id,
                'auteur_id' => Auth::id(),
                'action' => 'creation',
                'commentaire' => null,
            ]);

            return $dossier;
        });

        Flux::modal('dossier-creation')->close();
        $this->selectionnerDossier($dossier->id);
        unset($this->tousLesDossiersAccessibles, $this->arbre);
        Flux::toast(variant: 'success', text: __('Dossier créé.'));
    }

    // ===== Renommer =====

    public function ouvrirRenommage(int $id): void
    {
        $dossier = DossierClassement::findOrFail($id);
        $this->authorize('update', $dossier);

        $this->dossierEnRenommageId = $id;
        $this->nomRenommage = $dossier->nom;
        $this->descriptionRenommage = $dossier->description ?? '';
        $this->resetValidation();

        Flux::modal('dossier-renommage')->show();
    }

    public function renommerDossier(): void
    {
        $dossier = DossierClassement::findOrFail($this->dossierEnRenommageId);
        $this->authorize('update', $dossier);

        $this->validate([
            'nomRenommage' => ['required', 'string', 'max:255'],
            'descriptionRenommage' => ['nullable', 'string'],
        ], [], [
            'nomRenommage' => __('nom'),
        ]);

        $dossier->update([
            'nom' => $this->nomRenommage,
            'description' => $this->descriptionRenommage ?: null,
        ]);

        DossierClassementHistorique::create([
            'dossier_classement_id' => $dossier->id,
            'auteur_id' => Auth::id(),
            'action' => 'renommage',
            'commentaire' => $dossier->nom,
        ]);

        Flux::modal('dossier-renommage')->close();
        unset($this->tousLesDossiersAccessibles, $this->arbre, $this->dossierSelectionne);
        Flux::toast(text: __('Dossier renommé.'));
    }

    // ===== Déplacer =====

    public function ouvrirDeplacement(int $id): void
    {
        $dossier = DossierClassement::findOrFail($id);
        $this->authorize('update', $dossier);

        $this->dossierADeplacerId = $id;
        $this->nouveauParentId = $dossier->parent_id;

        Flux::modal('dossier-deplacement')->show();
    }

    public function deplacerDossier(): void
    {
        $dossier = DossierClassement::findOrFail($this->dossierADeplacerId);
        $nouveauParent = $this->nouveauParentId ? DossierClassement::findOrFail($this->nouveauParentId) : null;

        $this->authorize('deplacer', [$dossier, $nouveauParent]);

        if ($nouveauParent && ($nouveauParent->id === $dossier->id
            || $dossier->estDescendantDe($nouveauParent->id, $this->tousLesDossiersAccessibles))) {
            Flux::toast(variant: 'danger', text: __('Déplacement impossible : ce dossier ne peut pas devenir son propre sous-dossier.'));

            return;
        }

        $dossier->update(['parent_id' => $nouveauParent?->id]);

        DossierClassementHistorique::create([
            'dossier_classement_id' => $dossier->id,
            'auteur_id' => Auth::id(),
            'action' => 'deplacement',
            'commentaire' => $nouveauParent?->nom ?? __('Racine'),
        ]);

        Flux::modal('dossier-deplacement')->close();
        unset($this->tousLesDossiersAccessibles, $this->arbre, $this->dossierSelectionne);
        Flux::toast(text: __('Dossier déplacé.'));
    }

    // ===== Partager (deux boîtes — même forme que UserList::destinataires*) =====

    // PENTEST (2026-09-24) — $dossierAPartagerId est une propriété publique
    // SANS #[Locked] : Livewire accepte de la modifier directement depuis
    // une requête cliente forgée. La modale "Partager" (partageDisponibles/
    // partageAssignes ci-dessous) est rendue SANS condition dans la vue —
    // sans revérifier 'partager' ICI, comme dossierSelectionne() le fait
    // déjà juste au-dessus pour 'view', ce panneau pouvait faire fuir noms
    // et emails des bénéficiaires d'un dossier hors du périmètre de
    // l'utilisateur au prochain rendu, sans jamais passer par ouvrirPartage().
    #[Computed]
    public function dossierAPartager(): ?DossierClassement
    {
        if ($this->dossierAPartagerId === null) {
            return null;
        }

        $dossier = DossierClassement::with('utilisateursAutorises:id,name,email')->find($this->dossierAPartagerId);

        return ($dossier !== null && Auth::user()->can('partager', $dossier)) ? $dossier : null;
    }

    // PENTEST (2026-09-24) — trouvé en corrigeant dossierAPartager()
    // ci-dessus : sans dossier sélectionné/autorisé (dossierAPartagerId
    // null par défaut à CHAQUE chargement de page, avant même d'ouvrir la
    // modale "Partager"), le ->when($dossier, ...) ci-dessous ne
    // s'appliquait tout simplement pas — cette méthode listait alors TOUS
    // les utilisateurs de l'organisation (id/nom/email) sans aucun filtre.
    // La modale étant rendue sans condition dans la vue (voir
    // dossierAPartager()), ça faisait fuir le nom et l'email de CHAQUE
    // utilisateur dans le HTML de CHAQUE chargement de "Dossiers &
    // Archives", pour quiconque a juste dossiers_classement.voir (accordé
    // largement : Agent/DGA/Responsable de service/Collaborateur), même
    // sans le moindre droit de partage. partageAssignes() ci-dessous avait
    // déjà le bon garde-fou (retourne collect() vide sans dossier) ; celui-
    // ci ne l'avait pas.
    #[Computed]
    public function partageDisponibles()
    {
        $dossier = $this->dossierAPartager;

        if ($dossier === null) {
            return collect();
        }

        $utilisateurs = User::query()->orderBy('name')->get(['id', 'name', 'email'])
            ->reject(fn (User $candidat) => $candidat->id === $dossier->cree_par_id)
            ->whereNotIn('id', $dossier->utilisateursAutorises->pluck('id'));

        return $this->filtrerUtilisateurs($utilisateurs, $this->recherchePartageDisponibles)->values();
    }

    #[Computed]
    public function partageAssignes()
    {
        $dossier = $this->dossierAPartager;

        if (! $dossier) {
            return collect();
        }

        $utilisateurs = User::query()->whereIn('id', $dossier->utilisateursAutorises->pluck('id'))->orderBy('name')->get(['id', 'name', 'email']);

        return $this->filtrerUtilisateurs($utilisateurs, $this->recherchePartageAssignes)->values();
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

    public function ouvrirPartage(int $id): void
    {
        $dossier = DossierClassement::findOrFail($id);
        $this->authorize('partager', $dossier);

        $this->dossierAPartagerId = $id;
        $this->reset(['recherchePartageDisponibles', 'recherchePartageAssignes', 'selectionPartageDisponibles', 'selectionPartageAssignes']);

        Flux::modal('dossier-partage')->show();
    }

    // dossierAPartager() revérifie déjà 'partager' (voir son commentaire
    // pentest ci-dessus) — un retour null ici signifie donc "non trouvé OU
    // non autorisé", jamais fait confiance implicitement.
    public function ajouterPartage(int $userId): void
    {
        $dossier = $this->dossierAPartager;

        if ($dossier === null) {
            return;
        }

        $dossier->utilisateursAutorises()->syncWithoutDetaching([$userId]);

        DossierClassementHistorique::create([
            'dossier_classement_id' => $dossier->id,
            'auteur_id' => Auth::id(),
            'action' => 'partage_ajoute',
            'commentaire' => User::find($userId)?->name,
        ]);

        // 2026-10-09 (audit "list me all") — l'utilisateur avec qui le
        // dossier est partagé ne l'apprenait jusqu'ici jamais.
        User::find($userId)?->notify(new DossierPartageNotification($dossier, 'partage', envoyePar: Auth::user()));

        unset($this->dossierAPartager);
    }

    public function retirerPartage(int $userId): void
    {
        $dossier = $this->dossierAPartager;

        if ($dossier === null) {
            return;
        }

        $dossier->utilisateursAutorises()->detach($userId);

        DossierClassementHistorique::create([
            'dossier_classement_id' => $dossier->id,
            'auteur_id' => Auth::id(),
            'action' => 'partage_retire',
            'commentaire' => User::find($userId)?->name,
        ]);

        User::find($userId)?->notify(new DossierPartageNotification($dossier, 'revoque', envoyePar: Auth::user()));

        unset($this->dossierAPartager);
    }

    public function ajouterSelectionPartage(): void
    {
        $dossier = $this->dossierAPartager;

        if ($dossier === null || $this->selectionPartageDisponibles === []) {
            return;
        }

        $dossier->utilisateursAutorises()->syncWithoutDetaching($this->selectionPartageDisponibles);
        Notification::send(User::query()->whereIn('id', $this->selectionPartageDisponibles)->get(), new DossierPartageNotification($dossier, 'partage', envoyePar: Auth::user()));
        $this->selectionPartageDisponibles = [];
        unset($this->dossierAPartager);
    }

    public function retirerSelectionPartage(): void
    {
        $dossier = $this->dossierAPartager;

        if ($dossier === null || $this->selectionPartageAssignes === []) {
            return;
        }

        $dossier->utilisateursAutorises()->detach($this->selectionPartageAssignes);
        Notification::send(User::query()->whereIn('id', $this->selectionPartageAssignes)->get(), new DossierPartageNotification($dossier, 'revoque', envoyePar: Auth::user()));
        $this->selectionPartageAssignes = [];
        unset($this->dossierAPartager);
    }

    // ===== Ajouter des courriers existants au dossier ouvert =====

    // Vide si la recherche est vide — évite de charger toute la base dans un
    // picker (même principe que les listes "deux boîtes" de la modale
    // Partager, mais ici la source potentielle — TOUS les courriers visibles
    // — est trop grande pour être affichée sans filtre). Périmètre identique
    // à courriersDuNoeud (Courrier::scopeVisiblePar()), exclut ceux déjà
    // présents dans CE dossier.
    // Paginée sous un nom distinct de la table centrale ("page") — les deux
    // peuvent être affichées en même temps (modale par-dessus la table),
    // jamais fait partager le même état de pagination.
    #[Computed]
    public function courriersDisponiblesPourAjout()
    {
        if (trim($this->rechercheCourriersAAjouter) === '' || $this->dossierSelectionne === null) {
            return collect();
        }

        $recherche = $this->rechercheCourriersAAjouter;

        return $this->portee()
            ->where(fn ($q) => $q->whereNull('dossier_classement_id')->orWhere('dossier_classement_id', '!=', $this->dossierId))
            ->where(fn ($q) => $q
                ->where('numero_reference', 'like', "%{$recherche}%")
                ->orWhere('objet', 'like', "%{$recherche}%")
                ->orWhere('expediteur_nom', 'like', "%{$recherche}%"))
            ->orderByDesc('date_mouvement')
            ->paginate(10, ['*'], 'courriersAAjouterPage');
    }

    public function ouvrirAjoutCourriers(): void
    {
        // courriers.classer (2026-09-23) — chaque ajout reste revérifié
        // courrier par courrier via can('classer') dans ajouterCourriersSelection().
        abort_unless(Auth::user()->hasPrivilege('courriers.classer'), 403);

        if ($this->dossierSelectionne === null) {
            return;
        }

        $this->reset('rechercheCourriersAAjouter', 'courriersAAjouter');
        $this->resetPage('courriersAAjouterPage');
        Flux::modal('dossier-ajout-courriers')->show();
    }

    // Vidé à chaque changement de recherche pour ne jamais rester bloqué sur
    // une page devenue vide après un filtrage plus restrictif.
    public function updated($nom): void
    {
        if ($nom === 'rechercheCourriersAAjouter') {
            $this->resetPage('courriersAAjouterPage');
        }
    }

    public function ajouterCourriersSelection(DossierClassementService $service): void
    {
        $dossier = $this->dossierSelectionne;

        if ($dossier === null || $this->courriersAAjouter === []) {
            return;
        }

        $utilisateur = Auth::user();
        $ajoutes = 0;

        foreach (Courrier::query()->whereIn('id', $this->courriersAAjouter)->get() as $courrier) {
            if (! $utilisateur->can('classer', $courrier)) {
                continue;
            }

            $service->classer($courrier, $dossier, $utilisateur);
            $ajoutes++;
        }

        $this->reset('rechercheCourriersAAjouter', 'courriersAAjouter');
        unset($this->courriersDuNoeud, $this->tousLesDossiersAccessibles, $this->dossierSelectionne, $this->courriersDisponiblesPourAjout);

        Flux::modal('dossier-ajout-courriers')->close();
        Flux::toast(
            variant: $ajoutes > 0 ? 'success' : 'warning',
            text: $ajoutes > 0 ? __(':n courrier(s) ajouté(s) au dossier.', ['n' => $ajoutes]) : __('Aucun courrier sélectionné n\'a pu être ajouté.'),
        );
    }

    // Action par ligne dans la table centrale — retire un courrier du
    // dossier actuellement ouvert. Revérifie classer() sur le courrier ET
    // view() sur son dossier actuel (Règle n°6, jamais fait confiance à
    // l'ID de ligne posté depuis le client).
    public function retirerCourrierDuDossier(int $courrierId, DossierClassementService $service): void
    {
        $courrier = Courrier::findOrFail($courrierId);
        $utilisateur = Auth::user();

        if (! $utilisateur->can('classer', $courrier)) {
            return;
        }

        if ($courrier->dossier_classement_id !== null && $utilisateur->cannot('view', $courrier->dossierClassement)) {
            return;
        }

        $service->retirer($courrier, $utilisateur);

        unset($this->courriersDuNoeud, $this->tousLesDossiersAccessibles, $this->dossierSelectionne);

        Flux::toast(text: __('Courrier retiré du dossier.'));
    }

    // ===== Supprimer (bloqué si non vide) =====

    public function ouvrirSuppression(int $id): void
    {
        $dossier = DossierClassement::findOrFail($id);
        $this->authorize('delete', $dossier);

        $this->dossierASupprimerId = $id;
        Flux::modal('dossier-suppression')->show();
    }

    public function supprimerDossier(): void
    {
        $dossier = DossierClassement::findOrFail($this->dossierASupprimerId);
        $this->authorize('delete', $dossier);

        if ($dossier->enfants()->exists() || $dossier->courriers()->exists()) {
            Flux::toast(variant: 'danger', text: __('Ce dossier contient des sous-dossiers ou des courriers — déplacez-les avant de le supprimer.'));

            return;
        }

        $dossier->delete(); // soft delete — Règle n°5

        Flux::modal('dossier-suppression')->close();

        if ($this->dossierId === $dossier->id) {
            $this->dossierId = null;
        }

        unset($this->tousLesDossiersAccessibles, $this->arbre);
        Flux::toast(text: __('Dossier supprimé.'));
    }

    public function render()
    {
        return view('frontend::dossierClassementList');
    }
}
