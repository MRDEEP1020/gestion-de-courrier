<?php

namespace App\Livewire\Backend;

use App\Concerns\ChoixServiceCascade;
use App\Models\Courrier;
use App\Models\CourrierConsultation;
use App\Models\CourrierHistorique;
use App\Models\DossierClassement;
use App\Models\EmpreinteFichier;
use App\Models\MotCle;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\ClassificationService;
use App\Services\DossierClassementService;
use App\Services\SlaCalculatorService;
use App\Services\WorkflowService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

#[Title('Détail du courrier')]
class ShowCourrier extends Component
{
    // Module 1/4 (2026-10-23) — choix du service destinataire du transfert
    // (cascade Organisation), partagé avec MesCourriers et CourrierList.
    use ChoixServiceCascade;

    // Propriété : ID primitif uniquement (pas de modèle Eloquent complet) — Règle n°2.
    public int $courrierId;

    // 2026-09-22, demande explicite de l'utilisateur (diagnostic en direct :
    // "why do i have to navigate there first where is that shortcut one") —
    // l'onglet affiché reste piloté par Alpine côté client (aucun
    // changement du mécanisme existant, voir la vue), mais sa valeur
    // INITIALE peut désormais être fixée via ?onglet=circuit dans l'URL —
    // pour que les "Tâches du jour" du tableau de bord et le panneau "Actions
    // rapides" puissent ouvrir directement sur "Circuit de traitement" au
    // lieu de forcer un clic supplémentaire sur chaque courrier. Valeur
    // non validée contre une liste blanche : une valeur inconnue ne
    // correspond simplement à aucun bouton d'onglet et Alpine affiche alors
    // celui déjà actif par défaut dans la vue (x-show sur chaque panneau),
    // aucun risque d'injection HTML (jamais affiché tel quel, seulement
    // comparé en JS).
    #[Url(as: 'onglet')]
    public string $ongletInitial = 'general';

    // Liste blanche des clés d'onglet réellement gérées par la vue
    // (x-show="onglet === '...'") — voir mount() ci-dessous, qui retombe sur
    // 'general' si la valeur reçue via ?onglet= n'en fait pas partie.
    private const ONGLETS_VALIDES = ['general', 'pieces-jointes', 'circuit', 'historique'];

    // Module 3 — saisie manuelle d'un tag secondaire.
    public string $nouveauMotCle = '';

    // Module 4/6 — propriétés primitives du panneau Circuit (Règle n°2 : pas
    // de Form Object, saisie très simple — un id + un motif/commentaire selon
    // l'action, jamais plusieurs champs croisés comme CourrierForm).
    public ?int $collaborateurSelectionne = null;

    // 2026-10-29 (« Un seul bouton Affecter ») — choix dans la modale
    // « Affecter » : 'collaborateur' (choisi dans la liste) ou 'moi'
    // (« Me l'affecter et commencer », l'ancien bouton « Traiter moi-même »).
    public string $modeAffectation = 'collaborateur';

    // Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service
    // et acceptation") — motif obligatoire du refus d'un transfert par le
    // service destinataire.
    public string $motifRefusTransfert = '';

    // 2026-10-23 (voir DECISIONS.md "Transmission descendante par la
    // Direction") — unité (organization_units.id) à laquelle le responsable
    // d'une entité transmet le courrier. Id brut, revérifié contre
    // $this->unitesSubordonnees avant d'agir (Règle n°6).
    public ?int $uniteTransmissionId = null;

    // Un champ par action plutôt qu'un seul "motifCirculation" partagé : pour
    // un Administrateur, plusieurs formulaires d'actions différentes peuvent
    // s'afficher en même temps sur un même statut (ex. Réaffecter + Mettre en
    // attente) — un champ commun s'écrirait dans les deux à la fois.
    public string $motifReaffectation = '';

    public string $motifRenvoi = '';

    public string $motifAttente = '';

    public string $motifRejet = '';

    // 2026-10-30 — message facultatif de la relance manuelle.
    public string $messageRelance = '';

    public string $commentaireCirculation = '';

    // Module 5 — délai du chronomètre de traitement (2026-09-24), saisi par
    // le responsable à l'affectation puis modifiable avec motif.
    public ?int $delaiValeur = null;

    public string $delaiUnite = 'jours';

    public string $motifDelai = '';

    // Module 3/9 — modale "Classer dans un dossier" (2026-09-22, demande
    // explicite de l'utilisateur : "les trois" points d'entrée). Id brut,
    // revérifié contre $this->dossiersAccessibles avant d'agir (Règle n°6).
    public ?int $dossierAClasserId = null;

    // Module 9 — modale "Émettre une décharge" (2026-10-05). Id brut,
    // revérifié contre $this->utilisateursPourDecharge avant d'agir (Règle n°6).
    public ?int $dechargeEmprunteurId = null;

    public string $dechargeMotif = '';

    public function mount(int $courrierId): void
    {
        $courrier = Courrier::with('service')->findOrFail($courrierId);

        // Règle n°6 — jamais confiance en un ID client sans vérifier les droits côté serveur.
        $this->authorize('view', $courrier);

        $this->courrierId = $courrierId;

        // 2026-10-30 — journal des consultations (conformité de l'archivage) :
        // une ligne à l'ouverture de la fiche (mount() ne rejoue pas aux
        // requêtes Livewire suivantes).
        CourrierConsultation::enregistrer($courrierId, 'fiche');

        if (! in_array($this->ongletInitial, self::ONGLETS_VALIDES, true)) {
            $this->ongletInitial = 'general';
        }

        $this->preremplirAffectation();

        // Module 1/4 (2026-10-23) — transfert au service : la cascade est
        // pré-remplie avec la proposition de classement (Module 3), l'agent
        // la garde ou en choisit une autre. Rien de pré-rempli sans
        // proposition (jamais une donnée inventée).
        if ($courrier->statut === 'en_attente_de_transfert') {
            $this->preselectionnerCascadeDepuisService($courrier->service_propose_id);
        }
    }

    // Rechargé côté serveur à chaque requête à partir du seul ID (Règle n°2),
    // avec eager loading pour éviter le N+1 sur la timeline (Règle n°3).
    #[Computed]
    public function courrier(): Courrier
    {
        $courrier = Courrier::with([
            'service',
            'servicePropose',
            'regleClassement',
            'motsCles',
            'historiques.auteur',
            'affectationCourante.collaborateur',
            'affectationCourante.affectePar',
            'piecesJointes',
            // 2026-10-02 — affiché à 3 endroits de cette page ("Informations
            // générales", "Détails complémentaires", "Actions rapides") :
            // sans eager loading, la relation se charge quand même (mise en
            // cache après le 1er accès), mais Règle n°3 (eager loading
            // systématique) l'exige explicitement comme les autres ci-dessus.
            'dossierClassement',
            // Module 9 — "Décharge" (2026-10-05).
            'dechargeActive.emprunteur',
            // Module 1/4 — transfert au service (2026-10-23).
            'dernierTransfert.service',
            'dernierTransfert.transferePar',
            'dernierTransfert.decidePar',
            'dernierTransfert.originalRemisPar',
            'dernierTransfert.receptionSigneePar',
        ])->findOrFail($this->courrierId);

        $this->authorize('view', $courrier);

        return $courrier;
    }

