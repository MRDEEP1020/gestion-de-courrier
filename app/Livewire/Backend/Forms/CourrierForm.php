<?php

namespace App\Livewire\Backend\Forms;

use App\Models\ListeReference;
use App\Models\User;
use App\Services\ClassificationService;
use Illuminate\Validation\Rule;
use Livewire\Form;

class CourrierForm extends Form
{
    // Module 1 — Form Object pour l'enregistrement courrier (Règle n°2 CONVENTIONS.md :
    // saisie complexe => Form Object plutôt que des propriétés multipliées sur le composant).
    // Uniquement des types primitifs, comme l'exige la Règle n°2.

    // Module 1 — catégories courantes proposées en liste déroulante pour
    // "Type de document" (demande explicite de l'utilisateur, 2026-09-08 :
    // liste au lieu d'un texte libre), gérable depuis "Paramètres système"
    // ("Group B", 2026-09-24, voir DECISIONS.md "Paramètres système
    // configurables — Groupe A/B" — ex-`const TYPES_DOCUMENT`).
    // Valeurs = texte affiché tel quel (comme objet/expediteur_nom, jamais
    // traduites via __() — voir RegistrationForm/EditForm blade), PAS des
    // clés d'énumération : gardé cohérent avec le reste de l'appli qui
    // compare/affiche type_document en texte brut
    // (ClassificationService::normaliser(), CourrierList, règles de
    // classement...). Volontairement PAS une contrainte Rule::in() dans
    // rules() ci-dessous : une proposition de classement (Module 3, texte
    // libre configuré par un admin dans une règle), une valeur désactivée
    // depuis "Paramètres système", ou une valeur historique peut rester
    // hors de cette liste sans jamais bloquer l'enregistrement — seul le
    // choix à la saisie est restreint, pas la donnée elle-même. "Sinistre"
    // reste `protege` dans listes_reference : estUnSinistre() ci-dessous en
    // dépend (comparaison "contient sinistre").
    public static function typesDocument(): array
    {
        return ListeReference::valeursActives(ListeReference::TYPE_DOCUMENT);
    }

    public string $sens = 'entrant';

    public string $date_mouvement = '';

    public ?string $expediteur_nom = null;

    public ?string $expediteur_organisation = null;

    // Coordonnées de contact séparées par type — demande explicite de
    // l'utilisateur (2026-09-17) : remplace l'ancien champ libre unique
    // `expediteur_coordonnees` (voir DECISIONS.md), même raisonnement que
    // la séparation RC/NIU juste en dessous.
    public ?string $expediteur_telephone = null;

    public ?string $expediteur_email = null;

    public ?string $expediteur_adresse = null;

    // Identifiants légaux (Cameroun), distincts des coordonnées de contact
    // — demande explicite de l'utilisateur (2026-09-07).
    public ?string $expediteur_rc = null;

    public ?string $expediteur_niu = null;

    public ?string $destinataire = null;

    public string $objet = '';

    public string $type_document = '';

    // Module 3 — sous-type "Sinistre" confirmé dès la phase 1 : matériel |
    // corporel. N'a de sens que si type_document désigne un sinistre.
    public ?string $sous_type_sinistre = null;

    public string $mode_reception = 'depot_physique';

    public ?int $service_id = null;

    public string $priorite = 'normale';

    // Entier 1-5 depuis le 2026-09-21 (demande explicite de l'utilisateur —
    // "numbers 1,2,3,4,5 etc", voir User::NIVEAU_CONFIDENTIALITE_MAX).
    public int $confidentialite = 1;

    public function rules(): array
    {
        return [
            'sens' => ['required', Rule::in(['entrant', 'sortant'])],
            'date_mouvement' => ['required', 'date'],
            'expediteur_nom' => ['nullable', 'string', 'max:255'],
            'expediteur_organisation' => ['nullable', 'string', 'max:255'],
            'expediteur_telephone' => ['nullable', 'string', 'max:255'],
            'expediteur_email' => ['nullable', 'email', 'max:255'],
            'expediteur_adresse' => ['nullable', 'string', 'max:255'],
            'expediteur_rc' => ['nullable', 'string', 'max:255'],
            'expediteur_niu' => ['nullable', 'string', 'max:255'],
            'destinataire' => ['nullable', 'string'],
            'objet' => ['required', 'string', 'max:255'],
            'type_document' => ['required', 'string', 'max:255'],
            // Requis uniquement quand le type désigne un sinistre (spec Module 3,
            // "dès la phase 1") — comparaison insensible casse/accents, cohérente
            // avec celle du moteur de classement (ClassificationService).
            // Note : 'nullable' et 'required' ne se combinent pas en un seul
            // tableau de règles (Laravel ignore les règles implicites, dont
            // required_if, dès que 'nullable' est présent et la valeur nulle) —
            // deux jeux de règles distincts selon le cas, plutôt que Rule::requiredIf().
            'sous_type_sinistre' => $this->estUnSinistre()
                ? ['required', Rule::in(['materiel', 'corporel'])]
                : ['nullable', Rule::in(['materiel', 'corporel'])],
            'mode_reception' => ['required', Rule::in(ListeReference::valeursActives(ListeReference::MODE_RECEPTION))],
            // 2026-09-15 (voir DECISIONS.md, synchronisation SRS-GEC.pdf) : le
            // service n'est plus saisi par l'agent pour un courrier ENTRANT —
            // c'est le DGA/ADJ DGA qui le choisit au moment de valider le
            // transfert (Module 4). Le sortant garde un service requis,
            // inchangé. Même raison que sous_type_sinistre ci-dessus pour ne
            // pas combiner 'nullable' et 'required' dans un seul tableau.
            'service_id' => $this->sens === 'sortant'
                ? ['required', 'integer', 'exists:services,id']
                : ['nullable', 'integer', 'exists:services,id'],
            'priorite' => ['required', Rule::in(ListeReference::valeursActives(ListeReference::PRIORITE))],
            'confidentialite' => ['required', 'integer', 'between:1,'.User::niveauConfidentialiteMax()],
        ];
    }

    public function estUnSinistre(): bool
    {
        return str_contains(ClassificationService::normaliser($this->type_document), 'sinistre');
    }
}
