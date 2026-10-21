<?php

namespace App\Livewire\Backend;

use App\Jobs\IndexCourrierJob;
use App\Livewire\Backend\Forms\CourrierForm;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Service;
use App\Services\PieceJointeService;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Modifier un courrier')]
class EditForm extends Component
{
    use WithFileUploads;

    // Propriété : ID primitif uniquement (pas de modèle Eloquent complet) — Règle n°2.
    public int $courrierId;

    public CourrierForm $form;

    public string $numeroReference = '';

    // Exception à la Règle n°2 : mécanisme d'upload natif de Livewire (voir
    // RegistrationForm). Une pièce jointe de plus est toujours ajoutable — la
    // table `pieces_jointes` est un-à-plusieurs, contrairement à l'ancien
    // `fichier_path` unique (voir ARCHITECTURE.md).
    public $pieceJointe = null;

    // Module 1 — voir RegistrationForm::$typeDocumentPersonnalise (même
    // raison ici : un courrier déjà enregistré avant l'introduction de la
    // liste déroulante, ou classé automatiquement avec une valeur hors
    // liste, doit rester modifiable sans que sa vraie valeur disparaisse).
    public bool $typeDocumentPersonnalise = false;

    // Maquette "Modifier le courrier" (2026-09-18, demande explicite de
    // l'utilisateur : "Add the new fields for real") — champs génuinement
    // nouveaux, absents du schéma avant cette maquette (Type/Catégorie/
    // Service émetteur de la maquette sont eux de simples relibellés de
    // sens/type_document/service_id existants, pas dupliqués ici). Propriétés
    // directes sur le composant plutôt que sur le CourrierForm partagé avec
    // RegistrationForm : RegistrationForm n'a aucune UI pour ces champs et ne
    // doit pas être impacté par leur validation (Règle n°2 — un nombre
    // raisonnable de champs simples, pas besoin d'un second Form Object).
    // TOUS nullable : champ net nouveau sur des courriers déjà existants,
    // jamais rendu required tant qu'aucune règle métier ne l'exige (pas
    // d'astérisque "obligatoire" dans la vue sur ces champs précis).
    public ?int $directionOrigineId = null;

    public ?string $echeance = null;

    public ?string $typeTraitement = null;

    public ?string $expediteurFonction = null;

    public ?string $noteInterne = null;

    // Maquette "Détail du courrier" (2026-09-18, demande explicite de
    // l'utilisateur : "Add them for real (Recommended)... wire them into
    // the edit form too so they're actually editable, not just
    // displayed") — mêmes principes que le bloc ci-dessus.
    public ?string $dossierReference = null;

    public ?int $slaJours = null;

    public ?int $serviceResponsableId = null;

    public ?string $referenceExterne = null;

    public function mount(int $courrierId): void
    {
        $courrier = Courrier::findOrFail($courrierId);

        // Règle n°6 — jamais confiance en un ID client sans vérifier les droits côté serveur.
        $this->authorize('update', $courrier);

        $this->courrierId = $courrierId;
        $this->numeroReference = $courrier->numero_reference;

        $this->form->sens = $courrier->sens;
        $this->form->date_mouvement = $courrier->date_mouvement->format('Y-m-d');
        $this->form->expediteur_nom = $courrier->expediteur_nom;
        $this->form->expediteur_organisation = $courrier->expediteur_organisation;
        $this->form->expediteur_telephone = $courrier->expediteur_telephone;
        $this->form->expediteur_email = $courrier->expediteur_email;
        $this->form->expediteur_adresse = $courrier->expediteur_adresse;
        $this->form->expediteur_rc = $courrier->expediteur_rc;
        $this->form->expediteur_niu = $courrier->expediteur_niu;
        $this->form->destinataire = $courrier->destinataire;
        $this->form->objet = $courrier->objet;
        $this->form->type_document = $courrier->type_document;
        $this->form->sous_type_sinistre = $courrier->sous_type_sinistre;
        $this->form->mode_reception = $courrier->mode_reception;
        $this->form->service_id = $courrier->service_id;
        $this->form->priorite = $courrier->priorite;
        $this->form->confidentialite = $courrier->confidentialite;

        $this->directionOrigineId = $courrier->direction_origine_id;
        $this->echeance = $courrier->echeance?->format('Y-m-d');
        $this->typeTraitement = $courrier->type_traitement;
        $this->expediteurFonction = $courrier->expediteur_fonction;
        $this->noteInterne = $courrier->note_interne;
        $this->dossierReference = $courrier->dossier_reference;
        $this->slaJours = $courrier->sla_jours;
        $this->serviceResponsableId = $courrier->service_responsable_id;
        $this->referenceExterne = $courrier->reference_externe;

        $this->typeDocumentPersonnalise = $this->form->type_document !== ''
            && ! in_array($this->form->type_document, CourrierForm::typesDocument(), true);
    }