    // Droit de modification évalué une seule fois par rendu (la Policy interroge
    // l'historique) au lieu d'un @can par tag dans la vue — Règle n°3, pas de N+1.
    #[Computed]
    public function peutModifier(): bool
    {
        return Auth::user()?->can('update', $this->courrier) ?? false;
    }

    // Module 4/6 — mêmes principes que peutModifier : une seule évaluation
    // par rendu, jamais un @can répété dans la vue.
    #[Computed]
    public function peutAffecter(): bool
    {
        return Auth::user()?->can('affecter', $this->courrier) ?? false;
    }

    // 2026-10-29 — bouton « Affecter » de l'en-tête : seulement à l'étape
    // « Accepté — à affecter », une fois la décharge remise et signée
    // (CourrierPolicy::affecterPremiere()).
    #[Computed]
    public function peutAffecterPremiere(): bool
    {
        return Auth::user()?->can('affecterPremiere', $this->courrier) ?? false;
    }

    #[Computed]
    public function peutTraiter(): bool
    {
        return Auth::user()?->can('traiter', $this->courrier) ?? false;
    }

    // 2026-10-13 — raccourci "Traiter moi-même" (voir CourrierPolicy::traiterMoiMeme()).
    #[Computed]
    public function peutTraiterMoiMeme(): bool
    {
        return Auth::user()?->can('traiterMoiMeme', $this->courrier) ?? false;
    }

    #[Computed]
    public function peutValider(): bool
    {
        return Auth::user()?->can('valider', $this->courrier) ?? false;
    }

    // 2026-10-09 — extension du circuit générique (Signature DG / Trésorerie,
    // voir WorkflowService). Mêmes principes que peutValider ci-dessus.
    #[Computed]
    public function peutEnvoyerSignatureDg(): bool
    {
        return Auth::user()?->can('envoyerSignatureDg', $this->courrier) ?? false;
    }

    #[Computed]
    public function peutSignerDg(): bool
    {
        return Auth::user()?->can('signerDg', $this->courrier) ?? false;
    }

    #[Computed]
    public function peutTraiterTresorerie(): bool
    {
        return Auth::user()?->can('traiterTresorerie', $this->courrier) ?? false;
    }

    // Module 1/4 — l'accueil transfère au service concerné (2026-10-23,
    // voir DECISIONS.md "Transfert direct au service et acceptation") : même
    // principe que les autres peut*() ci-dessus.
    #[Computed]
    public function peutTransferer(): bool
    {
        return Auth::user()?->can('transferer', $this->courrier) ?? false;
    }

    // Le responsable du service destinataire accepte ou refuse.
    #[Computed]
    public function peutDeciderTransfert(): bool
    {
        return Auth::user()?->can('accepterTransfert', $this->courrier) ?? false;
    }

    // Transmettre à une unité placée sous l'entité du courrier (ex. un
    // département d'une Direction).
    #[Computed]
    public function peutTransmettre(): bool
    {
        return Auth::user()?->can('transmettre', $this->courrier) ?? false;
    }

    // Unités placées DIRECTEMENT sous l'entité du service de ce courrier,
    // avec leur responsable — cibles possibles de "Transmettre". Seules les
    // unités actives reliées à un service réel actif AVEC responsable sont
    // retenues : sinon personne ne recevrait le courrier.
    #[Computed]
    public function unitesSubordonnees()
    {
        if ($this->courrier->service_id === null) {
            return collect();
        }

        $entite = OrganizationUnit::where('service_id', $this->courrier->service_id)->first();

        if ($entite === null) {
            return collect();
        }

        return OrganizationUnit::query()
            ->where('parent_id', $entite->id)
            ->where('status', OrganizationUnit::STATUT_ACTIF)
            ->whereHas('service', fn ($q) => $q->where('actif', true)->whereNotNull('responsable_id'))
            ->with('service.responsable')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function peutMarquerOriginalRemis(): bool
    {
        return Auth::user()?->can('marquerOriginalRemis', $this->courrier) ?? false;
    }

    // 2026-10-28 — signature électronique de la réception (cadre « Reçu par »).
    #[Computed]
    public function peutSignerReception(): bool
    {
        return Auth::user()?->can('signerReceptionOriginal', $this->courrier) ?? false;
    }

    // Contenu (aperçu, pièces jointes, OCR) masqué tant que le service n'a
    // pas accepté — blocage côté serveur, voir CourrierPolicy::voirContenu().
    #[Computed]
    public function peutVoirContenu(): bool
    {
        return Auth::user()?->can('voirContenu', $this->courrier) ?? false;
    }

    // Module 1/9 (2026-09-24) — "Marquer comme remis" sur un pli
    // confidentiel, réservé à son destinataire (voir CourrierPolicy::cloturerConfidentiel()).
    #[Computed]
    public function peutCloturerConfidentiel(): bool
    {
        return Auth::user()?->can('cloturerConfidentiel', $this->courrier) ?? false;
    }

    #[Computed]
    public function peutFixerDelai(): bool
    {
        return Auth::user()?->can('fixerDelai', $this->courrier) ?? false;
    }

    // Module 3/9 — "Classer dans un dossier" : même principe que peutModifier
    // ci-dessus, une seule évaluation par rendu.
    #[Computed]
    public function peutClasser(): bool
    {
        return Auth::user()?->can('classer', $this->courrier) ?? false;
    }

    // "Chaque action / lecture = un privilège" (2026-09-23) : droits fins de
    // la fiche, évalués une seule fois par rendu (même principe que peut*()).
    #[Computed]
    public function droits(): array
    {
        $user = Auth::user();
        $courrier = $this->courrier;

        return collect([
            'reaffecter', 'soumettreValidation', 'validerDirectement', 'renvoyerCorrection', 'rejeter',
            'mettreEnAttente', 'reprendre', 'relancer', 'validerClassement', 'gererMotsCles',
            'voirHistorique', 'voirTexteOcr', 'voirPiecesJointes', 'voirTraitementDocument',
            'repondre', 'creerNote', 'voirConsultations',
        ])->mapWithKeys(fn (string $abilite) => [$abilite => (bool) $user?->can($abilite, $courrier)])->all();
    }

    // 2026-10-30 — conformité de l'archivage (Module 9) : empreintes SHA-256
    // du document principal et de sa copie PDF/A, pour la carte « Archivage
    // et intégrité ».
    #[Computed]
    public function empreintes()
    {
        return EmpreinteFichier::query()
            ->where('fichier_id', $this->courrierId)
            ->whereIn('fichier_type', [EmpreinteFichier::TYPE_COURRIER, EmpreinteFichier::TYPE_COURRIER_PDFA])
            ->get()->keyBy('fichier_type');
    }

    // Journal des consultations de ce courrier (courriers.voir_consultations).
    #[Computed]
    public function consultations()
    {
        if (! $this->droits['voirConsultations']) {
            return collect();
        }

        return CourrierConsultation::query()->where('courrier_id', $this->courrierId)
            ->with('utilisateur:id,name')
            ->latest('created_at')->latest('id')
            ->limit(50)->get();
    }

    // Un seul dossier par utilisateur non-gerer_tout : créateur, responsable,
    // ou partagé explicitement — même requête que
    // DossierClassementList::tousLesDossiersAccessibles() (dupliquée à
    // l'identique, même convention déjà suivie pour services() dans ce
    // composant ET dans CourrierList/DossierClassementList).
    #[Computed]
    public function dossiersAccessibles()
    {
        $user = Auth::user();

        return DossierClassement::query()
            ->when(! $user->hasPrivilege('dossiers_classement.gerer_tout'), fn ($q) => $q->where(fn ($q) => $q
                ->where('cree_par_id', $user->id)
                ->orWhere('responsable_id', $user->id)
                ->orWhereHas('utilisateursAutorises', fn ($q) => $q->where('users.id', $user->id))))
            ->orderBy('nom')
            ->get();
    }

    // Module 9 — "Décharge" (2026-10-05). Évalué une seule fois par rendu,
    // même principe que peutModifier/peutTransferer ci-dessus.
    #[Computed]
    public function peutEmettreDecharge(): bool
    {
        return Auth::user()->can('emettreDecharge', $this->courrier);
    }

    // Liste pour le menu déroulant "Emprunteur" — n'importe quel utilisateur
    // peut physiquement venir récupérer un original aux archives, pas
    // seulement les collaborateurs d'un service précis (contrairement à
    // collaborateursDuService ci-dessous, propre à la réaffectation).
    #[Computed]
    public function utilisateursPourDecharge()
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }

