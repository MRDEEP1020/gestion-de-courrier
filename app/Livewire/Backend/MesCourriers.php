<?php

namespace App\Livewire\Backend;

use App\Concerns\ChoixServiceCascade;
use App\Models\Courrier;
use App\Models\Service;
use App\Models\TransfertService;
use App\Services\WorkflowService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

// Module 1/4 — page dédiée pour la réceptionniste (demande explicite de
// l'utilisateur, 2026-09-15 : "new page for receptionist") : ses courriers
// ENTRANT enregistrés, par étape du transfert. 2026-10-23 (voir DECISIONS.md
// "Transfert direct au service et acceptation") — plus de DGA : elle les
// transfère directement à un service (un ou plusieurs à la fois), le service
// les accepte ou les refuse, puis elle marque l'original papier comme remis.
// Distincte de `CourrierList` (recherche générale multi-critères).
#[Title('Mes courriers')]
class MesCourriers extends Component
{
    use ChoixServiceCascade;
    use WithPagination;

    // 'acceptes' : courriers dont le DERNIER transfert a été accepté, quel
    // que soit leur statut actuel — l'original papier reste à remettre même
    // si le service a déjà affecté le courrier entre-temps.
    public const ONGLETS = ['en_attente_de_transfert', 'en_cours_de_transfert', 'acceptes'];

    #[Url]
    public string $onglet = 'en_attente_de_transfert';

    // Sélection en masse — transfert (onglet "À transférer") ou remise des
    // originaux (onglet "Acceptés"). Ids bruts uniquement (Règle n°2),
    // chacun revérifié individuellement au moment d'agir (Règle n°6).
    public array $selection = [];

    // 2026-10-30 (« they have to also see the search and filter inside Mes
    // courriers ») — l'agent n'a plus « Tous les courriers » au menu :
    // recherche et filtres ici, appliqués dans l'onglet courant. Chaînes
    // seulement (« vide » = pas de filtre, Règle 19 des pièges Livewire).
    #[Url]
    public string $recherche = '';

    #[Url]
    public string $typeDocument = '';

    #[Url]
    public string $priorite = '';

    #[Url]
    public string $periode = '';

    #[Url]
    public string $dateDebut = '';

    #[Url]
    public string $dateFin = '';

    #[Url]
    public string $serviceId = '';

    private const FILTRES = ['recherche', 'typeDocument', 'priorite', 'periode', 'dateDebut', 'dateFin', 'serviceId'];

    public function mount(): void
    {
        // Privilège de lecture dédié courriers.voir_mes_courriers (2026-09-23).
        $this->authorize('voirMesCourriers', Courrier::class);

        if (! in_array($this->onglet, self::ONGLETS, true)) {
            $this->onglet = 'en_attente_de_transfert';
        }
    }

    public function changerOnglet(string $onglet): void
    {
        if (! in_array($onglet, self::ONGLETS, true)) {
            return;
        }

        $this->onglet = $onglet;
        $this->reset('selection');
        $this->reinitialiserCascade();
        $this->resetPage();
    }

    // Un filtre modifié : retour à la première page, et la sélection en
    // masse est vidée (elle pourrait contenir des courriers qui ne sont plus
    // affichés).
    public function updated(string $nom): void
    {
        if (in_array($nom, self::FILTRES, true)) {
            $this->reset('selection');
            $this->resetPage();
        }
    }

    public function reinitialiserFiltres(): void
    {
        $this->reset(self::FILTRES);
        $this->reset('selection');
        $this->resetPage();
    }

    // Courriers ENTRANT créés par l'utilisateur courant (via l'historique
    // append-only, Règle n°5), cumulé avec Courrier::scopeVisiblePar() (même
    // règle que leur fiche) — base commune de la liste et du filtre Service.
    private function mesCourriers()
    {
        return Courrier::query()
            ->visiblePar(Auth::user())
            ->where('sens', 'entrant')
            ->whereHas('historiques', fn ($q) => $q->where('action', 'creation')->where('auteur_id', Auth::id()));
    }

