<?php

namespace App\Livewire\Backend;

use App\Jobs\ReanalyserCourriersJob;
use App\Models\RegleClassement;
use App\Models\Service;
use App\Services\ClassificationService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Règles de classement')]
class RegleList extends Component
{
    // Module 3 — "les règles de classement automatique doivent être paramétrables
    // par un administrateur, sans intervention développeur". Propriétés
    // primitives uniquement (Règle n°2) ; la liste est rechargée via #[Computed].

    public ?int $regleId = null;

    public string $nom = '';

    public string $motsCles = '';

    public array $champs = [];

    public string $typeDocumentPropose = '';

    public ?int $serviceProposeId = null;

    public string $tags = '';

    public int $priorite = 100;

    public bool $actif = true;

    public function mount(): void
    {
        $this->authorize('viewAny', RegleClassement::class);
    }

    #[Computed]
    public function regles()
    {
        return RegleClassement::with('servicePropose')->orderBy('priorite')->orderBy('id')->get();
    }

    #[Computed]
    public function services()
    {
        return Service::query()->where('actif', true)->orderBy('nom')->get(['id', 'nom']);
    }

    // Courriers sans décision de classement (index sur classement_statut — Règle n°3).
    // 2026-09-23 — "each card should be a permission" : ce compteur n'existe
    // que pour justifier le bouton "Réanalyser" (regles_classement.reanalyser,
    // même privilège) — jamais calculé pour qui ne peut de toute façon pas
    // agir dessus, même principe que les cartes du tableau de bord.
    #[Computed]
    public function courriersAReanalyser(): ?int
    {
        if (! Auth::user()->hasPrivilege('regles_classement.reanalyser')) {
            return null;
        }

        return ReanalyserCourriersJob::cibles()->count();
    }

    // Une règle nouvelle ou modifiée ne s'applique pas rétroactivement toute
    // seule : l'administrateur relance explicitement l'analyse des courriers
    // existants. Tout se passe en Job (Règle n°1).
    public function reanalyser(): void
    {
        // Privilège dédié regles_classement.reanalyser (2026-09-23).
        $this->authorize('reanalyser', RegleClassement::class);

        $nombre = $this->courriersAReanalyser;

        ReanalyserCourriersJob::dispatch()->onQueue('indexation');

        Flux::toast(
            variant: 'success',
            text: __('Réanalyse lancée pour :n courrier(s) — les fiches se mettront à jour au fil du traitement.', ['n' => $nombre]),
        );
    }

    public function nouvelle(): void
    {
        $this->authorize('create', RegleClassement::class);

        $this->reset('regleId', 'nom', 'motsCles', 'champs', 'typeDocumentPropose', 'serviceProposeId', 'tags', 'priorite', 'actif');
        $this->resetValidation();
    }

    public function modifier(int $id): void
    {
        $regle = RegleClassement::findOrFail($id);

        $this->authorize('update', $regle);

        $this->regleId = $regle->id;
        $this->nom = $regle->nom;
        $this->motsCles = implode(', ', $regle->mots_cles ?? []);
        $this->champs = $regle->champs ?? [];
        $this->typeDocumentPropose = (string) $regle->type_document_propose;
        $this->serviceProposeId = $regle->service_propose_id;
        $this->tags = implode(', ', $regle->tags ?? []);
        $this->priorite = $regle->priorite;
        $this->actif = $regle->actif;
        $this->resetValidation();
    }

    public function enregistrer(): void
    {
        $regle = $this->regleId ? RegleClassement::findOrFail($this->regleId) : null;

        $this->authorize($regle ? 'update' : 'create', $regle ?? RegleClassement::class);

        $data = $this->validate([
            'nom' => ['required', 'string', 'max:100'],
            'motsCles' => ['required', 'string', 'max:1000'],
            'champs' => ['array'],
            'champs.*' => [Rule::in(RegleClassement::CHAMPS)],
            'typeDocumentPropose' => ['nullable', 'string', 'max:255'],
            'serviceProposeId' => ['nullable', 'integer', 'exists:services,id'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'priorite' => ['required', 'integer', 'min:1', 'max:1000'],
            'actif' => ['boolean'],
        ]);

        $motsCles = self::liste($data['motsCles']);

        if ($motsCles === []) {
            $this->addError('motsCles', __('Indiquez au moins un mot-clé.'));

            return;
        }

        $tags = self::liste($data['tags'] ?? '');

        if (blank($data['typeDocumentPropose']) && empty($data['serviceProposeId']) && $tags === []) {
            $this->addError('typeDocumentPropose', __('La règle doit proposer au moins un type, un service ou des tags.'));

            return;
        }

        foreach ($tags as $tag) {
            if (mb_strlen($tag) > ClassificationService::LONGUEUR_MAX_MOT_CLE) {
                $this->addError('tags', __('Un tag ne peut pas dépasser :n caractères.', ['n' => ClassificationService::LONGUEUR_MAX_MOT_CLE]));

                return;
            }
        }

        $attributs = [
            'nom' => $data['nom'],
            'mots_cles' => $motsCles,
            'champs' => $data['champs'] === [] ? null : array_values($data['champs']),
            'type_document_propose' => $data['typeDocumentPropose'] ?: null,
            'service_propose_id' => $data['serviceProposeId'] ?: null,
            'tags' => $tags ?: null,
            'priorite' => $data['priorite'],
            'actif' => $data['actif'],
        ];

        $regle ? $regle->update($attributs) : RegleClassement::create($attributs);

        unset($this->regles);
        $this->nouvelle();

        Flux::toast(
            variant: 'success',
            text: __('Règle enregistrée — elle s\'applique aux prochains courriers ; utilisez « Réanalyser » pour les courriers existants.'),
        );
    }

    public function basculer(int $id): void
    {
        $regle = RegleClassement::findOrFail($id);

        $this->authorize('update', $regle);

        $regle->update(['actif' => ! $regle->actif]);

        unset($this->regles);
    }

    public function supprimer(int $id): void
    {
        $regle = RegleClassement::findOrFail($id);

        $this->authorize('delete', $regle);

        $regle->delete();

        if ($this->regleId === $id) {
            $this->nouvelle();
        }

        unset($this->regles);

        Flux::toast(variant: 'success', text: __('Règle supprimée.'));
    }

    /** "a, b ,c" → ['a', 'b', 'c'] sans doublons ni vides. */
    public static function liste(string $valeur): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $valeur)))));
    }

    public function render()
    {
        return view('frontend::regleList');
    }
}