    // Collaborateurs du service du courrier, triés par charge de travail
    // croissante (Module 6 — "affecte automatiquement au collaborateur le
    // moins chargé") : le premier de la liste est présélectionné dans la vue,
    // le responsable reste libre de choisir un autre nom.
    #[Computed]
    public function collaborateursDuService()
    {
        // Pli confidentiel (2026-09-24) : 'enregistre' SANS service — rien à
        // affecter ; sans ce garde-fou, mount() plantait (500) pour tout
        // lecteur de la fiche (TypeError dans chargeParCollaborateur()).
        if ($this->courrier->service_id === null) {
            return collect();
        }

        $charge = app(WorkflowService::class)->chargeParCollaborateur($this->courrier->service_id);

        // 2026-10-13, bug réel (retour utilisateur, "it is not working" après
        // avoir accordé courriers.traiter_affecte à "Responsable de service")
        // — le privilège seul ne suffit pas : CourrierPolicy::traiter() exige
        // AUSSI d'être affectationCourante->user_id, mais cette liste
        // n'incluait QUE le profil "Collaborateur", jamais le responsable
        // lui-même — il ne pouvait donc jamais être choisi ici, quel que soit
        // son privilège. "Responsable de service" ajouté à la liste des
        // profils sélectionnables ; reste sans effet pour qui n'a pas le
        // privilège (revérifié par CourrierPolicy::traiter() au moment
        // d'agir, Règle n°6 — ce composant ne fait que le rendre choisissable).
        // 2026-10-29 (« remove responsable from that list of collaborateur »)
        // — retiré de nouveau : le responsable s'affecte désormais le
        // courrier par « Me l'affecter et commencer » (même modale), la liste
        // ne montre plus que les collaborateurs.
        return User::query()
            ->where('service_id', $this->courrier->service_id)
            ->whereHas('profil', fn ($q) => $q->where('nom', 'Collaborateur'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'charge' => $charge[$u->id] ?? 0])
            ->sortBy('charge')
            ->values();
    }

