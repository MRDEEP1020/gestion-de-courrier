<?php

namespace App\Livewire\Backend;

use App\Models\Privilege;
use App\Models\Profil;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// Module 9 — page "Profils" : créer des profils et leur assigner des
// privilèges. 2026-09-23, demande explicite de l'utilisateur ("make profile
// page as userlist") : même présentation que "Utilisateurs & Accès" —
// recherche, tableau, menu d'actions par ligne et la même modale "Gestion des
// permissions" groupée par module — à la place de l'ancien sélecteur +
// "deux boîtes" (voir DECISIONS.md "Page Profils alignée sur Utilisateurs &
// Accès").
#[Title('Profils')]
class ProfilList extends Component
{
    public string $recherche = '';

    public string $nouveauProfilNom = '';

    // Profil dont la modale "Gestion des permissions" est ouverte (id brut,
    // Règle n°2 ; revérifié à chaque action).
    public ?int $profilEnEditionId = null;

    public string $modulePermissionSelectionne = 'courriers';

    public string $recherchePermission = '';

    public function mount(): void
    {
        $this->authorize('gerer', Privilege::class);
    }

    // Liste courte (une poignée de profils) : pas de pagination nécessaire.
    #[Computed]
    public function profils()
    {
        $recherche = trim($this->recherche);

        return Profil::query()
            ->withCount('users')
            ->with('privileges:id,type')
            ->when($recherche !== '', fn ($q) => $q->where('nom', 'like', "%{$recherche}%"))
            ->orderBy('nom')
            ->get();
    }

    // ===== Nouveau profil =====

    public function ouvrirCreation(): void
    {
        $this->authorize('creerProfil', Privilege::class);

        $this->reset('nouveauProfilNom');
        $this->resetValidation();

        Flux::modal('profil-form')->show();
    }

    public function creerProfil(): void
    {
        // Privilège dédié profils.creer (2026-09-23), cumulé avec privileges.gerer.
        $this->authorize('creerProfil', Privilege::class);

        $data = $this->validate([
            'nouveauProfilNom' => ['required', 'string', 'max:100', Rule::unique('profils', 'nom')],
        ], [], ['nouveauProfilNom' => __('nom du profil')]);

        $profil = Profil::create(['nom' => $data['nouveauProfilNom']]);

        $this->reset('nouveauProfilNom');
        unset($this->profils);

        Flux::modal('profil-form')->close();
        Flux::toast(variant: 'success', text: __('Profil créé.'));

        // Enchaîne directement sur ses permissions (un profil vide n'a aucun droit).
        $this->ouvrirPermissions($profil->id);
    }

    // ===== Modale "Gestion des permissions" — même principe que UserList =====

    public function ouvrirPermissions(int $id): void
    {
        $this->authorize('gerer', Privilege::class);

        $this->profilEnEditionId = Profil::findOrFail($id)->id;
        $this->modulePermissionSelectionne = 'courriers';
        $this->recherchePermission = '';

        Flux::modal('profil-permissions')->show();
    }

    #[Computed]
    public function profilEnEdition(): ?Profil
    {
        return $this->profilEnEditionId
            ? Profil::withCount('users')->with('privileges:id')->find($this->profilEnEditionId)
            : null;
    }

    #[Computed]
    public function permissionsCatalogue()
    {
        return Privilege::orderBy('nom')->get();
    }

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

    #[Computed]
    public function resumePermissions(): array
    {
        $profil = $this->profilEnEdition;

        if (! $profil) {
            return ['modulesActifs' => 0, 'modulesTotal' => 0, 'lecture' => 0, 'ecriture' => 0, 'administratif' => 0];
        }

        $assignes = $this->permissionsCatalogue->whereIn('id', $profil->privileges->pluck('id'));

        return [
            'modulesActifs' => $assignes->map(fn (Privilege $p) => $p->moduleCle())->unique()->count(),
            'modulesTotal' => $this->modules->count(),
            'lecture' => $assignes->where('type', 'lecture')->count(),
            'ecriture' => $assignes->where('type', 'ecriture')->count(),
            'administratif' => $assignes->where('type', 'administratif')->count(),
        ];
    }

    public function basculerPermission(int $privilegeId): void
    {
        $profil = $this->profilAutorise();

        if ($profil->privileges->pluck('id')->contains($privilegeId)) {
            $profil->privileges()->detach($privilegeId);
        } else {
            $profil->privileges()->syncWithoutDetaching([Privilege::findOrFail($privilegeId)->id]);
        }

        unset($this->profilEnEdition, $this->profils);
    }

    // "Tout sélectionner"/"Tout désélectionner" — scopés au module affiché,
    // jamais tout le catalogue d'un coup (même principe que UserList).
    public function toutSelectionnerModule(): void
    {
        $this->profilAutorise()->privileges()->syncWithoutDetaching($this->permissionsDuModule->pluck('id'));

        unset($this->profilEnEdition, $this->profils);
    }

    public function toutDeselectionnerModule(): void
    {
        $this->profilAutorise()->privileges()->detach($this->permissionsDuModule->pluck('id'));

        unset($this->profilEnEdition, $this->profils);
    }

    // Règle n°6 — droit revérifié à chaque action ; profilEnEditionId est
    // une propriété publique, donc toujours relu depuis la base.
    private function profilAutorise(): Profil
    {
        $this->authorize('gerer', Privilege::class);

        return Profil::with('privileges:id')->findOrFail($this->profilEnEditionId);
    }

    public function render()
    {
        return view('frontend::profilList');
    }
}
