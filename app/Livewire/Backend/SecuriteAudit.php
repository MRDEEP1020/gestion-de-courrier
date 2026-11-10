<?php

namespace App\Livewire\Backend;

use App\Jobs\CalculerOccupationStockageJob;
use App\Jobs\ChiffrerFichiersExistantsJob;
use App\Jobs\RecalculerConservationJob;
use App\Jobs\VerifierIntegriteFichiersJob;
use App\Livewire\Backend\Forms\CourrierForm;
use App\Models\Courrier;
use App\Models\CourrierConsultation;
use App\Models\CourrierHistorique;
use App\Models\DureeConservation;
use App\Models\EmpreinteFichier;
use App\Models\OccupationStockage;
use App\Models\Parametre;
use App\Models\PieceJointe;
use App\Services\ChiffrementFichiers;
use App\Services\ConservationService;
use App\Services\EtatSysteme;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

// Module 9 — Administration › Sécurité & Audit (2026-10-30, conformité de
// l'archivage, voir DECISIONS.md) : journal des consultations, intégrité des
// fichiers, conservation des archives (durées, échéances, gel juridique,
// destruction avec motif), état du planificateur et de la sauvegarde.
// Lecture : administration.audit ; actions de conservation :
// archives.gerer_conservation (revérifiées à chaque action, Règle n°6).
#[Title('Sécurité & Audit')]
class SecuriteAudit extends Component
{
    use WithPagination;

    // 'stockage' (2026-11-09) : occupation de l'espace de stockage.
    public const ONGLETS = ['consultations', 'integrite', 'conservation', 'stockage', 'systeme'];

    #[Url]
    public string $onglet = 'consultations';

    // Filtres du journal (chaînes, Règle 19 des pièges Livewire).
    #[Url]
    public string $recherche = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $du = '';

    #[Url]
    public string $au = '';

    // Durées de conservation.
    public string $dureeDefaut = '';

    public string $nouveauType = '';

    public string $nouvellesAnnees = '';

    // Action sur une archive (modale).
    public ?int $courrierActionId = null;

    public string $actionConservation = '';

    public string $anneesProlongation = '1';

    public string $motif = '';

    public string $confirmation = '';

    public function mount(): void
    {
        $this->authorize('voirSecuriteAudit', Courrier::class);

        if (! in_array($this->onglet, self::ONGLETS, true)) {
            $this->onglet = 'consultations';
        }

        $this->dureeDefaut = (string) (Parametre::actuel()->conservation_annees_defaut ?? 10);
        $this->premierCalculStockage();
    }

    public function changerOnglet(string $onglet): void
    {
        if (in_array($onglet, self::ONGLETS, true)) {
            $this->onglet = $onglet;
            $this->resetPage();
            $this->premierCalculStockage();
        }
    }

    // ===== Stockage (2026-11-09) =====

    // Jamais calculé : le premier calcul part en file d'attente (Règle n°1),
    // une seule fois (ShouldBeUnique).
    private function premierCalculStockage(): void
    {
        if ($this->onglet === 'stockage' && ! OccupationStockage::exists()) {
            CalculerOccupationStockageJob::dispatch();
        }
    }

    // Le dernier calcul et les précédents (évolution).
    #[Computed]
    public function stockage(): array
    {
        $calculs = OccupationStockage::latest('calcule_le')->limit(12)->get();

        return ['dernier' => $calculs->first(), 'historique' => $calculs];
    }

    public function calculerStockage(): void
    {
        $this->authorize('voirSecuriteAudit', Courrier::class);

        CalculerOccupationStockageJob::dispatch();

        Flux::toast(variant: 'success', text: __('Calcul lancé en tâche de fond : rouvrez cet onglet dans un instant pour le résultat.'));
    }

    public function updated(string $nom): void
    {
        if (in_array($nom, ['recherche', 'action', 'du', 'au'], true)) {
            $this->resetPage();
        }
    }

    // ===== Consultations =====