    // Maquette "Détail du courrier" (2026-09-18, demande explicite de
    // l'utilisateur — "use this design exactly", puis "on the parcour add
    // transfere after creation") — 6 étapes génériques (Création/Transfert/
    // Affectation/Traitement/Réponse/Clôture) : purement AFFICHÉES d'après
    // le statut réel et l'historique réel (aucune nouvelle transition ici,
    // voir l'onglet "Circuit de traitement" pour les actions elles-mêmes).
    // "archive" est traité comme équivalent de "traite" pour cette frise
    // (les deux signifient "Clôture" atteinte) — pas une 7e étape distincte.
    // Les clés du tableau ci-dessous sont des étapes CONCEPTUELLES, pas
    // toujours identiques au statut brut en base : 'enregistre' seul est
    // ambigu (il désigne aussi bien "juste créé, jamais transféré" — courrier
    // sortant — que "transfert accepté par le service" —
    // WorkflowService::accepterTransfert(), 2026-10-23)
    // donc traité comme équivalent à "Transfert atteint" dans les deux cas
    // (jamais un courrier n'est "orphelin" entre Création et Transfert).
    // Chaque étape atteinte affiche la date RÉELLE de l'entrée d'historique
    // correspondante (voir WorkflowService pour les noms d'action exacts),
    // jamais une date inventée. Calculé côté composant plutôt qu'en
    // @php inline dans la vue : un bloc @php/@endphp à cet endroit précis
    // ne compilait pas correctement (restait littéralement "@php" dans le
    // HTML rendu, laissant $etapesParcours indéfinie — cause exacte non
    // identifiée avec certitude, contournée en évitant l'inline PHP dans
    // la vue pour ce calcul).
    #[Computed]
    public function etapesParcours(): array
    {
        $etapes = [
            'creation' => ['libelle' => __('Création'), 'action' => 'creation'],
            // 2026-10-23 — franchie quand le service ACCEPTE le transfert ;
            // 'service_valide_dga' reste reconnu pour les courriers validés
            // par la DGA avant la fin de ce routage.
            'transfert' => ['libelle' => __('Transfert'), 'action' => ['transfert_accepte', 'service_valide_dga']],
            'affecte' => ['libelle' => __('Affectation'), 'action' => 'affectation'],
            'en_traitement' => ['libelle' => __('Traitement'), 'action' => 'traitement_demarre'],
            'en_validation' => ['libelle' => __('Réponse'), 'action' => 'soumis_validation'],
            'traite' => ['libelle' => __('Clôture'), 'action' => 'validation_acceptee'],
        ];

        // 2026-10-30 (« since it is the responsable doing the traitement and
        // not the collaborateur, is this circuit good representing it ») —
        // quand le chef du service traite lui-même : « Prise en charge » (il
        // ne s'est pas fait affecter le courrier, il l'a pris) et pas de
        // « Réponse » (« Valider directement » clôt sans soumission).
        $chefTraiteLuiMeme = $this->courrier->affectationCourante !== null
            && $this->courrier->affectationCourante->user_id === $this->courrier->service?->responsable_id;

        if ($chefTraiteLuiMeme) {
            $etapes['affecte']['libelle'] = __('Prise en charge');
            $etapes['affecte']['precision'] = __('par le :titre', ['titre' => mb_strtolower($this->courrier->service->titreChef())]);
            unset($etapes['en_validation']);
        }

        $ordre = array_keys($etapes);

        // Un courrier actuellement sur une piste ANNEXE (attente
        // d'information, rejet) a quand même déjà franchi certaines des
        // étapes standard AVANT de bifurquer — jamais "rien n'est encore
        // arrivé" juste parce que le statut littéral ne figure pas dans
        // $ordre. Constaté par l'utilisateur (capture d'écran) : un
        // courrier "en_attente_de_transfert" affichait "Création" comme
        // NON franchie, alors que le courrier existe forcément déjà.
        // 'enregistre'/'en_attente_de_transfert'/'en_cours_de_transfert'
        // sont TOUS mappés sur "Transfert" — un courrier fraîchement créé
        // (jamais affecté) est toujours au moins "en train d'être
        // transféré" (ou l'a déjà été/n'en avait pas besoin), jamais avant.
        $statutEffectif = match ($this->courrier->statut) {
            'archive' => 'traite',
            'enregistre', 'en_attente_de_transfert', 'en_cours_de_transfert' => 'transfert',
            'en_attente_information', 'rejete' => $this->dernierePositionAvantPause(),
            // 2026-10-09 — extension du circuit (Signature DG / Trésorerie) :
            // pas d'étape dédiée dans cette barre fixe à 6 positions (évite
            // d'ajouter 2 segments "Non applicable" en permanence pour les
            // courriers qui n'empruntent jamais ce chemin) — reste affiché
            // sur "Réponse" (toujours dépassé l'étape 'en_validation' pour
            // arriver ici) tant que la clôture n'est pas encore atteinte.
            'en_attente_signature_dg', 'en_attente_tresorerie' => 'en_validation',
            default => $this->courrier->statut,
        };

        // Sans étape « Réponse », ce qui y menait reste sur « Traitement ».
        if ($chefTraiteLuiMeme && $statutEffectif === 'en_validation') {
            $statutEffectif = 'en_traitement';
        }

        $indexActuel = array_search($statutEffectif, $ordre, true);

        $resultat = [];

        foreach ($etapes as $statut => $definition) {
            $indexEtape = array_search($statut, $ordre, true);
            // "Création" (index 0) est TOUJOURS franchie pour une fiche
            // consultable — le courrier existe déjà, indépendamment de la
            // piste (standard ou annexe) sur laquelle il se trouve
            // actuellement.
            $atteinte = $indexEtape === 0 || ($indexActuel !== false && $indexEtape <= $indexActuel);
            // "Transfert" est un cas particulier : contrairement aux autres
            // étapes, `$statutEffectif === 'transfert'` reste vrai même une
            // fois le statut réel repassé à 'enregistre' (validé OU jamais
            // requis, voir plus haut) — cette étape n'est alors plus
            // "courante", juste franchie (sinon "Non applicable" ci-dessous
            // ne s'afficherait jamais pour un courrier sortant/sinistre).
            // Elle reste courante uniquement pendant le transfert LITTÉRAL,
            // ou si le courrier a été rejeté pendant qu'il y était (même
            // logique de "dernière position avant la pause" que les autres
            // étapes).
            $courante = $statut === 'transfert'
                ? in_array($this->courrier->statut, ['en_attente_de_transfert', 'en_cours_de_transfert'], true)
                    || ($this->courrier->statut === 'rejete' && $statutEffectif === 'transfert')
                : $statut === $statutEffectif;

            // Un pli confidentiel est clôturé par 'confidentiel_remis'
            // (2026-09-24, WorkflowService::cloturerConfidentiel()), pas par
            // une validation hiérarchique.
            $dateAtteinte = $atteinte
                ? ($this->courrier->historiques->first(fn ($entree) => in_array($entree->action, (array) $definition['action'], true))
                    ?? ($statut === 'traite' ? $this->courrier->historiques->firstWhere('action', 'confidentiel_remis') : null))?->created_at
                : null;

            $resultat[] = [
                'statut' => $statut,
                'libelle' => $definition['libelle'],
                // Ligne facultative sous la date (ex. « par le responsable »).
                'precision' => $definition['precision'] ?? null,
                'atteinte' => $atteinte,
                'courante' => $courante,
                // Sous-libellé affiché sous l'étape : date réelle si
                // atteinte et retrouvée dans l'historique, sinon un état
                // textuel (jamais une date fabriquée pour une étape non
                // encore franchie). "Transfert" est le seul cas où une
                // étape peut être atteinte SANS date ni être courante : un
                // courrier sortant ou un sinistre auto-routé (voir
                // RegistrationForm::enregistrer()) n'a jamais eu besoin de
                // transfert, "Non applicable" plutôt que le "En attente"
                // par défaut qui suggérerait à tort que quelque chose reste
                // encore à faire sur une étape déjà dépassée.
                'sousLibelle' => match (true) {
                    $dateAtteinte !== null => $dateAtteinte->format('d/m H:i'),
                    $courante => __('En cours'),
                    $statut === 'transfert' && $atteinte => __('Non applicable'),
                    $statut === 'en_validation' && ! $atteinte => __('En attente'),
                    $statut === 'traite' && ! $atteinte => __('Non clôturé'),
                    default => __('En attente'),
                },
            ];
        }

        return $resultat;
    }

    // Retrouve la dernière étape RÉELLEMENT franchie avant qu'un courrier ne
    // bascule sur "en_attente_information" ou "rejete" (deux statuts
    // atteignables depuis plusieurs points du circuit — voir
    // WorkflowService::TRANSITIONS) : parcourt l'historique (déjà trié du
    // plus récent au plus ancien, voir Courrier::historiques()) et retourne
    // le statut correspondant à la première action de progression
    // rencontrée, jamais une position inventée. "enregistre" par défaut si
    // aucune action de progression n'est trouvée (ex. mis en attente
    // immédiatement après affectation, sans étape intermédiaire tracée).
    private function dernierePositionAvantPause(): string
    {
        $actionVersEtape = [
            'creation' => 'creation',
            'transfert' => 'transfert',
            'service_valide_dga' => 'transfert',
            'transfert_accepte' => 'transfert',
            'affectation' => 'affecte',
            'reaffectation' => 'affecte',
            'traitement_demarre' => 'en_traitement',
            'validation_refusee' => 'en_traitement',
            'reprise' => 'en_traitement',
            'soumis_validation' => 'en_validation',
            'validation_acceptee' => 'traite',
        ];

        foreach ($this->courrier->historiques as $entree) {
            if (isset($actionVersEtape[$entree->action])) {
                return $actionVersEtape[$entree->action];
            }
        }

        return 'creation';
    }

