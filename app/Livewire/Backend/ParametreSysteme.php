<?php

namespace App\Livewire\Backend;

use App\Livewire\Backend\Forms\CourrierForm;
use App\Models\ListeReference;
use App\Models\NumeroSequence;
use App\Models\Parametre;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// Module 1/5/7 — "Paramètres système" (2026-09-23, voir DECISIONS.md
// "Paramètres système configurables") : demande explicite de l'utilisateur
// — "those small things that usually need to be coded has to [be] done
// through the UI now", format du numéro de référence donné en exemple.
// Remplace config/gec.php (fichier) par App\Models\Parametre (ligne
// singleton en base, éditable ici). Gardé par le privilège
// `administration.sla` déjà existant (repurpose de l'entrée sidebar
// "SLA & Alertes", jusque-là "Bientôt disponible") : même privilège,
// mêmes profils par défaut (Administrateur seul) — pas de nouvelle clé.
//
// "Group A / Group B" (2026-09-24, voir DECISIONS.md "Paramètres système
// configurables — Groupe A/B") : Group A = 5 réglages numériques
// supplémentaires (ex-`const` codées en dur) ; Group B = gestion complète
// (ajout/renommage/activation/ordre) des 3 listes de référence Module 1
// (type de document, mode de réception, priorité), sur App\Models\ListeReference.
// Les actions Group B prennent effet immédiatement (comme UserList::ajouter()),
// contrairement aux réglages numériques ci-dessus qui restent groupés
// derrière le bouton "Enregistrer" unique.
#[Title('Paramètres système')]
class ParametreSysteme extends Component
{
    public string $numeroReferencePrefixe = '';

    public int $numeroReferenceChiffresSequence = 6;

    public int $slaJoursDefaut = 10;

    public int $slaSeuilRisqueJours = 2;

    public int $slaRelanceJours = 2;

    // Nullable = escalade désactivée (2026-10-05, Module 7, "escalade...
    // configurable selon le niveau de retard") — contrairement aux 3
    // réglages SLA ci-dessus, obligatoires, celui-ci est un champ vide
    // valide (Rule n°2 — primitif, jamais un objet, une chaîne vide reste
    // un primitif comme les autres).
    public string $slaEscaladeJours = '';

    // Un champ par type de document RÉEL (CourrierForm::typesDocument(),
    // Group B ci-dessous — désormais une vraie liste gérée depuis cette
    // page, plus une constante figée). Valeur vide = pas de délai
    // spécifique pour ce type, repli sur slaJoursDefaut (voir
    // Parametre::delaiSlaPour()). Uniquement des primitifs (Règle n°2) :
    // tableau [type => chaîne numérique|''].
    public array $slaParType = [];

    // ===== Group A — anciennes public const codées en dur =====
    public int $niveauConfidentialiteMax = 5;

    public int $scanResolutionMinimale = 600;

    public int $ocrConfianceMinimale = 55;

    public int $ocrLongueurMinimaleTexte = 20;

    public int $dashboardDelaiMoyenPeriodeJours = 90;

    // ===== Group B — ajout d'une nouvelle valeur par liste =====
    public string $nouveauTypeDocument = '';

    public string $nouveauModeReception = '';

    public string $nouvellePriorite = '';

    // ===== Group B — renommage en ligne (une seule ligne à la fois) =====
    public ?int $renommageListeId = null;

    public string $renommageListeValeur = '';

    public function mount(): void
    {
        $this->verifierAcces();

        $parametres = Parametre::actuel();

        $this->numeroReferencePrefixe = $parametres->numero_reference_prefixe;
        $this->numeroReferenceChiffresSequence = $parametres->numero_reference_chiffres_sequence;
        $this->slaJoursDefaut = $parametres->sla_jours_defaut;
        $this->slaSeuilRisqueJours = $parametres->sla_seuil_risque_jours;
        $this->slaRelanceJours = $parametres->sla_relance_jours;
        $this->slaEscaladeJours = $parametres->sla_escalade_jours !== null ? (string) $parametres->sla_escalade_jours : '';
        $this->niveauConfidentialiteMax = $parametres->niveau_confidentialite_max;
        $this->scanResolutionMinimale = $parametres->scan_resolution_minimale;
        $this->ocrConfianceMinimale = $parametres->ocr_confiance_minimale;
        $this->ocrLongueurMinimaleTexte = $parametres->ocr_longueur_minimale_texte;
        $this->dashboardDelaiMoyenPeriodeJours = $parametres->dashboard_delai_moyen_periode_jours;

        $parType = $parametres->sla_par_type ?? [];
        foreach (CourrierForm::typesDocument() as $type) {
            $this->slaParType[$type] = isset($parType[$type]) ? (string) $parType[$type] : '';
        }
    }

    // Règle n°6 — mount() n'est PAS rappelé entre deux actions Livewire sur
    // le même composant monté : chaque action mutante doit revérifier le
    // privilège elle-même plutôt que de compter sur le contrôle fait à mount().
    private function verifierAcces(): void
    {
        abort_unless(Auth::user()->hasPrivilege('administration.sla'), 403);
    }