    #[Computed]
    public function consultations()
    {
        return CourrierConsultation::query()
            ->with(['utilisateur:id,name', 'courrier' => fn ($q) => $q->withTrashed()->select('id', 'numero_reference')])
            ->when($this->recherche !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('courrier', fn ($c) => $c->withTrashed()->where('numero_reference', 'like', "%{$this->recherche}%"))
                ->orWhereHas('utilisateur', fn ($u) => $u->where('name', 'like', "%{$this->recherche}%"))))
            ->when(isset(CourrierConsultation::ACTIONS[$this->action]), fn ($q) => $q->where('action', $this->action))
            ->when($this->du !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->du))
            ->when($this->au !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->au))
            ->latest('created_at')->latest('id')
            ->paginate(20);
    }

    // ===== Intégrité =====

    #[Computed]
    public function integrite(): array
    {
        $parStatut = EmpreinteFichier::query()->selectRaw('statut, count(*) as n')->groupBy('statut')->pluck('n', 'statut');
        $alertes = EmpreinteFichier::query()
            ->whereIn('statut', [EmpreinteFichier::STATUT_ALTEREE, EmpreinteFichier::STATUT_MANQUANT])
            ->orderByDesc('verifie_le')->limit(50)->get();

        $courrierDe = fn (EmpreinteFichier $e) => $e->fichier_type === EmpreinteFichier::TYPE_PIECE_JOINTE
            ? PieceJointe::whereKey($e->fichier_id)->value('courrier_id')
            : $e->fichier_id;

        $derniere = Cache::get(VerifierIntegriteFichiersJob::CLE_DERNIERE_VERIFICATION);

        return [
            'ok' => (int) ($parStatut[EmpreinteFichier::STATUT_OK] ?? 0),
            'alteree' => (int) ($parStatut[EmpreinteFichier::STATUT_ALTEREE] ?? 0),
            'manquant' => (int) ($parStatut[EmpreinteFichier::STATUT_MANQUANT] ?? 0),
            'detruit' => (int) ($parStatut[EmpreinteFichier::STATUT_DETRUIT] ?? 0),
            'derniereVerification' => $derniere ? Carbon::parse($derniere) : null,
            'alertes' => $alertes->map(fn (EmpreinteFichier $e) => [
                'empreinte' => $e,
                'courrier' => Courrier::withTrashed()->select('id', 'numero_reference')->find($courrierDe($e)),
            ])->all(),
        ];
    }

    public function lancerVerification(): void
    {
        $this->authorize('voirSecuriteAudit', Courrier::class);

        VerifierIntegriteFichiersJob::dispatch();

        Flux::toast(variant: 'success', text: __('Vérification lancée en tâche de fond : les résultats apparaîtront ici dès qu\'elle sera terminée.'));
    }

    // ===== Conservation =====

    #[Computed]
    public function durees()
    {
        return DureeConservation::orderBy('type_document')->get();
    }

    #[Computed]
    public function typesDocument(): array
    {
        return CourrierForm::typesDocument();
    }

    #[Computed]
    public function archivesEchues()
    {
        return Courrier::query()
            ->where('statut', 'archive')->where('gel_juridique', false)
            ->whereNotNull('conserver_jusqua')->whereDate('conserver_jusqua', '<=', today())
            ->orderBy('conserver_jusqua')
            ->paginate(15, ['*'], 'echuesPage');
    }

    #[Computed]
    public function archivesGelees()
    {
        return Courrier::query()->where('statut', 'archive')->where('gel_juridique', true)
            ->orderBy('conserver_jusqua')->limit(100)->get();
    }

    #[Computed]
    public function prochainesEcheances()
    {
        return Courrier::query()->where('statut', 'archive')->where('gel_juridique', false)
            ->whereDate('conserver_jusqua', '>', today())->orderBy('conserver_jusqua')->limit(10)->get();
    }

    // Archives antérieures à la fonctionnalité, ou dont le recalcul nocturne
    // n'a pas tourné (serveur éteint à 03:00 : une tâche manquée n'est pas
    // rattrapée) — sans échéance, elles n'apparaîtraient nulle part.
    #[Computed]
    public function archivesSansEcheance(): int
    {
        return Courrier::where('statut', 'archive')->whereNull('conserver_jusqua')->count();
    }

    public function recalculerEcheances(): void
    {
        $this->authorize('gererConservation', Courrier::class);

        RecalculerConservationJob::dispatch();

        Flux::toast(variant: 'success', text: __('Recalcul lancé en tâche de fond : les échéances apparaîtront ici dès qu\'il sera terminé.'));
    }

    #[Computed]
    public function registreDestructions()
    {
        return CourrierHistorique::query()->where('action', 'destruction')
            ->with(['auteur:id,name', 'courrier' => fn ($q) => $q->withTrashed()->select('id', 'numero_reference', 'objet', 'type_document')])
            ->latest('created_at')->limit(100)->get();
    }

    public function enregistrerDureeDefaut(): void
    {
        $this->authorize('gererConservation', Courrier::class);
        $this->validate(['dureeDefaut' => ['required', 'integer', 'min:1', 'max:100']], [], ['dureeDefaut' => __('durée par défaut')]);

        Parametre::actuel()->update(['conservation_annees_defaut' => (int) $this->dureeDefaut]);
        Parametre::invaliderCache();
        RecalculerConservationJob::dispatch();

        Flux::toast(variant: 'success', text: __('Durée par défaut enregistrée : les échéances sont recalculées en tâche de fond.'));
    }

    public function ajouterDuree(): void
    {
        $this->authorize('gererConservation', Courrier::class);
        $this->validate([
            'nouveauType' => ['required', 'string', 'max:255'],
            'nouvellesAnnees' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], ['nouveauType' => __('type de document'), 'nouvellesAnnees' => __('durée')]);

        DureeConservation::updateOrCreate(['type_document' => $this->nouveauType], ['annees' => (int) $this->nouvellesAnnees]);
        $this->reset('nouveauType', 'nouvellesAnnees');
        unset($this->durees);
        RecalculerConservationJob::dispatch();

        Flux::toast(variant: 'success', text: __('Durée enregistrée : les échéances sont recalculées en tâche de fond.'));
    }

    public function supprimerDuree(int $id): void
    {
        $this->authorize('gererConservation', Courrier::class);

        DureeConservation::whereKey($id)->delete();
        unset($this->durees);
        RecalculerConservationJob::dispatch();

        Flux::toast(text: __('Durée retirée : ce type suit désormais la durée par défaut.'));
    }

    public function ouvrirAction(int $courrierId, string $action): void
    {
        $this->authorize('gererConservation', Courrier::class);

        if (! in_array($action, ['prolonger', 'geler', 'degeler', 'detruire'], true)) {
            return;
        }

        $this->reset('anneesProlongation', 'motif', 'confirmation');
        $this->resetValidation();
        $this->courrierActionId = $courrierId;
        $this->actionConservation = $action;

        Flux::modal('action-conservation')->show();
    }

    #[Computed]
    public function courrierAction(): ?Courrier
    {
        return $this->courrierActionId ? Courrier::find($this->courrierActionId) : null;
    }

    public function executerAction(ConservationService $conservation): void
    {
        $courrier = Courrier::findOrFail($this->courrierActionId);
        $this->authorize($this->actionConservation === 'detruire' ? 'detruire' : 'gererConservation', $this->actionConservation === 'detruire' ? $courrier : Courrier::class);

        $regles = ['motif' => ['required', 'string', 'min:5', 'max:500']];

        if ($this->actionConservation === 'prolonger') {
            $regles['anneesProlongation'] = ['required', 'integer', 'min:1', 'max:50'];
        }

        if ($this->actionConservation === 'detruire') {
            $regles['confirmation'] = ['required', 'in:'.$courrier->numero_reference];
        }

        $this->validate($regles, ['confirmation.in' => __('Saisissez exactement la référence :ref pour confirmer.', ['ref' => $courrier->numero_reference])], ['motif' => __('motif'), 'anneesProlongation' => __('durée')]);

        $auteur = Auth::user();

        try {
            match ($this->actionConservation) {
                'prolonger' => $conservation->prolonger($courrier, $auteur, (int) $this->anneesProlongation, $this->motif),
                'geler' => $conservation->geler($courrier, $auteur, true, $this->motif),
                'degeler' => $conservation->geler($courrier, $auteur, false, $this->motif),
                'detruire' => $conservation->detruire($courrier, $auteur, $this->motif),
            };
        } catch (RuntimeException $e) {
            report($e);
            Flux::toast(variant: 'danger', text: __('Action impossible : la situation de cette archive a changé, la liste vient d\'être actualisée.'));
            $this->fermerAction();

            return;
        }

        Flux::toast(variant: $this->actionConservation === 'detruire' ? 'warning' : 'success', text: match ($this->actionConservation) {
            'prolonger' => __('Conservation prolongée.'),
            'geler' => __('Archive placée sous gel juridique.'),
            'degeler' => __('Gel juridique levé.'),
            'detruire' => __('Archive détruite : la décision est inscrite au registre des destructions.'),
        });

        $this->fermerAction();
    }

    private function fermerAction(): void
    {
        Flux::modal('action-conservation')->close();
        $this->reset('courrierActionId', 'actionConservation', 'anneesProlongation', 'motif', 'confirmation');
        unset($this->archivesEchues, $this->archivesGelees, $this->prochainesEcheances, $this->registreDestructions, $this->courrierAction);
    }

    // ===== Système =====

    #[Computed]
    public function systeme(): array
    {
        return [
            'battement' => EtatSysteme::dernierBattement(),
            'planificateurActif' => EtatSysteme::planificateurActif(),
            'sauvegarde' => EtatSysteme::etatSauvegarde(),
            'archivesSansPdfa' => Courrier::where('statut', 'archive')->whereNull('fichier_pdfa_path')->where('fichier_path', 'like', '%.pdf')->count(),
            'ghostscript' => filled(config('services.ghostscript.binary')),
            // 2026-11-09 — chiffrement des fichiers au repos.
            'cleChiffrement' => app(ChiffrementFichiers::class)->cleConfiguree(),
            'chiffrementExistants' => Cache::get(ChiffrerFichiersExistantsJob::CLE_ETAT),
        ];
    }

    // Chiffre en place les fichiers encore en clair (déposés avant le
    // chiffrement), en file d'attente : chaque fichier est vérifié avant et
    // après (voir ChiffrerFichiersExistantsJob). Réservé à qui gère la
    // conservation des archives : l'opération réécrit les fichiers stockés.
    public function chiffrerExistants(): void
    {
        $this->authorize('gererConservation', Courrier::class);

        ChiffrerFichiersExistantsJob::dispatch();

        Flux::toast(variant: 'success', text: __('Chiffrement des fichiers existants lancé en tâche de fond : le bilan apparaîtra ici.'));
    }

    public function render()
    {
        return view('frontend::securiteAudit');
    }
}