    // Module 4 — demande explicite de l'utilisateur : "make the parcours du
    // courier work live in real time at each step" — WorkflowService diffuse
    // `statut.change` sur le même canal privé que ocrTermine()/
    // classementPropose() ci-dessous à chaque transition réelle (affecter/
    // transfert/acceptation/refus/toute transition simple), pour que la fiche
    // ouverte dans un AUTRE onglet/session (un collègue qui affecte/traite le
    // même courrier) se mette à jour sans rechargement manuel — pas
    // seulement pour l'auteur de l'action lui-même (qui voit déjà la mise à
    // jour via le cycle normal requête/rendu Livewire de son propre clic).
    // Toutes les propriétés #[Computed] qui dépendent du statut sont
    // invalidées, pas seulement $this->courrier — sinon "Parcours du
    // courrier" (etapesParcours) ou les boutons d'action (peutAffecter,
    // peutTraiter...) resteraient figés sur l'ancien statut jusqu'au
    // prochain rendu qui touche $this->courrier pour une autre raison.
    #[On('echo-private:courrier.{courrierId},.statut.change')]
    public function statutChange(): void
    {
        unset(
            $this->courrier,
            $this->etapesParcours,
            $this->peutModifier,
            $this->peutAffecter,
            $this->peutAffecterPremiere,
            $this->peutTraiterMoiMeme,
            $this->peutTraiter,
            $this->peutValider,
            $this->peutEnvoyerSignatureDg,
            $this->peutSignerDg,
            $this->peutTraiterTresorerie,
            $this->peutTransferer,
            $this->peutDeciderTransfert,
            $this->peutMarquerOriginalRemis,
            $this->peutSignerReception,
            $this->peutVoirContenu,
            $this->peutTransmettre,
            $this->unitesSubordonnees,
            $this->peutCloturerConfidentiel,
            $this->peutFixerDelai,
            $this->collaborateursDuService,
        );

        $this->preremplirAffectation();

        Flux::toast(text: __('Ce courrier a été mis à jour par un autre utilisateur — la fiche vient d\'être actualisée.'));
    }

    // Règle n°1 (notification de fin de job) sans wire:poll (Règle n°2) : le job
    // OCR diffuse `ocr.termine` sur le canal privé du courrier (Echo + Reverb),
    // la fiche invalide sa donnée mise en cache et se re-rend.
    #[On('echo-private:courrier.{courrierId},.ocr.termine')]
    public function ocrTermine(array $evenement = []): void
    {
        unset($this->courrier);

        $statut = $evenement['statut'] ?? null;

        Flux::toast(
            variant: $statut === 'reussi' ? 'success' : 'warning',
            text: match ($statut) {
                'reussi' => __('Numérisation terminée : texte extrait (confiance :c %).', ['c' => $evenement['confiance'] ?? '—']),
                'echec_qualite' => __('Numérisation terminée : scan jugé illisible, un nouveau scan est recommandé.'),
                default => __('Numérisation terminée : le traitement OCR a échoué (voir l\'historique).'),
            },
        );
    }

    // Diffusé par IndexCourrierJob à chaque changement de la proposition
    // (nouvelle proposition, ou proposition retirée après modification du courrier).
    #[On('echo-private:courrier.{courrierId},.classement.propose')]
    public function classementPropose(): void
    {
        unset($this->courrier);

        Flux::toast(
            variant: 'success',
            text: $this->courrier->classement_statut === 'propose'
                ? __('Le système propose un classement — à valider ou ignorer.')
                : __('Analyse automatique terminée.'),
        );
    }