    // Bascule "Autre" du select vers le champ libre — voir $typeDocumentPersonnalise.
    public function updatedFormTypeDocument(string $valeur): void
    {
        if ($valeur === '__autre__') {
            $this->typeDocumentPersonnalise = true;
            $this->form->type_document = '';
        }
    }

    // Retour à la liste depuis le champ libre — voir RegistrationForm (même raison).
    public function choisirTypeDocumentDansLaListe(): void
    {
        $this->typeDocumentPersonnalise = false;
        $this->form->type_document = '';
    }

    #[Computed]
    public function services()
    {
        return Service::query()->where('actif', true)->orderBy('nom')->get(['id', 'nom']);
    }

    // Règle n°2 — jamais un modèle Eloquent en propriété publique : rechargé
    // à chaque requête pour le panneau "Aperçu du courrier" (même motif que
    // ShowCourrier::courrier()). PENTEST (2026-09-24) — $courrierId est une
    // propriété publique SANS #[Locked] : Livewire accepte de la modifier
    // directement depuis une requête cliente forgée, même sans wire:model
    // dans le DOM (mount() n'autorise qu'UNE FOIS, au chargement initial).
    // Revérifier 'view' ICI, comme ShowCourrier::courrier() le fait déjà,
    // est donc INDISPENSABLE — sans quoi ce panneau (expéditeur, statut,
    // confidentialité, pièces jointes) pouvait fuiter le détail d'un
    // courrier hors du périmètre de l'utilisateur après un simple
    // changement d'id côté client.
    #[Computed]
    public function courrier(): Courrier
    {
        $courrier = Courrier::query()->with(['piecesJointes'])->findOrFail($this->courrierId);

        $this->authorize('view', $courrier);

        return $courrier;
    }

