<?php

namespace App\Livewire\Backend;

use App\Models\Courrier;
use App\Services\WorkflowService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Module 1/4 — page dédiée pour la réceptionniste (demande explicite de
// l'utilisateur, 2026-09-15 : "new page for receptionist", après "where can
// i see all receptionist register doc enttand and with buttons for select
// bulk and transfere to a supervisor") : ses courriers ENTRANT enregistrés,
// répartis en 3 onglets correspondant aux sous-statuts du transfert vers le
// DGA/ADJ DGA (voir DECISIONS.md "Synchronisation avec le nouveau document
// SRS-GEC.pdf" — Module 1/4). Distincte de `CourrierList` (recherche
// générale multi-critères).
#[Title('Mes courriers')]
class MesCourriers extends Component
{
    use WithPagination;

    // Onglet actif — un des 3 sous-statuts du document (voir
    // specifications-modules-GEC.md, Module 4). "Transféré" correspond au
    // statut 'enregistre' déjà existant (pas de nouvelle valeur d'ENUM,
    // voir DECISIONS.md).
    public const ONGLETS = ['en_attente_de_transfert', 'en_cours_de_transfert', 'enregistre'];

    #[Url]
    public string $onglet = 'en_attente_de_transfert';

    // Module 1/4 — sélection en masse pour le transfert groupé (Règle n°2 :
    // ids bruts uniquement).
    public array $selection = [];

    // Module 1/4 — modale "Transférer à" (2026-09-15, voir DECISIONS.md
    // "Destinataires de transfert") — id brut, revérifié contre
    // Auth::user()->destinatairesTransfert() avant d'agir (Règle n°6).
    public ?int $destinataireChoisi = null;

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
        $this->reset('selection', 'destinataireChoisi');
        $this->resetPage();
    }

    // Toujours paginé (Règle n°3). Scopé aux courriers ENTRANT créés par
    // l'utilisateur courant (même condition que CourrierPolicy::estCreeParUtilisateur()
    // via l'historique append-only, Règle n°5 — jamais une colonne dupliquée).
    // 2026-09-23 — cumulé avec Courrier::scopeVisiblePar() : avoir créé un
    // courrier ne suffit pas à le voir listé si le niveau de confidentialité,
    // l'accès dossier ou le périmètre organisationnel le refusent (même
    // règle que sa fiche, CourrierPolicy::view()).
    #[Computed]
    public function courriers()
    {
        return Courrier::query()
            ->visiblePar(Auth::user())
            ->where('sens', 'entrant')
            ->whereHas('historiques', fn ($q) => $q->where('action', 'creation')->where('auteur_id', Auth::id()))
            ->where('statut', $this->onglet)
            ->with('service')
            ->latest('date_mouvement')
            ->paginate(10);
    }

    // Module 1/4 — liste affichée dans la modale "Transférer à" : curatée
    // par l'administrateur pour CET utilisateur précis (voir DECISIONS.md
    // "Destinataires de transfert"), pas dérivée d'un profil/privilège.
    #[Computed]
    public function destinatairesTransfert()
    {
        return Auth::user()->destinatairesTransfert()->orderBy('name')->get(['users.id', 'users.name']);
    }

    // Module 1/4 — transférer plusieurs courriers en attente en un seul
    // geste, vers le destinataire choisi dans la modale (2026-09-15, voir
    // DECISIONS.md "Destinataires de transfert"). $destinataireChoisi
    // revérifié contre les destinataires autorisés de l'utilisateur avant
    // d'agir — jamais fait confiance à l'ID posté (Règle n°6). Chaque
    // courrier de la sélection est ensuite revérifié individuellement : un
    // id qui ne correspond plus (déjà transféré entre-temps par une autre
    // requête, plus le sien) est silencieusement ignoré plutôt que de faire
    // échouer tout le lot.
    public function transfererSelection(WorkflowService $workflow): void
    {
        $utilisateur = Auth::user();
        $destinataire = $utilisateur->destinatairesTransfert()->where('users.id', $this->destinataireChoisi)->first();

        if (! $destinataire) {
            $this->addError('destinataireChoisi', __('Choisissez un destinataire.'));

            return;
        }

        $transferes = 0;

        foreach (Courrier::query()->whereIn('id', $this->selection)->where('statut', 'en_attente_de_transfert')->get() as $courrier) {
            if (! $utilisateur->can('transferer', $courrier)) {
                continue;
            }

            $workflow->transferer($courrier, $destinataire, $utilisateur);
            $transferes++;
        }

        $this->reset('selection', 'destinataireChoisi');
        unset($this->courriers);

        Flux::toast(
            variant: $transferes > 0 ? 'success' : 'warning',
            text: $transferes > 0
                ? __(':n courrier(s) transféré(s) à :nom.', ['n' => $transferes, 'nom' => $destinataire->name])
                : __('Aucun courrier sélectionné n\'a pu être transféré.'),
        );
    }

    public function render()
    {
        return view('frontend::mesCourriers');
    }
}