    // Module 3 — "l'agent peut valider ou corriger la classification proposée".
    // Valider applique la proposition ; ignorer garde les valeurs saisies ; pour
    // corriger vers une troisième valeur, l'agent passe par "Modifier".
    public function validerClassement(): void
    {
        $courrier = $this->courrierModifiable('validerClassement');

        if ($courrier->classement_statut !== 'propose') {
            return;
        }

        DB::transaction(function () use ($courrier) {
            $changements = [];

            if ($courrier->type_document_propose && $courrier->type_document_propose !== $courrier->type_document) {
                $changements[] = "type « {$courrier->type_document} » → « {$courrier->type_document_propose} »";
                $courrier->type_document = $courrier->type_document_propose;
            }

            if ($courrier->service_propose_id && $courrier->service_propose_id !== $courrier->service_id) {
                $changements[] = 'service modifié';
                $courrier->service_id = $courrier->service_propose_id;
            }

            $courrier->classement_statut = 'valide';
            $courrier->save();

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => Auth::id(),
                'action' => 'classement_valide',
                'commentaire' => $changements ? implode(', ', $changements) : 'Proposition identique aux valeurs saisies.',
            ]);
        });

        unset($this->courrier);

        Flux::toast(variant: 'success', text: __('Classement validé.'));
    }

    public function ignorerClassement(): void
    {
        $courrier = $this->courrierModifiable('validerClassement');

        if ($courrier->classement_statut !== 'propose') {
            return;
        }

        $courrier->update(['classement_statut' => 'ignore']);

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => Auth::id(),
            'action' => 'classement_ignore',
            'commentaire' => 'Valeurs saisies conservées.',
        ]);

        unset($this->courrier);

        Flux::toast(text: __('Proposition ignorée, valeurs saisies conservées.'));
    }

    public function ajouterMotCle(): void
    {
        $courrier = $this->courrierModifiable('gererMotsCles');

        $this->validate(['nouveauMotCle' => ['required', 'string', 'min:2', 'max:60']]);

        $libelle = ClassificationService::libelle($this->nouveauMotCle);
        $motCle = MotCle::firstOrCreate(['libelle' => $libelle]);

        if (! $courrier->motsCles()->where('mot_cle_id', $motCle->id)->exists()) {
            $courrier->motsCles()->attach($motCle->id, ['source' => 'manuel']);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => Auth::id(),
                'action' => 'mot_cle_ajoute',
                'commentaire' => $libelle,
            ]);
        }

        $this->reset('nouveauMotCle');
        unset($this->courrier);
    }

    public function retirerMotCle(int $motCleId): void
    {
        $courrier = $this->courrierModifiable('gererMotsCles');
        $motCle = $courrier->motsCles()->where('mot_cle_id', $motCleId)->first();

        if (! $motCle) {
            return;
        }

        $courrier->motsCles()->detach($motCleId);

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => Auth::id(),
            'action' => 'mot_cle_retire',
            'commentaire' => $motCle->libelle,
        ]);

        unset($this->courrier);
    }

    // 2026-10-13, demande explicite de l'utilisateur ("isnt the another way
    // to that without selecting his name") — raccourci "Traiter moi-même" :
    // s'auto-affecte ET démarre le traitement en une seule action, sans
    // passer par le menu déroulant "Affecter à" (où se choisir soi-même
    // parmi ses propres collaborateurs était jugé peu naturel). Même
    // garde-fou Règle n°6 que affecter() : courrierPour() revérifie
    // l'autorisation serveur (CourrierPolicy::traiterMoiMeme()) avant
    // d'agir, jamais déduite de la simple visibilité du bouton.
    // 2026-10-29 — n'a plus de bouton ni de modale à part : c'est le choix
    // « Me l'affecter et commencer » de la modale « Affecter » (affecter()
    // ci-dessous), même délai préempli (mount()).
    public function traiterMoiMeme(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('traiterMoiMeme');

        $avecDelai = $this->delaiValeur !== null && Auth::user()->can('fixerDelai', $courrier);

        if ($avecDelai) {
            $this->validerDelai();
        }

        $this->executer(function () use ($workflow, $courrier, $avecDelai) {
            $workflow->affecter($courrier, Auth::user(), Auth::user());

            if ($avecDelai) {
                $workflow->fixerDelai($courrier->fresh(), $this->minutesDelai(), Auth::user());
            }

            $workflow->demarrerTraitement($courrier->fresh(), Auth::user());
        }, __('Courrier affecté et traitement démarré.'));
    }

    // Module 6 — première affectation. Le collaborateur choisi doit
    // appartenir au service du courrier (vérifié ici, jamais fait confiance
    // à l'ID posté — Règle n°6, même si la liste affichée est déjà filtrée).
    // 2026-10-29 — seulement une fois la décharge remise et signée
    // (CourrierPolicy::affecterPremiere()) ; « Me l'affecter et commencer »
    // passe par traiterMoiMeme() et ses propres contrôles.
    public function affecter(WorkflowService $workflow): void
    {
        if ($this->modeAffectation === 'moi') {
            $this->traiterMoiMeme($workflow);

            return;
        }

        $courrier = $this->courrierPour('affecterPremiere');
        $collaborateur = $this->collaborateurDuServiceOuEchec($courrier);

        if (! $collaborateur) {
            return;
        }

        // Délai du chronomètre (2026-09-24) : fixé dans la même action que
        // l'affectation quand le responsable en a le droit et l'a renseigné.
        $avecDelai = $this->delaiValeur !== null && Auth::user()->can('fixerDelai', $courrier);

        if ($avecDelai) {
            $this->validerDelai();
        }

        $this->executer(function () use ($workflow, $courrier, $collaborateur, $avecDelai) {
            $workflow->affecter($courrier, $collaborateur, Auth::user());

            if ($avecDelai) {
                $workflow->fixerDelai($courrier->fresh(), $this->minutesDelai(), Auth::user());
            }
        }, __('Courrier affecté à :nom.', ['nom' => $collaborateur->name]));
    }

    // Module 5 — nouveau délai à partir de maintenant, motif obligatoire
    // (traçabilité, Règle n°5).
    public function modifierDelai(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('fixerDelai');

        $this->validerDelai();
        $this->validate(['motifDelai' => ['required', 'string', 'min:5', 'max:500']], [], ['motifDelai' => __('motif du changement de délai')]);

        $this->executer(fn () => $workflow->fixerDelai($courrier, $this->minutesDelai(), Auth::user(), $this->motifDelai), __('Délai de traitement mis à jour.'));
    }

    private function validerDelai(): void
    {
        $this->validate([
            'delaiUnite' => ['required', 'in:heures,jours'],
            'delaiValeur' => ['required', 'integer', 'min:1', 'max:'.($this->delaiUnite === 'heures' ? 2000 : 365)],
        ], [], ['delaiValeur' => __('délai'), 'delaiUnite' => __('unité')]);
    }

    private function minutesDelai(): int
    {
        return $this->delaiValeur * ($this->delaiUnite === 'heures' ? 60 : 24 * 60);
    }

    // Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service
    // et acceptation") — l'accueil transfère directement au service choisi
    // dans la cascade (plus de DGA). Le service retenu est revérifié côté
    // serveur (pont Organisation, service actif avec un responsable) —
    // jamais fait confiance aux identifiants postés (Règle n°6).
    public function transferer(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('transferer');
        $service = $this->serviceChoisiOuEchec();

        if (! $service) {
            return;
        }

        $reussi = $this->executer(
            fn () => $workflow->transfererAuService($courrier, $service, Auth::user()),
            __('Courrier transféré au service :nom — en attente de son acceptation.', ['nom' => $service->nom]),
        );

        if ($reussi) {
            Flux::modal('transferer-modal')->close();
        }
    }

    // Le responsable du service destinataire accepte : numéro de décharge,
    // contenu désormais visible, agent prévenu qu'il peut remettre l'original.
    public function accepterTransfert(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('accepterTransfert');

        $reussi = $this->executer(
            fn () => $workflow->accepterTransfert($courrier, Auth::user()),
            __('Courrier accepté — l\'accueil est prévenu qu\'il peut vous remettre l\'original.'),
        );

        if ($reussi) {
            Flux::modal('accepter-transfert-modal')->close();
        }
    }

    // Refus motivé : le courrier retourne à l'accueil, sans service. Le
    // responsable perd alors normalement l'accès à la fiche — redirigé
    // plutôt que de voir une page 403 juste après un succès.
    public function refuserTransfert(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('refuserTransfert');

        $this->validate(['motifRefusTransfert' => ['required', 'string', 'min:5', 'max:500']], [], ['motifRefusTransfert' => __('motif')]);

        $reussi = $this->executer(
            fn () => $workflow->refuserTransfert($courrier, Auth::user(), $this->motifRefusTransfert),
            __('Courrier refusé — renvoyé à l\'accueil pour être réorienté.'),
        );

        if ($reussi) {
            Flux::modal('refuser-transfert-modal')->close();

            if (Auth::user()->cannot('view', $courrier->fresh())) {
                $this->redirect(route('dashboard'), navigate: true);
            }
        }
    }

    // 2026-10-23 — le responsable d'une entité (ex. une Direction) transmet
    // le courrier accepté à l'une de ses unités ; son chef le reçoit "À
    // affecter". L'unité postée est revérifiée parmi les unités réellement
    // rattachées (Règle n°6). Le transmetteur garde la consultation (voir
    // User::idsServicesSupervises()) : pas de redirection nécessaire.
    public function transmettre(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('transmettre');
        $unite = $this->unitesSubordonnees->firstWhere('id', $this->uniteTransmissionId);

        if ($unite === null) {
            $this->addError('uniteTransmissionId', __('Choisissez une unité rattachée à la vôtre.'));

            return;
        }

        $this->executer(
            fn () => $workflow->transmettreAUnite($courrier, $unite->service, Auth::user()),
            __('Courrier transmis à :unite (:chef).', ['unite' => $unite->name, 'chef' => $unite->service->responsable?->name]),
        );
    }

    // L'accueil a remis l'original papier au service qui l'a accepté.
    public function marquerOriginalRemis(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('marquerOriginalRemis');

        $reussi = $this->executer(
            fn () => $workflow->marquerOriginalRemis($courrier, Auth::user()),
            __('Original papier marqué comme remis au service.'),
        );

        if ($reussi) {
            Flux::modal('remise-original-modal')->close();
        }
    }

    // 2026-10-28 (« mettre le nom comme signature au cas où on ne veut pas
    // imprimer ») — le service signe la réception de l'original dans GEC.
    public function signerReceptionOriginal(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('signerReceptionOriginal');

        $this->executer(
            fn () => $workflow->signerReceptionOriginal($courrier, Auth::user()),
            __('Réception de l\'original signée — votre nom figure sur la décharge.'),
        );
    }

    // Module 3/9 — ouvre la modale, pré-remplie avec le dossier actuel s'il
    // y en a un (Règle n°6 — jamais fait confiance à un ID posté sans
    // revérifier au moment d'agir, voir classerDansDossier() ci-dessous).
    public function ouvrirClassement(): void
    {
        $this->authorize('classer', $this->courrier);

        $this->dossierAClasserId = $this->courrier->dossier_classement_id;

        Flux::modal('classement-dossier-modal')->show();
    }

    public function classerDansDossier(DossierClassementService $service): void
    {
        $courrier = Courrier::findOrFail($this->courrierId);
        $this->authorize('classer', $courrier);

        $dossier = $this->dossiersAccessibles->firstWhere('id', $this->dossierAClasserId);

        if (! $dossier || Auth::user()->cannot('view', $dossier)) {
            $this->addError('dossierAClasserId', __('Choisissez un dossier.'));

            return;
        }

        $service->classer($courrier, $dossier, Auth::user());

        $this->reset('dossierAClasserId');
        unset($this->courrier, $this->dossiersAccessibles);

        Flux::modal('classement-dossier-modal')->close();
        Flux::toast(variant: 'success', text: __('Courrier classé dans « :nom ».', ['nom' => $dossier->nom]));
    }

    public function retirerDuDossier(DossierClassementService $service): void
    {
        $courrier = Courrier::findOrFail($this->courrierId);
        $this->authorize('classer', $courrier);

        if ($courrier->dossier_classement_id !== null && Auth::user()->cannot('view', $courrier->dossierClassement)) {
            return;
        }

        $service->retirer($courrier, Auth::user());

        $this->reset('dossierAClasserId');
        unset($this->courrier, $this->dossiersAccessibles);

        Flux::modal('classement-dossier-modal')->close();
        Flux::toast(text: __('Courrier retiré du dossier.'));
    }

    // Module 9 — "Décharge" (2026-10-05).
    public function ouvrirDecharge(): void
    {
        $this->authorize('emettreDecharge', $this->courrier);

        $this->reset('dechargeEmprunteurId', 'dechargeMotif');

        Flux::modal('decharge-modal')->show();
    }

    public function emettreDecharge(WorkflowService $workflow): void
    {
        $courrier = Courrier::findOrFail($this->courrierId);
        $this->authorize('emettreDecharge', $courrier);

        $data = $this->validate([
            'dechargeEmprunteurId' => ['required', 'integer'],
            'dechargeMotif' => ['nullable', 'string', 'max:500'],
        ], [], ['dechargeEmprunteurId' => __('emprunteur'), 'dechargeMotif' => __('motif')]);

        // Règle n°6 — jamais confiance en un ID client : l'emprunteur doit
        // être un vrai utilisateur existant, pas une valeur devinée.
        $emprunteur = $this->utilisateursPourDecharge->firstWhere('id', $data['dechargeEmprunteurId']);

        if (! $emprunteur) {
            $this->addError('dechargeEmprunteurId', __('Choisissez un emprunteur.'));

            return;
        }

        try {
            $decharge = $workflow->emettreDecharge($courrier, $emprunteur, Auth::user(), $data['dechargeMotif'] ?: null);
        } catch (RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->reset('dechargeEmprunteurId', 'dechargeMotif');
        unset($this->courrier);

        Flux::modal('decharge-modal')->close();
        Flux::toast(variant: 'success', text: __('Décharge :ref émise.', ['ref' => $decharge->numero_reference]));
    }

    public function marquerDechargeRendue(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('emettreDecharge');

        $decharge = $courrier->dechargeActive;

        if (! $decharge) {
            return;
        }

        $workflow->marquerDechargeRendue($decharge, Auth::user());

        unset($this->courrier);

        Flux::toast(text: __('Original marqué comme rendu.'));
    }

    public function reaffecter(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('reaffecter');
        $collaborateur = $this->collaborateurDuServiceOuEchec($courrier);

        if (! $collaborateur) {
            return;
        }

        $this->validate(['motifReaffectation' => ['required', 'string', 'min:5', 'max:500']], [], ['motifReaffectation' => __('motif de la réaffectation')]);

        $this->executer(fn () => $workflow->reaffecter($courrier, $collaborateur, Auth::user(), $this->motifReaffectation), __('Courrier réaffecté à :nom.', ['nom' => $collaborateur->name]));
    }

    public function demarrerTraitement(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('traiter');

        $this->executer(fn () => $workflow->demarrerTraitement($courrier, Auth::user()), __('Traitement démarré.'));
    }

    public function soumettrePourValidation(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('soumettreValidation');

        $this->executer(fn () => $workflow->soumettrePourValidation($courrier, Auth::user(), $this->commentaireCirculation ?: null), __('Soumis pour validation.'));
    }

    // 2026-10-14, demande explicite de l'utilisateur ("why can he validate
    // directly" après "Traiter moi-même") — raccourci "soumettre + valider"
    // en une seule action, visible uniquement quand le traiteur ET le
    // validateur sont la même personne (CourrierPolicy::validerDirectement()).
    // Les DEUX transitions réelles restent appelées l'une après l'autre
    // (jamais une 3e transition raccourcie dans WorkflowService — le
    // circuit générique garde ses étapes et son historique complet, Règle
    // n°5, seul le NOMBRE DE CLICS change pour l'utilisateur).
    public function validerDirectement(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('validerDirectement');
        $commentaire = $this->commentaireCirculation ?: null;

        $this->executer(function () use ($workflow, $courrier, $commentaire) {
            $workflow->soumettrePourValidation($courrier, Auth::user(), $commentaire);
            $workflow->valider($courrier->fresh(), Auth::user(), $commentaire);
        }, __('Courrier validé — traité.'));
    }

    public function valider(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('valider');

        $this->executer(fn () => $workflow->valider($courrier, Auth::user(), $this->commentaireCirculation ?: null), __('Courrier validé — traité.'));
    }

    public function renvoyerPourCorrection(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('renvoyerCorrection');

        $this->validate(['motifRenvoi' => ['required', 'string', 'min:5', 'max:500']], [], ['motifRenvoi' => __('motif du renvoi')]);

        $this->executer(fn () => $workflow->renvoyerPourCorrection($courrier, Auth::user(), $this->motifRenvoi), __('Renvoyé pour correction.'), 'warning');
    }

    // 2026-10-09 — extension du circuit générique (Signature DG / Trésorerie).
    public function envoyerSignatureDg(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('envoyerSignatureDg');

        $this->executer(fn () => $workflow->envoyerPourSignatureDg($courrier, Auth::user(), $this->commentaireCirculation ?: null), __('Courrier envoyé en signature DG.'));
    }

    public function signerEtCloturer(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('signerDg');

        $this->executer(fn () => $workflow->signerEtCloturer($courrier, Auth::user(), $this->commentaireCirculation ?: null), __('Courrier signé et clôturé.'));
    }

    public function signerEtEnvoyerTresorerie(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('signerDg');

        $this->executer(fn () => $workflow->signerEtEnvoyerTresorerie($courrier, Auth::user(), $this->commentaireCirculation ?: null), __('Courrier signé et transmis à la trésorerie.'));
    }

    public function traiterParTresorerie(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('traiterTresorerie');

        $this->executer(fn () => $workflow->traiterParTresorerie($courrier, Auth::user(), $this->commentaireCirculation ?: null), __('Courrier traité par la trésorerie.'));
    }

    public function mettreEnAttente(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('mettreEnAttente');

        $this->validate(['motifAttente' => ['required', 'string', 'min:5', 'max:500']], [], ['motifAttente' => __('motif de la mise en attente')]);

        $this->executer(fn () => $workflow->mettreEnAttente($courrier, Auth::user(), $this->motifAttente), __('Courrier mis en attente d\'information.'), 'warning');
    }

    // 2026-10-30 (Module 7) — relance manuelle de la personne affectée.
    public function relancer(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('relancer');

        $this->validate(['messageRelance' => ['nullable', 'string', 'max:500']], [], ['messageRelance' => __('message de la relance')]);

        $nom = $courrier->affectationCourante?->collaborateur?->name;

        $this->executer(fn () => $workflow->relancer($courrier, Auth::user(), trim($this->messageRelance) ?: null), __('Relance envoyée à :nom.', ['nom' => $nom]));
    }

    public function reprendre(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('reprendre');

        $this->executer(fn () => $workflow->reprendre($courrier, Auth::user()), __('Traitement repris.'));
    }

    public function cloturerConfidentiel(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('cloturerConfidentiel');

        $this->executer(fn () => $workflow->cloturerConfidentiel($courrier, Auth::user()), __('Pli confidentiel marqué comme remis — il sera archivé automatiquement.'));
    }

    public function rejeter(WorkflowService $workflow): void
    {
        $courrier = $this->courrierPour('rejeter');

        $this->validate(['motifRejet' => ['required', 'string', 'min:5', 'max:500']], [], ['motifRejet' => __('motif du rejet')]);

        $this->executer(fn () => $workflow->rejeter($courrier, Auth::user(), $this->motifRejet), __('Courrier rejeté.'), 'danger');
    }

    // "Mettre en attente" et "Reprendre" restent ouverts au collaborateur
    // affecté ET au responsable (le spec ne réserve pas cette étape à la
    // seule hiérarchie) : c'est désormais CourrierPolicy::mettreEnAttente()/
    // reprendre() qui accepte la portée traiter() OU valider() (2026-09-23).

    // Toute transition passe par ici : la Policy a déjà autorisé l'ACTEUR,
    // mais WorkflowService revérifie que le STATUT actuel autorise encore la
    // transition (Règle n°6 — jamais fait confiance à l'état affiché côté
    // client, qui peut être périmé si un autre onglet/utilisateur a agi entre
    // temps).
    private function executer(\Closure $action, string $messageSucces, string $variantSucces = 'success'): bool
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            report($e);
            unset($this->courrier);
            // 2026-10-28 — chaque action du circuit passe par une modale
            // (<x-circuit.modale>) : refermée pour montrer la fiche actualisée.
            Flux::modals()->close();
            Flux::toast(variant: 'danger', text: __('Action impossible : la fiche a changé entre-temps, elle vient d\'être actualisée.'));

            return false;
        }

        $this->reinitialiserCirculation();

        Flux::modals()->close();
        Flux::toast(variant: $variantSucces, text: $messageSucces);

        return true;
    }

    private function collaborateurDuServiceOuEchec(Courrier $courrier): ?User
    {
        // 2026-10-13 — même correctif que collaborateursDuService() ci-dessus
        // (liste affichée), gardé synchronisé : "Responsable de service"
        // ajouté ici aussi, sinon ce garde-fou Règle n°6 rejette un choix
        // pourtant valide dans la liste affichée. 2026-10-29 — retiré des
        // deux côtés (voir collaborateursDuService()).
        $collaborateur = User::query()
            ->where('id', $this->collaborateurSelectionne)
            ->where('service_id', $courrier->service_id)
            ->whereHas('profil', fn ($q) => $q->where('nom', 'Collaborateur'))
            ->first();

        if (! $collaborateur) {
            $this->addError('collaborateurSelectionne', __('Choisissez un collaborateur du service.'));
        }

        return $collaborateur;
    }

    private function reinitialiserCirculation(): void
    {
        $this->reset('collaborateurSelectionne', 'modeAffectation', 'motifRefusTransfert', 'uniteTransmissionId', 'motifReaffectation', 'motifRenvoi', 'motifAttente', 'motifRejet', 'messageRelance', 'commentaireCirculation', 'delaiValeur', 'delaiUnite', 'motifDelai');
        $this->reinitialiserCascade();
        unset(
            $this->courrier,
            $this->etapesParcours,
            $this->peutModifier,
            $this->peutAffecter,
            $this->peutAffecterPremiere,
            $this->peutTraiterMoiMeme,
            $this->peutTraiter,
            $this->peutValider,
            $this->peutEnvoyerSignatureDg,
            $this->peutSignerDg,
            $this->peutTraiterTresorerie,
            $this->peutTransferer,
            $this->peutDeciderTransfert,
            $this->peutMarquerOriginalRemis,
            $this->peutSignerReception,
            $this->peutVoirContenu,
            $this->peutTransmettre,
            $this->unitesSubordonnees,
            $this->peutCloturerConfidentiel,
            $this->peutFixerDelai,
            $this->collaborateursDuService,
        );

        $this->preremplirAffectation();
    }

    // Module 6 — "affecte automatiquement au collaborateur le moins
    // chargé" : présélection RÉELLE du premier (moins chargé) de la liste
    // (bug réel du 2026-09-04, voir DECISIONS.md), et délai proposé = SLA
    // actuel du courrier (le sien, sinon celui de son type), que le
    // responsable garde ou ajuste.
    // 2026-10-30 — bug réel (capture : « Choisissez un collaborateur du
    // service. » alors que le menu affichait « Collaborateur Trésorerie »)
    // : la présélection n'avait lieu qu'au mount(). Toute action de la fiche
    // (ex. « Signer la réception », juste avant « Affecter ») la remettait à
    // vide via reinitialiserCirculation() ; le menu, dont l'option « Choisir
    // un collaborateur » est désactivée, montrait alors le premier nom sans
    // qu'aucune valeur ne soit envoyée. Rejouée après chaque action et
    // chaque mise à jour par un autre utilisateur, sans écraser un choix
    // déjà fait.
    private function preremplirAffectation(): void
    {
        if ($this->courrier->statut !== 'enregistre') {
            return;
        }

        if ($this->collaborateurSelectionne === null && ($premier = $this->collaborateursDuService->first())) {
            $this->collaborateurSelectionne = $premier['id'];
        }

        if ($this->delaiValeur === null) {
            $this->delaiValeur = SlaCalculatorService::delaiJours($this->courrier->sla_jours, $this->courrier->type_document);
        }
    }

    // Règle n°6 — droits revérifiés à chaque action, jamais déduits de l'état client.
    // $abilite : privilège d'action précis (2026-09-23), toujours cumulé
    // avec le droit de modifier ce courrier (voir CourrierPolicy).
    private function courrierModifiable(string $abilite = 'update'): Courrier
    {
        $courrier = Courrier::findOrFail($this->courrierId);

        $this->authorize($abilite, $courrier);

        return $courrier;
    }

    // Charge le courrier avec ce dont les policies affecter/traiter/valider
    // ont besoin (service, affectation courante) et vérifie l'habilité
    // demandée — jamais fait confiance à un bouton affiché côté client.
    private function courrierPour(string $abilite): Courrier
    {
        $courrier = Courrier::with(['service', 'affectationCourante'])->findOrFail($this->courrierId);

        $this->authorize($abilite, $courrier);

        return $courrier;
    }

    public function render()
    {
        return view('frontend::showCourrier');
    }
}