    // Aperçu du PROCHAIN numéro réel — lecture seule de la séquence de
    // l'année en cours (jamais incrémentée ici, voir NumeroSequence,
    // consommée uniquement par NumeroReferenceGenerator::generer()) —
    // recalculé à chaque frappe grâce à wire:model.live sur les 2 champs
    // concernés, sans toucher à la vraie séquence.
    #[Computed]
    public function apercuProchainNumero(): string
    {
        $annee = (int) now()->year;
        $dernier = NumeroSequence::where('annee', $annee)->value('dernier_numero') ?? 0;
        $prochain = $dernier + 1;

        $prefixe = trim($this->numeroReferencePrefixe) ?: 'GEC';
        $chiffres = max(1, min(10, $this->numeroReferenceChiffresSequence));

        return sprintf('%s-%d-%0'.$chiffres.'d', $prefixe, $annee, $prochain);
    }

    #[Computed]
    public function listesTypeDocument()
    {
        return ListeReference::toutes(ListeReference::TYPE_DOCUMENT);
    }

    #[Computed]
    public function listesModeReception()
    {
        return ListeReference::toutes(ListeReference::MODE_RECEPTION);
    }

    #[Computed]
    public function listesPriorite()
    {
        return ListeReference::toutes(ListeReference::PRIORITE);
    }

    public function enregistrer(): void
    {
        $this->verifierAcces();

        $data = $this->validate([
            'numeroReferencePrefixe' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'numeroReferenceChiffresSequence' => ['required', 'integer', 'min:3', 'max:10'],
            'slaJoursDefaut' => ['required', 'integer', 'min:1', 'max:365'],
            'slaSeuilRisqueJours' => ['required', 'integer', 'min:0', 'max:30'],
            'slaRelanceJours' => ['required', 'integer', 'min:1', 'max:30'],
            'slaEscaladeJours' => ['nullable', 'integer', 'min:1', 'max:365'],
            'slaParType' => ['array'],
            'slaParType.*' => ['nullable', 'integer', 'min:1', 'max:365'],
            'niveauConfidentialiteMax' => ['required', 'integer', 'min:1', 'max:20'],
            'scanResolutionMinimale' => ['required', 'integer', 'min:100', 'max:5000'],
            'ocrConfianceMinimale' => ['required', 'integer', 'min:0', 'max:100'],
            'ocrLongueurMinimaleTexte' => ['required', 'integer', 'min:0', 'max:1000'],
            'dashboardDelaiMoyenPeriodeJours' => ['required', 'integer', 'min:1', 'max:730'],
        ], [], [
            'numeroReferencePrefixe' => __('préfixe'),
            'numeroReferenceChiffresSequence' => __('nombre de chiffres'),
            'slaJoursDefaut' => __('délai par défaut'),
            'slaSeuilRisqueJours' => __('seuil de risque'),
            'slaRelanceJours' => __('délai de relance'),
            'slaEscaladeJours' => __('délai avant escalade'),
            'niveauConfidentialiteMax' => __('niveau de confidentialité maximum'),
            'scanResolutionMinimale' => __('résolution minimale'),
            'ocrConfianceMinimale' => __('confiance OCR minimale'),
            'ocrLongueurMinimaleTexte' => __('longueur de texte minimale'),
            'dashboardDelaiMoyenPeriodeJours' => __('période du délai moyen'),
        ]);

        // Lignes vides retirées — un type sans valeur saisie retombe sur le
        // délai par défaut (voir Parametre::delaiSlaPour()), jamais stocké
        // comme 0 ou une chaîne vide.
        $parType = collect($data['slaParType'])
            ->filter(fn ($valeur) => $valeur !== null && $valeur !== '')
            ->map(fn ($valeur) => (int) $valeur)
            ->all();

        Parametre::actuel()->update([
            'numero_reference_prefixe' => strtoupper($data['numeroReferencePrefixe']),
            'numero_reference_chiffres_sequence' => $data['numeroReferenceChiffresSequence'],
            'sla_jours_defaut' => $data['slaJoursDefaut'],
            'sla_seuil_risque_jours' => $data['slaSeuilRisqueJours'],
            'sla_relance_jours' => $data['slaRelanceJours'],
            'sla_escalade_jours' => $data['slaEscaladeJours'] !== null && $data['slaEscaladeJours'] !== '' ? (int) $data['slaEscaladeJours'] : null,
            'sla_par_type' => $parType,
            'niveau_confidentialite_max' => $data['niveauConfidentialiteMax'],
            'scan_resolution_minimale' => $data['scanResolutionMinimale'],
            'ocr_confiance_minimale' => $data['ocrConfianceMinimale'],
            'ocr_longueur_minimale_texte' => $data['ocrLongueurMinimaleTexte'],
            'dashboard_delai_moyen_periode_jours' => $data['dashboardDelaiMoyenPeriodeJours'],
        ]);

        // Le numéro de référence déjà généré NE CHANGE JAMAIS
        // (specifications-modules-GEC.md, "une fois confirmé ne changera
        // plus jamais") — seul l'AFFICHAGE du prochain numéro change,
        // jamais la séquence déjà consommée en base.
        Parametre::invaliderCache();

        $this->numeroReferencePrefixe = strtoupper($data['numeroReferencePrefixe']);
        unset($this->apercuProchainNumero);

        Flux::toast(variant: 'success', text: __('Paramètres enregistrés.'));
    }

