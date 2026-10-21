<?php

namespace App\Livewire\Backend;

use App\Models\Courrier;
use App\Models\CourrierBrouillon;
use App\Services\BrouillonScanService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

// Module 1/2 — "scan d'abord" (voir DECISIONS.md "Flux scan-first") : point
// d'entrée SANS courrier existant. Mêmes règles de validation qu'un scan
// classique (ScanForm, laissé inchangé pour re-numériser / usage manuel).
#[Title('Numériser un nouveau courrier')]
class ScanPremier extends Component
{
    use WithFileUploads, WithPagination;

    // Exception à la Règle n°2 : mécanisme d'upload natif de Livewire.
    public $document = null;

    // Recherche sur le tableau "Documents importés depuis le dossier
    // surveillé" (2026-09-22, demande explicite de l'utilisateur — même
    // gabarit que UserList : carte de recherche au-dessus de la table).
    public string $rechercheImports = '';

    // Règle n°6 — 2026-09-23 : la page elle-même est refusée sans
    // courriers.numeriser (auparavant seule l'action d'upload l'était, si
    // bien qu'un DGA/Responsable/Collaborateur voyait une page dont le
    // bouton échouait systématiquement). Privilège distinct de
    // courriers.creer depuis le même jour (menus pilotés par privilège).
    // Même raison que RegistrationForm::$agentId : placeholder {agentId} de
    // l'écouteur echo-private ci-dessous, jamais null.
    public int $agentId = 0;

    public function mount(): void
    {
        $this->authorize('numeriser', Courrier::class);

        $this->agentId = (int) Auth::id();
    }

    // 2026-09-24 — fin d'OCR d'un de SES brouillons (BrouillonOcrTermine,
    // canal privé par agent) : le statut OCR du tableau se met à jour sans
    // recharger la page (Règle n°2 : événement diffusé, jamais wire:poll).
    #[On('echo-private:App.Models.User.{agentId},.brouillon.ocr.termine')]
    public function brouillonOcrTermine(): void
    {
        unset($this->importsDossierSurveille);
    }

    // Vidé à chaque changement de recherche pour ne jamais rester bloqué sur
    // une page devenue vide après un filtrage plus restrictif (même
    // principe que CourrierList::updated()).
    public function updated($nom): void
    {
        if ($nom === 'rechercheImports') {
            $this->resetPage('importsPage');
        }
    }

    public function numeriser(): void
    {
        $this->authorize('numeriser', Courrier::class);
        $this->validate(['document' => BrouillonScanService::reglesValidation(ScanForm::resolutionMinimale())]);

        $brouillon = app(BrouillonScanService::class)->creer($this->document);

        // Un opérateur de scan sans courriers.creer reste sur cette page :
        // le brouillon sera repris par un agent qui peut enregistrer.
        if (! Auth::user()->can('create', Courrier::class)) {
            $this->reset('document');
            Flux::toast(variant: 'success', text: __('Document envoyé, traitement en cours. Il sera enregistré par un agent.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Document envoyé, traitement en cours. Vous pouvez enchaîner un autre scan ou continuer l\'enregistrement.'));

        $this->redirect(route('courriers.nouveau', ['brouillonId' => $brouillon->id]), navigate: true);
    }

    // Point d'entrée du dossier surveillé (resources/js/scan-watcher.js) :
    // upload natif via input caché puis $wire.numeriserAutomatique() (voir
    // DECISIONS.md — appelé via window.Livewire.find(id), pas via
    // this.$wire directement). Pas de toast ni de redirection ici — un
    // scan à la fois arrivant en rafale ne doit ni spammer de toasts ni
    // recharger la page à chaque fichier (tout l'intérêt de la
    // fonctionnalité) ; l'état visible est géré côté client par le journal
    // d'activité Alpine. #[Renderless] : ce composant reste monté longtemps
    // pendant la surveillance, un re-render à chaque fichier importé est
    // inutile. Même méthode dupliquée sur RegistrationForm (voir
    // DECISIONS.md "Watcher automatique sur le formulaire
    // d'enregistrement") — le partage passe par BrouillonScanService, pas
    // par une dépendance entre les deux composants Livewire.
    //
    // 2026-09-24 — PLUS #[Renderless] (retour de l'utilisateur : "why
    // doesn't it take automatically without i actualising it") : le tableau
    // "Documents importés" ci-dessous ne se mettait jamais à jour après un
    // import automatique. Même choix que RegistrationForm::numeriserAutomatique(),
    // déjà confirmé en navigateur réel : le composant Alpine garde son état
    // au re-rendu (morph), et ne réaccède jamais à this.$wire (Livewire.find(id)).
    public function numeriserAutomatique(): array
    {
        $this->authorize('numeriser', Courrier::class);
        $this->validate(['document' => BrouillonScanService::reglesValidation(ScanForm::resolutionMinimale())]);

        $brouillon = app(BrouillonScanService::class)->creer($this->document, CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE);

        $this->reset('document');
        unset($this->importsDossierSurveille);

        return [
            'brouillonId' => $brouillon->id,
            'nomOriginal' => $brouillon->nom_original,
        ];
    }

    // 2026-09-22 — table admin persistante des documents importés
    // spécifiquement via le dossier surveillé (demande explicite de
    // l'utilisateur, distincte de RegistrationForm::brouillonsEnAttente()
    // qui liste TOUS les brouillons en attente, quelle que soit leur
    // origine). PAS de whereNull('finalise_le') : c'est un historique
    // complet (importé, en attente OU déjà enregistré), pas une liste "à
    // traiter".
    //
    // 2026-10-08 — ne filtre PLUS par cree_par_id/brouillons.utiliser_tout
    // (retour utilisateur, "not the whole thing the admin can see" — voir
    // CourrierBrouillonPolicy::utiliser() pour le raisonnement complet) :
    // cette liste est déjà entièrement restreinte à source=dossier_surveille,
    // une ressource du poste partagé plutôt que personnelle à qui était
    // connecté au moment précis de l'import — tout agent qui atteint cette
    // page (déjà gardée par courriers.numeriser) voit donc tous ces imports.
    #[Computed]
    public function importsDossierSurveille()
    {
        return CourrierBrouillon::query()
            ->where('source', CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE)
            ->with('creePar:id,name')
            ->when($this->rechercheImports !== '', fn ($q) => $q->where('nom_original', 'like', "%{$this->rechercheImports}%"))
            ->latest()
            ->paginate(10, ['id', 'nom_original', 'ocr_statut', 'created_at', 'cree_par_id', 'finalise_le'], 'importsPage');
    }

    public function render()
    {
        return view('frontend::scanPremier');
    }
}
