{{-- Actions du circuit de traitement dans l'en-tête de la fiche, à côté de
     « Télécharger » / « Imprimer » (2026-10-29, « Circuit de traitement :
     bouton au niveau de « téléchargement » avec condition à chaque étape du
     circuit », précisé : « i wanted the circuit to appear beside those
     buttons rather than in the tab since they are hidden there ») —
     auparavant dans l'onglet « Circuit de traitement », que les utilisateurs
     ne voyaient pas. Chaque bouton garde EXACTEMENT sa condition d'avant
     (étape du courrier + droit de l'utilisateur, Policy côté serveur) : on
     ne voit que les actions de l'étape en cours qu'on a le droit de faire.
     Actions principales en boutons ; secondaires (délai, réaffectation,
     attente, rejet) dans le menu « Autres actions ». Chaque bouton ouvre sa
     modale (partials/circuit-modales.blade.php, hors des onglets).
     2026-10-29 (« it should appear only when a condition is met not all of
     it like that ») — une étape à la fois après l'acceptation : remise de
     l'original (accueil) → signature de la réception (service) →
     « Affecter » (un seul bouton, « Me l'affecter et commencer » dans sa
     modale) ; « Autres actions » attend aussi la fin de la décharge. --}}
@php
    $dechargeEnAttente = $courrier->statut === 'enregistre' && ! $courrier->dechargeTerminee();
    $transfertCircuit = $courrier->dernierTransfert;
    $actionDelai = $courrier->statut !== 'enregistre' && $this->peutFixerDelai;
    $actionReaffecter = $this->droits['reaffecter'] && $courrier->affectationCourante && in_array($courrier->statut, ['affecte', 'en_traitement', 'en_validation', 'en_attente_information']);
    $actionAttente = $this->droits['mettreEnAttente'] && in_array($courrier->statut, ['affecte', 'en_traitement', 'en_validation'], true);
    $actionRejeter = $this->droits['rejeter'] && ! in_array($courrier->statut, ['traite', 'archive', 'rejete'], true);
    // 2026-10-30 (capture : collaborateur avec « Autres actions » ne contenant
    // que « Mettre en attente », choix « bouton direct, pas de menu ») — une
    // seule action secondaire : bouton direct ; deux ou plus : menu.
    $actionsSecondaires = $dechargeEnAttente ? [] : array_values(array_filter([
        $actionDelai ? ['modale' => 'delai-modal', 'icone' => 'clock', 'libelle' => __('Modifier le délai'), 'danger' => false] : null,
        $actionReaffecter ? ['modale' => 'reaffecter-modal', 'icone' => 'arrow-path', 'libelle' => __('Réaffecter'), 'danger' => false] : null,
        $actionAttente ? ['modale' => 'attente-modal', 'icone' => 'pause-circle', 'libelle' => __('Mettre en attente'), 'danger' => false] : null,
        $actionRejeter ? ['modale' => 'rejeter-modal', 'icone' => 'no-symbol', 'libelle' => __('Rejeter'), 'danger' => true] : null,
    ]));
@endphp

{{-- Module 1/4 — l'accueil transfère au service. --}}
@if ($courrier->statut === 'en_attente_de_transfert' && $this->peutTransferer)
    <flux:modal.trigger name="transferer-modal">
        <flux:button variant="primary" icon="arrow-right-circle">{{ __('Transférer au service') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- Le responsable du service destinataire accepte ou refuse. --}}
@if ($courrier->statut === 'en_cours_de_transfert' && $this->peutDeciderTransfert)
    <flux:modal.trigger name="accepter-transfert-modal">
        <flux:button variant="primary" icon="check">{{ __('Accepter le courrier') }}</flux:button>
    </flux:modal.trigger>
    <flux:modal.trigger name="refuser-transfert-modal">
        <flux:button variant="outline" icon="x-mark">{{ __('Refuser') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- Décharge : remise de l'original (accueil), signature de la réception (service). --}}
@if ($transfertCircuit?->estAccepte() && $this->peutMarquerOriginalRemis)
    <flux:modal.trigger name="remise-original-modal">
        <flux:button variant="primary" icon="document-check">{{ __('Marquer l\'original comme remis') }}</flux:button>
    </flux:modal.trigger>
@endif
@if ($transfertCircuit?->estAccepte() && $this->peutSignerReception)
    <flux:modal.trigger name="signer-reception-modal">
        <flux:button variant="primary" icon="pencil-square">{{ __('Signer la réception') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- Module 1/9 — pli confidentiel remis directement à son destinataire. --}}
@if ($this->peutCloturerConfidentiel)
    <flux:modal.trigger name="confidentiel-remis-modal">
        <flux:button variant="primary" icon="lock-closed">{{ __('Marquer comme remis') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- 2026-10-23 — transmission à une unité placée sous l'entité. --}}
@if ($this->peutTransmettre && $this->unitesSubordonnees->isNotEmpty())
    <flux:modal.trigger name="transmettre-modal">
        <flux:button variant="primary" icon="arrow-down-right">{{ __('Transmettre à une unité') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- Module 6 — première affectation, une fois la décharge remise et signée. --}}
@if ($this->peutAffecterPremiere)
    <flux:modal.trigger name="affecter-modal">
        <flux:button variant="primary" icon="user-plus">{{ __('Affecter') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- Module 4 — collaborateur affecté : démarrer / soumettre. --}}
@if ($this->peutTraiter && $courrier->statut === 'affecte')
    <flux:modal.trigger name="demarrer-modal">
        <flux:button variant="primary" icon="play">{{ __('Démarrer le traitement') }}</flux:button>
    </flux:modal.trigger>
@elseif ($this->peutTraiter && $courrier->statut === 'en_traitement' && ($this->droits['validerDirectement'] || $this->droits['soumettreValidation']))
    @if ($this->droits['validerDirectement'])
        <flux:modal.trigger name="valider-directement-modal">
            <flux:button variant="primary" icon="check-badge">{{ __('Valider directement') }}</flux:button>
        </flux:modal.trigger>
    @endif
    {{-- 2026-10-29 — absent pour le chef qui traite lui-même (CourrierPolicy::traiteLuiMeme()). --}}
    @if ($this->droits['soumettreValidation'])
        <flux:modal.trigger name="soumettre-modal">
            <flux:button :variant="$this->droits['validerDirectement'] ? 'outline' : 'primary'" icon="paper-airplane">{{ __('Soumettre pour validation') }}</flux:button>
        </flux:modal.trigger>
    @endif
@endif

{{-- Module 4 — responsable de service : valider, signature DG, renvoyer. --}}
@if ($courrier->statut === 'en_validation' && $this->peutValider)
    <flux:modal.trigger name="valider-modal">
        <flux:button variant="primary" icon="check-badge">{{ __('Valider') }}</flux:button>
    </flux:modal.trigger>
    @if ($this->peutEnvoyerSignatureDg)
        <flux:modal.trigger name="signature-dg-modal">
            <flux:button variant="outline" icon="paper-airplane">{{ __('Envoyer en signature DG') }}</flux:button>
        </flux:modal.trigger>
    @endif
    @if ($this->droits['renvoyerCorrection'])
        <flux:modal.trigger name="renvoyer-modal">
            <flux:button variant="outline" icon="arrow-uturn-left">{{ __('Renvoyer pour correction') }}</flux:button>
        </flux:modal.trigger>
    @endif
@endif

{{-- 2026-10-09 — DG : signe et clôture, ou signe et transmet à la trésorerie. --}}
@if ($courrier->statut === 'en_attente_signature_dg' && $this->peutSignerDg)
    <flux:modal.trigger name="signer-cloturer-modal">
        <flux:button variant="primary" icon="identification">{{ __('Signer et clôturer') }}</flux:button>
    </flux:modal.trigger>
    <flux:modal.trigger name="signer-tresorerie-modal">
        <flux:button variant="outline" icon="banknotes">{{ __('Signer et transmettre à la trésorerie') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- 2026-10-09 — Trésorerie : clôture après le décaissement. --}}
@if ($courrier->statut === 'en_attente_tresorerie' && $this->peutTraiterTresorerie)
    <flux:modal.trigger name="tresorerie-modal">
        <flux:button variant="primary" icon="banknotes">{{ __('Marquer comme traité') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- Reprise après une mise en attente. --}}
@if ($this->droits['reprendre'] && $courrier->statut === 'en_attente_information')
    <flux:modal.trigger name="reprendre-modal">
        <flux:button variant="primary" icon="play">{{ __('Reprendre le traitement') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- 2026-10-30 (Module 7, « on doit pouvoir les relancer manuellement ») —
     bouton visible, pas dans « Autres actions » : le chef suit un courrier
     affecté à quelqu'un d'autre (CourrierPolicy::relancer()). --}}
@if ($this->droits['relancer'])
    <flux:modal.trigger name="relancer-modal">
        <flux:button variant="outline" icon="bell-alert">{{ __('Relancer') }}</flux:button>
    </flux:modal.trigger>
@endif

{{-- Actions secondaires, mêmes conditions qu'avant. --}}
@if (count($actionsSecondaires) === 1)
    @php $seule = $actionsSecondaires[0]; @endphp
    <flux:modal.trigger :name="$seule['modale']">
        <flux:button :variant="$seule['danger'] ? 'danger' : 'outline'" :icon="$seule['icone']">{{ $seule['libelle'] }}</flux:button>
    </flux:modal.trigger>
@elseif (count($actionsSecondaires) > 1)
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="outline" icon="ellipsis-horizontal" icon:trailing="chevron-down">{{ __('Autres actions') }}</flux:button>
        <flux:menu>
            @foreach ($actionsSecondaires as $action)
                @if ($action['danger'])
                    @if (! $loop->first)
                        <flux:menu.separator />
                    @endif
                    <flux:menu.item :icon="$action['icone']" variant="danger" x-on:click="$flux.modal('{{ $action['modale'] }}').show()">{{ $action['libelle'] }}</flux:menu.item>
                @else
                    <flux:menu.item :icon="$action['icone']" x-on:click="$flux.modal('{{ $action['modale'] }}').show()">{{ $action['libelle'] }}</flux:menu.item>
                @endif
            @endforeach
        </flux:menu>
    </flux:dropdown>
@endif
