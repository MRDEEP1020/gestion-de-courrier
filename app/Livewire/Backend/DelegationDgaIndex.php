<?php

namespace App\Livewire\Backend;

use App\Models\DelegationDga;
use App\Models\User;
use App\Notifications\DelegationDgaNotification;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

// Module 1/4 — Administration > Délégation DGA (2026-10-06, entretien
// terrain réceptionniste, voir DECISIONS.md "Délégation DGA/ADJ absents").
// Toggle manuel uniquement : un Administrateur ou un DGA actif active une
// délégation nominative (delegant DGA/ADJ → delegataire, typiquement RH),
// avec un motif et une fin optionnels — jamais de détection automatique
// d'absence. Même gabarit que DossierSurveille (page d'administration à
// privilège unique, pas de Policy dédiée : il n'y a pas de "modèle"
// individuel à autoriser, juste une action globale).
#[Title('Délégation DGA')]
class DelegationDgaIndex extends Component
{
    use WithPagination;

    public ?int $delegantId = null;

    public ?int $delegataireId = null;

    public string $motif = '';

    public string $finLe = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->hasPrivilege('administration.delegation_dga'), 403);
    }

    // Candidats "delegant" : uniquement les utilisateurs qui ont réellement
    // le privilège à déléguer (DGA/ADJ) — une délégation venant d'un
    // utilisateur sans ce privilège n'aurait aucun effet, inutile de le proposer.
    #[Computed]
    public function delegantsPotentiels()
    {
        return User::query()
            ->where(function ($q) {
                $q->whereHas('profil.privileges', fn ($q) => $q->where('cle', 'courriers.dga_valider_service'))
                    ->orWhereHas('privilegesDirectes', fn ($q) => $q->where('cle', 'courriers.dga_valider_service'));
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    // Délégataire : tout utilisateur (même choix libre que les autres
    // sélecteurs "Responsable" de l'application — voir OrganisationIndex).
    #[Computed]
    public function delegatairesPotentiels()
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function delegationsActives()
    {
        return DelegationDga::query()
            ->actives()
            ->with(['delegant:id,name', 'delegataire:id,name', 'activePar:id,name'])
            ->orderByDesc('debut_le')
            ->get();
    }

    // Règle n°3 — historique paginé, peut grossir au fil des allers-retours
    // d'absence contrairement à la liste des délégations actives ci-dessus.
    #[Computed]
    public function historique()
    {
        return DelegationDga::query()
            ->where('actif', false)
            ->with(['delegant:id,name', 'delegataire:id,name', 'activePar:id,name', 'desactivePar:id,name'])
            ->orderByDesc('desactive_le')
            ->paginate(10);
    }

    public function activerDelegation(): void
    {
        $data = $this->validate([
            'delegantId' => ['required', 'integer', 'exists:users,id', 'different:delegataireId'],
            'delegataireId' => ['required', 'integer', 'exists:users,id'],
            'motif' => ['nullable', 'string', 'max:255'],
            'finLe' => ['nullable', 'date', 'after:now'],
        ]);

        if (! in_array($data['delegantId'], $this->delegantsPotentiels->pluck('id')->all(), true)) {
            $this->addError('delegantId', __('Cet utilisateur n\'a pas le privilège de validation DGA/ADJ.'));

            return;
        }

        $delegation = DelegationDga::create([
            'delegant_id' => $data['delegantId'],
            'delegataire_id' => $data['delegataireId'],
            'motif' => $data['motif'] ?: null,
            'debut_le' => now(),
            'fin_le' => $data['finLe'] ?: null,
            'actif' => true,
            'active_par_id' => Auth::id(),
        ]);

        // 2026-10-09 (audit "list me all") — le délégataire ne savait
        // jusqu'ici jamais qu'il avait gagné ce pouvoir temporaire.
        $delegation->delegataire?->notify(new DelegationDgaNotification($delegation, 'activee', envoyePar: Auth::user()));

        $this->reset(['delegantId', 'delegataireId', 'motif', 'finLe']);
        unset($this->delegationsActives);
        $this->toast(__('Délégation activée.'));
    }

    public function desactiverDelegation(int $id): void
    {
        $delegation = DelegationDga::where('actif', true)->findOrFail($id);

        $delegation->update([
            'actif' => false,
            'desactive_par_id' => Auth::id(),
            'desactive_le' => now(),
        ]);

        $delegation->delegataire?->notify(new DelegationDgaNotification($delegation, 'desactivee', envoyePar: Auth::user()));

        unset($this->delegationsActives, $this->historique);
        $this->toast(__('Délégation désactivée.'));
    }

    private function toast(string $texte): void
    {
        Flux::toast(variant: 'success', text: $texte);
    }

    public function render()
    {
        return view('frontend::delegationDgaIndex');
    }
}