    // Toujours paginé (Règle n°3). Recherche et filtres (2026-10-30) : mêmes
    // règles que « Tous les courriers » (CourrierList::resultats()).
    #[Computed]
    public function courriers()
    {
        [$periodeDebut, $periodeFin] = match ($this->periode) {
            'aujourd_hui' => [now()->startOfDay(), now()->endOfDay()],
            'cette_semaine' => [now()->startOfWeek(), now()->endOfWeek()],
            'ce_mois' => [now()->startOfMonth(), now()->endOfMonth()],
            'cette_annee' => [now()->startOfYear(), now()->endOfYear()],
            default => [null, null],
        };

        return $this->mesCourriers()
            ->when(
                $this->onglet === 'acceptes',
                fn ($q) => $q->whereHas('dernierTransfert', fn ($q) => $q->where('statut', TransfertService::ACCEPTE)),
                fn ($q) => $q->where('statut', $this->onglet),
            )
            ->when($this->recherche !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('numero_reference', 'like', "%{$this->recherche}%")
                ->orWhere('objet', 'like', "%{$this->recherche}%")
                ->orWhere('expediteur_nom', 'like', "%{$this->recherche}%")
                ->orWhere('expediteur_organisation', 'like', "%{$this->recherche}%")))
            ->when($this->typeDocument !== '', fn ($q) => $q->where('type_document', $this->typeDocument))
            ->when($this->priorite !== '', fn ($q) => $q->where('priorite', $this->priorite))
            ->when($periodeDebut, fn ($q) => $q->whereBetween('date_mouvement', [$periodeDebut, $periodeFin]))
            ->when($this->dateDebut !== '', fn ($q) => $q->whereDate('date_mouvement', '>=', $this->dateDebut))
            ->when($this->dateFin !== '', fn ($q) => $q->whereDate('date_mouvement', '<=', $this->dateFin))
            ->when($this->serviceId !== '', fn ($q) => $q->where('service_id', (int) $this->serviceId))
            ->with(['service', 'dernierTransfert.service'])
            ->latest('date_mouvement')
            ->paginate(10);
    }

    // Filtre Service : seulement les services vers lesquels l'agent a
    // réellement envoyé des courriers (pas tous les services de l'entreprise).
    #[Computed]
    public function servicesDeMesCourriers()
    {
        return Service::query()
            ->whereIn('id', $this->mesCourriers()->whereNotNull('service_id')->select('service_id'))
            ->orderBy('nom')
            ->get(['id', 'nom']);
    }

    public function filtresActifs(): bool
    {
        return collect(self::FILTRES)->contains(fn ($nom) => $this->{$nom} !== '');
    }

    // Transfert groupé vers UN service (cascade Organisation, revérifiée
    // côté serveur). Chaque courrier est revérifié individuellement
    // (CourrierPolicy::transferer() + statut) : un id qui ne correspond plus
    // (déjà transféré entre-temps, pas le sien) est ignoré plutôt que de
    // faire échouer tout le lot.
    public function transfererSelection(WorkflowService $workflow): void
    {
        $service = $this->serviceChoisiOuEchec();

        if (! $service) {
            return;
        }

        $utilisateur = Auth::user();
        $transferes = 0;

        foreach (Courrier::query()->whereIn('id', $this->selection)->where('statut', 'en_attente_de_transfert')->get() as $courrier) {
            if (! $utilisateur->can('transferer', $courrier)) {
                continue;
            }

            try {
                $workflow->transfererAuService($courrier, $service, $utilisateur);
                $transferes++;
            } catch (RuntimeException $e) {
                report($e);
            }
        }

        $this->reset('selection');
        $this->reinitialiserCascade();
        unset($this->courriers);

        Flux::modal('transferer-selection-modal')->close();
        Flux::toast(
            variant: $transferes > 0 ? 'success' : 'warning',
            text: $transferes > 0
                ? __(':n courrier(s) transféré(s) au service :nom — en attente de son acceptation.', ['n' => $transferes, 'nom' => $service->nom])
                : __('Aucun courrier sélectionné n\'a pu être transféré.'),
        );
    }

    // Plusieurs originaux remis en un seul passage au service. Même
    // revérification individuelle (CourrierPolicy::marquerOriginalRemis()).
    public function marquerOriginauxRemis(WorkflowService $workflow): void
    {
        $utilisateur = Auth::user();
        $remis = 0;

        foreach (Courrier::query()->whereIn('id', $this->selection)->with('dernierTransfert')->get() as $courrier) {
            if (! $utilisateur->can('marquerOriginalRemis', $courrier)) {
                continue;
            }

            try {
                $workflow->marquerOriginalRemis($courrier, $utilisateur);
                $remis++;
            } catch (RuntimeException $e) {
                report($e);
            }
        }

        $this->reset('selection');
        unset($this->courriers);

        Flux::modal('remise-originaux-modal')->close();
        Flux::toast(
            variant: $remis > 0 ? 'success' : 'warning',
            text: $remis > 0
                ? __(':n original(aux) marqué(s) comme remis.', ['n' => $remis])
                : __('Aucun original sélectionné n\'a pu être marqué comme remis.'),
        );
    }

    // 2026-10-27 (« comme Outlook : quand quelqu'un envoie un courrier, il
    // arrive directement sans que l'utilisateur rafraîchisse ») — une
    // notification reçue en direct (NotificationBell::notificationRecue(),
    // via Reverb) relance le rendu : la liste se recharge avec le nouveau
    // courrier, sans recharger la page ni sonder le serveur (Règle n°2).
    #[On('notifications-mises-a-jour')]
    public function actualiserEnDirect(): void {}

    public function render()
    {
        return view('frontend::mesCourriers');
    }
}