    public function enregistrerModification(PieceJointeService $pieceJointeService): void
    {
        $courrier = Courrier::findOrFail($this->courrierId);

        $this->authorize('update', $courrier);

        // Voir RegistrationForm::enregistrer() — Flux select "— Sans objet —"
        // lie '' et non null, ce que `nullable` ne dispense pas de Rule::in().
        $this->form->sous_type_sinistre = $this->form->sous_type_sinistre ?: null;

        $data = $this->form->validate();
        $donneesSupplementaires = $this->validate([
            'directionOrigineId' => ['nullable', 'integer', 'exists:services,id'],
            'echeance' => ['nullable', 'date'],
            'typeTraitement' => ['nullable', 'string', 'max:255'],
            'expediteurFonction' => ['nullable', 'string', 'max:255'],
            'noteInterne' => ['nullable', 'string', 'max:1000'],
            'dossierReference' => ['nullable', 'string', 'max:255'],
            'slaJours' => ['nullable', 'integer', 'min:0', 'max:365'],
            'serviceResponsableId' => ['nullable', 'integer', 'exists:services,id'],
            'referenceExterne' => ['nullable', 'string', 'max:255'],
            'pieceJointe' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);
        unset($donneesSupplementaires['pieceJointe']);

        $data = array_merge($data, [
            'direction_origine_id' => $donneesSupplementaires['directionOrigineId'],
            'echeance' => $donneesSupplementaires['echeance'],
            'type_traitement' => $donneesSupplementaires['typeTraitement'],
            'expediteur_fonction' => $donneesSupplementaires['expediteurFonction'],
            'note_interne' => $donneesSupplementaires['noteInterne'],
            'dossier_reference' => $donneesSupplementaires['dossierReference'],
            'sla_jours' => $donneesSupplementaires['slaJours'],
            'service_responsable_id' => $donneesSupplementaires['serviceResponsableId'],
            'reference_externe' => $donneesSupplementaires['referenceExterne'],
        ]);

        // 2026-09-15 (voir DECISIONS.md, synchronisation SRS-GEC.pdf) : le
        // champ Service est masqué pour un courrier ENTRANT (voir editForm.blade.php),
        // donc `form.service_id` garde la dernière valeur chargée même une
        // fois le champ caché. Uniquement si le Sens BASCULE de sortant vers
        // entrant pendant cette modification (pas simplement "c'est déjà un
        // entrant") — sinon un Administrateur qui corrige un autre champ d'un
        // courrier entrant déjà validé par le DGA effacerait son service.
        if ($data['sens'] === 'entrant' && $courrier->sens === 'sortant') {
            $data['service_id'] = null;
        }

        $champsModifies = [];

        DB::transaction(function () use ($courrier, $data, &$champsModifies) {
            $champsModifies = $this->champsModifies($courrier, $data);

            if ($champsModifies === []) {
                return;
            }

            // $courrier porte déjà les nouvelles valeurs : champsModifies() a rempli
            // le modèle en le comparant (Courrier passé par référence d'objet).
            $courrier->save();

            // Règle n°5 — toute modification crée une entrée d'historique immuable,
            // jamais une simple mise à jour de champ sans trace.
            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => Auth::id(),
                'action' => 'modification',
                'commentaire' => 'Champs modifiés : '.implode(', ', $champsModifies),
            ]);
        });

        // Module 3 — objet ou expéditeur corrigés : le classement automatique est rejoué.
        if (array_intersect($champsModifies, ['objet', 'expediteur_nom', 'expediteur_organisation']) !== []) {
            IndexCourrierJob::dispatch($courrier)->onQueue('indexation');
        }

        $avertissementPieceJointe = null;

        if ($this->pieceJointe) {
            try {
                $pieceJointeService->attacher($courrier, $this->pieceJointe, Auth::id());
            } catch (\Throwable $e) {
                report($e);
                $avertissementPieceJointe = __('Courrier mis à jour, mais la pièce jointe n\'a pas pu être stockée (stockage indisponible).');
            }
        }

        Flux::toast(
            variant: $avertissementPieceJointe ? 'warning' : 'success',
            text: $avertissementPieceJointe ?? __('Courrier mis à jour.'),
        );

        $this->redirect(route('courriers.show', ['courrierId' => $this->courrierId]), navigate: true);
    }

    // getDirty() compare la valeur brute stockée après fill() à l'original en base ;
    // pour un cast `date`, le format brut normalisé diffère selon qu'on vient d'un
    // fill() ou d'une lecture DB, ce qui déclenche un faux positif. On compare donc
    // les valeurs déjà castées (accesseur), avant/après, champ par champ.
    private function champsModifies(Courrier $courrier, array $data): array
    {
        $avant = [];
        foreach (array_keys($data) as $champ) {
            $avant[$champ] = $courrier->{$champ};
        }

        $courrier->fill($data);

        $modifies = [];
        foreach ($avant as $champ => $valeurAvant) {
            $valeurApres = $courrier->{$champ};

            $inchange = $valeurAvant instanceof CarbonInterface && $valeurApres instanceof CarbonInterface
                ? $valeurAvant->equalTo($valeurApres)
                : $valeurAvant === $valeurApres;

            if (! $inchange) {
                $modifies[] = $champ;
            }
        }

        return $modifies;
    }

    public function render()
    {
        return view('frontend::editForm');
    }
}