    // ===== Group B — actions sur les listes de référence =====

    private function creerValeurListe(string $type, string $valeur, string $proprieteAReinitialiser): void
    {
        $this->verifierAcces();

        $data = Validator::make(
            ['valeur' => trim($valeur)],
            ['valeur' => [
                'required', 'string', 'max:255',
                Rule::unique('listes_reference', 'valeur')->where('type', $type),
            ]],
            [],
            ['valeur' => __('valeur')],
        )->validate();

        $ordreMax = ListeReference::where('type', $type)->max('ordre');

        ListeReference::create([
            'type' => $type,
            'valeur' => $data['valeur'],
            'ordre' => $ordreMax === null ? 0 : $ordreMax + 1,
            'actif' => true,
            'protege' => false,
        ]);

        $this->reset($proprieteAReinitialiser);

        Flux::toast(variant: 'success', text: __('Valeur ajoutée.'));
    }

    public function ajouterTypeDocument(): void
    {
        $this->creerValeurListe(ListeReference::TYPE_DOCUMENT, $this->nouveauTypeDocument, 'nouveauTypeDocument');
    }

    public function ajouterModeReception(): void
    {
        $this->creerValeurListe(ListeReference::MODE_RECEPTION, $this->nouveauModeReception, 'nouveauModeReception');
    }

    public function ajouterPriorite(): void
    {
        $this->creerValeurListe(ListeReference::PRIORITE, $this->nouvellePriorite, 'nouvellePriorite');
    }

    public function ouvrirRenommage(int $id): void
    {
        $this->verifierAcces();

        $liste = ListeReference::findOrFail($id);

        if ($liste->protege) {
            return;
        }

        $this->renommageListeId = $liste->id;
        $this->renommageListeValeur = $liste->valeur;
    }

    public function annulerRenommage(): void
    {
        $this->reset('renommageListeId', 'renommageListeValeur');
    }

    public function enregistrerRenommage(): void
    {
        $this->verifierAcces();

        $liste = ListeReference::findOrFail($this->renommageListeId);

        // Défense en profondeur : le bouton "Renommer" est déjà masqué côté
        // vue pour une valeur `protege` (ex. "Sinistre", "email", "fax") —
        // une logique métier par chaîne exacte en dépend ailleurs
        // (CourrierForm::estUnSinistre(), OCR, flux confidentiel).
        abort_if($liste->protege, 403);

        $data = Validator::make(
            ['valeur' => trim($this->renommageListeValeur)],
            ['valeur' => [
                'required', 'string', 'max:255',
                Rule::unique('listes_reference', 'valeur')->where('type', $liste->type)->ignore($liste->id),
            ]],
            [],
            ['valeur' => __('valeur')],
        )->validate();

        $liste->update(['valeur' => $data['valeur']]);

        $this->reset('renommageListeId', 'renommageListeValeur');

        Flux::toast(variant: 'success', text: __('Valeur renommée.'));
    }

    // Désactivation = retirée des NOUVEAUX formulaires uniquement, jamais
    // des courriers déjà enregistrés avec cette valeur (voir DECISIONS.md) —
    // mais jamais la DERNIÈRE valeur active d'une liste, sous peine de
    // Rule::in([]) qui rejetterait tout nouvel enregistrement.
    public function basculerActif(int $id): void
    {
        $this->verifierAcces();

        $liste = ListeReference::findOrFail($id);

        if ($liste->actif) {
            $resteAuMoinsUneActive = ListeReference::where('type', $liste->type)
                ->where('actif', true)
                ->where('id', '!=', $liste->id)
                ->exists();

            if (! $resteAuMoinsUneActive) {
                Flux::toast(variant: 'danger', text: __('Impossible de désactiver la dernière valeur active de cette liste.'));

                return;
            }
        }

        $liste->update(['actif' => ! $liste->actif]);
    }

    public function deplacerValeur(int $id, string $direction): void
    {
        $this->verifierAcces();

        $liste = ListeReference::findOrFail($id);

        $voisin = ListeReference::where('type', $liste->type)
            ->where('ordre', $direction === 'haut' ? '<' : '>', $liste->ordre)
            ->orderBy('ordre', $direction === 'haut' ? 'desc' : 'asc')
            ->first();

        if (! $voisin) {
            return;
        }

        [$ordreListe, $ordreVoisin] = [$liste->ordre, $voisin->ordre];
        $liste->update(['ordre' => $ordreVoisin]);
        $voisin->update(['ordre' => $ordreListe]);
    }

    public function render()
    {
        return view('frontend::parametreSysteme');
    }
}
