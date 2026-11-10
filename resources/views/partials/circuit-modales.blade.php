{{-- Modales des actions du circuit de traitement (2026-10-29) — sorties de
     l'onglet « Circuit de traitement » : leurs boutons sont désormais dans
     l'en-tête de la fiche (partials/circuit-actions.blade.php), et une
     modale placée dans un onglet masqué (x-show) ne pourrait pas s'afficher.
     Contenu et conditions inchangés (déplacés tels quels). --}}
@php
    $transfert = $courrier->dernierTransfert;
    $affectationCourante = $courrier->affectationCourante;
@endphp


@if ($courrier->statut === 'en_attente_de_transfert' && $this->peutTransferer)
    <x-circuit.modale nom="transferer-modal" icone="arrow-right-circle" :titre="__('Transférer au service')" :courrier="$courrier" action="transferer" :libelle="__('Confirmer le transfert')" :etapes="[
        __('Le courrier passe « En cours de transfert ».'),
        __('Le responsable du service est prévenu et doit l\'accepter ou le refuser.'),
        __('Après son acceptation, une décharge est créée et vous pourrez lui remettre l\'original papier.'),
    ]">
        <flux:text>{{ __('Choisissez le service destinataire du courrier.') }}</flux:text>
        @if ($courrier->servicePropose)
            <div class="flex items-start gap-2 rounded-lg bg-brand-warning-light p-3 text-brand-warning-dark">
                <flux:icon.light-bulb class="mt-0.5 size-4 shrink-0" />
                <span>{{ __('Proposé automatiquement : :service — à vérifier', ['service' => $courrier->servicePropose->nom]) }}</span>
            </div>
        @endif
        @include('partials.cascade-service')
    </x-circuit.modale>
@endif

@if ($courrier->statut === 'en_cours_de_transfert' && $this->peutDeciderTransfert)
    <x-circuit.modale nom="accepter-transfert-modal" icone="check" ton="succes" :titre="__('Accepter ce courrier ?')" :courrier="$courrier" action="accepterTransfert" :libelle="__('Accepter le courrier')" :etapes="[
        __('Une décharge numérotée est créée pour la remise de l\'original.'),
        __('Le contenu du courrier devient visible pour votre service.'),
        __('L\'accueil est prévenu qu\'il peut vous remettre l\'original papier.'),
    ]">
        <flux:text>{{ __('Transféré par :agent le :date.', ['agent' => $transfert?->transferePar?->name ?? __('L\'accueil'), 'date' => $transfert?->created_at?->format('d/m/Y H:i')]) }}</flux:text>
    </x-circuit.modale>

    <x-circuit.modale nom="refuser-transfert-modal" icone="x-mark" ton="danger" :titre="__('Refuser ce courrier')" :courrier="$courrier" action="refuserTransfert" :libelle="__('Confirmer le refus')" :etapes="[
        __('Le courrier retourne à l\'accueil, sans service.'),
        __('L\'agent est prévenu avec votre motif pour le réorienter.'),
        __('Son contenu ne vous sera jamais affiché.'),
    ]">
        <flux:textarea wire:model="motifRefusTransfert" :label="__('Motif du refus')" rows="3" :placeholder="__('Ex. ce courrier concerne le service Sinistres')" />
    </x-circuit.modale>
@endif

@if ($transfert?->estAccepte())
    @if ($this->peutMarquerOriginalRemis)
        {{-- 2026-11-09 (« only the person receiving the doc should sign,
             the agent shouldn't sign ») — l'accueil CONFIRME la remise, il
             ne signe pas : seul le destinataire signe la décharge. --}}
        <x-circuit.modale nom="remise-original-modal" icone="document-check" ton="succes" :titre="__('Original papier remis ?')" :courrier="$courrier" action="marquerOriginalRemis" :libelle="__('Confirmer la remise')" :etapes="[
            __('La remise est enregistrée dans l\'historique du courrier, avec votre nom, la date et l\'heure.'),
            __('Seul le destinataire signe la décharge, dans le cadre « Reçu par ».'),
        ]">
            <flux:text>{{ __('Confirmez que l\'original papier a bien été remis au service :service, à :nom.', ['service' => $transfert->service?->nom, 'nom' => $transfert->decidePar?->name]) }}</flux:text>
        </x-circuit.modale>
    @endif

    {{-- 2026-10-28 (« mettre le nom comme signature au cas où on
         ne veut pas imprimer ») — le service signe la réception. --}}
    @if ($this->peutSignerReception)
        <x-circuit.modale nom="signer-reception-modal" icone="pencil-square" ton="succes" :titre="__('Signer la réception de l\'original')" :courrier="$courrier" action="signerReceptionOriginal" :libelle="__('Signer la réception')" :etapes="[
            __('Votre nom figure comme signature dans le cadre « Reçu par » de la décharge, avec la date et l\'heure.'),
            __('La signature est tracée dans l\'historique du courrier.'),
            __('La décharge n\'a plus besoin d\'être imprimée pour être signée.'),
        ]">
            <flux:text>{{ __('Confirmez que vous avez bien reçu l\'original papier de ce courrier (décharge :numero).', ['numero' => $transfert->numero_decharge]) }}</flux:text>
            <x-circuit.apercu-signature :nom="auth()->user()->name" />
        </x-circuit.modale>
    @endif
@endif

@if ($this->peutCloturerConfidentiel)
    <x-circuit.modale nom="confidentiel-remis-modal" icone="lock-closed" ton="navy" :titre="__('Pli confidentiel remis ?')" :courrier="$courrier" action="cloturerConfidentiel" :libelle="__('Marquer comme remis')" :etapes="[
        __('La remise du pli est enregistrée dans l\'historique.'),
        __('Le courrier passe « Traité » puis sera archivé automatiquement.'),
    ]">
        <flux:text>{{ __('Confirmez que ce pli confidentiel vous a bien été remis.') }}</flux:text>
    </x-circuit.modale>
@endif

@if ($this->peutTransmettre && $this->unitesSubordonnees->isNotEmpty())
    <x-circuit.modale nom="transmettre-modal" icone="arrow-down-right" :titre="__('Transmettre à une unité')" :courrier="$courrier" action="transmettre" :libelle="__('Transmettre')" :etapes="[
        __('Le courrier est transmis à l\'unité choisie : son chef le reçoit « À affecter » et en est prévenu.'),
        __('Vous gardez la consultation du courrier.'),
    ]">
        <flux:select wire:model="uniteTransmissionId" :label="__('Unité')" placeholder="{{ __('Choisir une unité') }}">
            @foreach ($this->unitesSubordonnees as $uniteSubordonnee)
                <flux:select.option value="{{ $uniteSubordonnee->id }}">{{ $uniteSubordonnee->name }} — {{ $uniteSubordonnee->titreDuChef() }} : {{ $uniteSubordonnee->service->responsable?->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </x-circuit.modale>
@endif

{{-- 2026-10-29 — une fois la décharge remise et signée
     (CourrierPolicy::affecterPremiere()) ; « Me l'affecter et commencer »
     remplace l'ancien bouton « Traiter moi-même » (même droit,
     CourrierPolicy::traiterMoiMeme()). --}}
@if ($this->peutAffecterPremiere)
    <x-circuit.modale nom="affecter-modal" icone="user-plus" :titre="__('Affecter le courrier')" :courrier="$courrier" action="affecter" :libelle="__('Affecter')" :etapes="array_filter([
        __('Le collaborateur choisi est prévenu et devient responsable du traitement.'),
        $this->peutTraiterMoiMeme ? __('Avec « Me l\'affecter et commencer », le courrier vous est affecté et passe aussitôt « En traitement ».') : null,
        $this->peutFixerDelai ? __('Le délai de traitement indiqué démarre maintenant.') : null,
        __('L\'affectation est tracée dans l\'historique.'),
    ])">
        @if ($this->peutTraiterMoiMeme)
            <flux:radio.group wire:model="modeAffectation" :label="__('Affecter à')">
                <flux:radio value="collaborateur" :label="__('Un collaborateur du service')" />
                <flux:radio value="moi" :label="__('Me l\'affecter et commencer')" :description="__('Le traitement démarre aussitôt, sans passer par « Démarrer ».')" />
            </flux:radio.group>
        @endif
        <div x-show="$wire.modeAffectation !== 'moi'">
            <flux:select wire:model="collaborateurSelectionne" :label="__('Collaborateur')" placeholder="{{ __('Choisir un collaborateur') }}" :description="__('Le nombre de courriers déjà en cours aide à répartir la charge.')">
                @forelse ($this->collaborateursDuService as $c)
                    <flux:select.option value="{{ $c['id'] }}">{{ $c['name'] }} — {{ trans_choice(':n en cours|:n en cours', $c['charge'], ['n' => $c['charge']]) }}</flux:select.option>
                @empty
                    <flux:select.option value="" disabled>{{ __('Aucun collaborateur dans ce service') }}</flux:select.option>
                @endforelse
            </flux:select>
        </div>
        @if ($this->peutFixerDelai)
            <div class="grid grid-cols-2 gap-3">
                <flux:input type="number" min="1" wire:model="delaiValeur" :label="__('Délai de traitement')" />
                <flux:select wire:model="delaiUnite" :label="__('Unité')">
                    <flux:select.option value="jours">{{ __('jours') }}</flux:select.option>
                    <flux:select.option value="heures">{{ __('heures') }}</flux:select.option>
                </flux:select>
            </div>
        @endif
    </x-circuit.modale>
@endif

@if ($this->peutTraiter && $courrier->statut === 'affecte')
    <x-circuit.modale nom="demarrer-modal" icone="play" :titre="__('Démarrer le traitement')" :courrier="$courrier" action="demarrerTraitement" :libelle="__('Démarrer')" :etapes="[
        __('Le courrier passe « En traitement ».'),
        __('Vous pourrez ensuite le soumettre pour validation.'),
    ]">
        <flux:text>{{ __('Vous indiquez que vous commencez à traiter ce courrier.') }}</flux:text>
    </x-circuit.modale>
@elseif ($this->peutTraiter && $courrier->statut === 'en_traitement' && ($this->droits['validerDirectement'] || $this->droits['soumettreValidation']))
    @if ($this->droits['validerDirectement'])
        <x-circuit.modale nom="valider-directement-modal" icone="check-badge" ton="succes" :titre="__('Valider directement')" :courrier="$courrier" action="validerDirectement" :etapes="[
            __('Le courrier est soumis puis validé en une seule fois — les deux étapes restent tracées dans l\'historique.'),
            __('Il passe « Traité » puis sera archivé automatiquement.'),
        ]">
            <flux:textarea wire:model="commentaireCirculation" rows="3" :label="__('Commentaire (optionnel)')" placeholder="{{ __('Réponse ou action effectuée…') }}" />
        </x-circuit.modale>
    @endif
    @if ($this->droits['soumettreValidation'])
        <x-circuit.modale nom="soumettre-modal" icone="paper-airplane" :titre="__('Soumettre pour validation')" :courrier="$courrier" action="soumettrePourValidation" :libelle="__('Soumettre')" :etapes="[
            __('Le courrier passe « En validation ».'),
            __('Le responsable du service est prévenu pour le valider ou le renvoyer.'),
        ]">
            <flux:textarea wire:model="commentaireCirculation" rows="3" :label="__('Commentaire (optionnel)')" placeholder="{{ __('Réponse ou action effectuée…') }}" />
        </x-circuit.modale>
    @endif
@endif

@if ($courrier->statut === 'en_validation' && $this->peutValider)
    <x-circuit.modale nom="valider-modal" icone="check-badge" ton="succes" :titre="__('Valider le traitement')" :courrier="$courrier" action="valider" :libelle="__('Valider')" :etapes="[
        __('Le courrier passe « Traité » puis sera archivé automatiquement.'),
        __('Le collaborateur affecté est prévenu.'),
    ]">
        <flux:textarea wire:model="commentaireCirculation" rows="3" :label="__('Commentaire (optionnel)')" />
    </x-circuit.modale>
    @if ($this->peutEnvoyerSignatureDg)
        <x-circuit.modale nom="signature-dg-modal" icone="paper-airplane" ton="navy" :titre="__('Envoyer en signature DG')" :courrier="$courrier" action="envoyerSignatureDg" :libelle="__('Envoyer en signature')" :etapes="[
            __('Le courrier passe « En attente de signature DG ».'),
            __('Les signataires de la DG sont prévenus.'),
        ]">
            <flux:textarea wire:model="commentaireCirculation" rows="3" :label="__('Commentaire (optionnel)')" />
        </x-circuit.modale>
    @endif
    @if ($this->droits['renvoyerCorrection'])
        <x-circuit.modale nom="renvoyer-modal" icone="arrow-uturn-left" ton="attention" :titre="__('Renvoyer pour correction')" :courrier="$courrier" action="renvoyerPourCorrection" :libelle="__('Renvoyer')" :etapes="[
            __('Le courrier repasse « En traitement ».'),
            __('Le collaborateur affecté est prévenu avec votre motif.'),
        ]">
            <flux:textarea wire:model="motifRenvoi" rows="3" :label="__('Motif du renvoi')" :placeholder="__('Ce qui doit être corrigé…')" />
        </x-circuit.modale>
    @endif
@endif

@if ($courrier->statut === 'en_attente_signature_dg' && $this->peutSignerDg)
    <x-circuit.modale nom="signer-cloturer-modal" icone="identification" ton="succes" :titre="__('Signer et clôturer')" :courrier="$courrier" action="signerEtCloturer" :etapes="[
        __('Votre signature est enregistrée dans l\'historique.'),
        __('Le courrier passe « Traité » ; la personne qui l\'a envoyé en signature est prévenue.'),
    ]">
        <flux:textarea wire:model="commentaireCirculation" rows="3" :label="__('Commentaire (optionnel)')" />
    </x-circuit.modale>
    <x-circuit.modale nom="signer-tresorerie-modal" icone="banknotes" ton="navy" :titre="__('Signer et transmettre à la trésorerie')" :courrier="$courrier" action="signerEtEnvoyerTresorerie" :libelle="__('Signer et transmettre')" :etapes="[
        __('Votre signature est enregistrée dans l\'historique.'),
        __('Le courrier passe « En attente trésorerie » ; la trésorerie est prévenue.'),
    ]">
        <flux:textarea wire:model="commentaireCirculation" rows="3" :label="__('Commentaire (optionnel)')" />
    </x-circuit.modale>
@endif

@if ($courrier->statut === 'en_attente_tresorerie' && $this->peutTraiterTresorerie)
    <x-circuit.modale nom="tresorerie-modal" icone="banknotes" ton="succes" :titre="__('Marquer comme traité')" :courrier="$courrier" action="traiterParTresorerie" :etapes="[
        __('Le courrier passe « Traité » puis sera archivé automatiquement.'),
        __('La personne qui l\'a signé est prévenue.'),
    ]">
        <flux:textarea wire:model="commentaireCirculation" rows="3" :label="__('Commentaire (optionnel)')" placeholder="{{ __('Ex. décaissement effectué le…') }}" />
    </x-circuit.modale>
@endif

@if ($this->droits['reprendre'] && $courrier->statut === 'en_attente_information')
    <x-circuit.modale nom="reprendre-modal" icone="play" :titre="__('Reprendre le traitement')" :courrier="$courrier" action="reprendre" :libelle="__('Reprendre')" :etapes="[
        __('Le courrier repasse « En traitement ».'),
        __('L\'autre personne du circuit est prévenue.'),
    ]">
        <flux:text>{{ __('L\'information attendue est arrivée : le traitement reprend.') }}</flux:text>
    </x-circuit.modale>
@endif

@if ($courrier->statut !== 'enregistre' && $this->peutFixerDelai)
    <x-circuit.modale nom="delai-modal" icone="clock" ton="attention" :titre="__('Modifier le délai de traitement')" :courrier="$courrier" action="modifierDelai" :libelle="__('Mettre à jour le délai')" :etapes="[
        __('Le nouveau délai part de maintenant.'),
        __('Le changement et son motif sont tracés dans l\'historique.'),
    ]">
        <div class="grid grid-cols-2 gap-3">
            <flux:input type="number" min="1" wire:model="delaiValeur" :label="__('Nouveau délai')" />
            <flux:select wire:model="delaiUnite" :label="__('Unité')">
                <flux:select.option value="jours">{{ __('jours') }}</flux:select.option>
                <flux:select.option value="heures">{{ __('heures') }}</flux:select.option>
            </flux:select>
        </div>
        <flux:textarea wire:model="motifDelai" rows="2" :label="__('Motif')" />
    </x-circuit.modale>
@endif

@if ($this->droits['reaffecter'] && $affectationCourante && in_array($courrier->statut, ['affecte', 'en_traitement', 'en_validation', 'en_attente_information']))
    <x-circuit.modale nom="reaffecter-modal" icone="arrow-path" ton="attention" :titre="__('Réaffecter le courrier')" :courrier="$courrier" action="reaffecter" :libelle="__('Réaffecter')" :etapes="[
        __('Le nouveau collaborateur devient responsable du traitement et en est prévenu.'),
        __(':nom est prévenu du changement.', ['nom' => $affectationCourante->collaborateur->name]),
        __('La réaffectation et son motif sont tracés dans l\'historique.'),
    ]">
        <flux:text>{{ __('Actuellement affecté à :nom.', ['nom' => $affectationCourante->collaborateur->name]) }}</flux:text>
        <flux:select wire:model="collaborateurSelectionne" :label="__('Nouveau collaborateur')" placeholder="{{ __('Choisir un collaborateur') }}">
            @foreach ($this->collaborateursDuService as $c)
                <flux:select.option value="{{ $c['id'] }}">{{ $c['name'] }} — {{ trans_choice(':n en cours|:n en cours', $c['charge'], ['n' => $c['charge']]) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:textarea wire:model="motifReaffectation" rows="2" :label="__('Motif')" />
    </x-circuit.modale>
@endif

@if ($this->droits['mettreEnAttente'] && in_array($courrier->statut, ['affecte', 'en_traitement', 'en_validation'], true))
    <x-circuit.modale nom="attente-modal" icone="pause-circle" ton="attention" :titre="__('Mettre en attente d\'information')" :courrier="$courrier" action="mettreEnAttente" :libelle="__('Mettre en attente')" :etapes="[
        __('Le courrier passe « En attente d\'information ».'),
        __('L\'autre personne du circuit est prévenue avec votre motif.'),
        __('Il pourra être repris dès que l\'information arrive.'),
    ]">
        <flux:textarea wire:model="motifAttente" rows="3" :label="__('Motif')" placeholder="{{ __('Ex. en attente d\'une pièce complémentaire de l\'expéditeur') }}" />
    </x-circuit.modale>
@endif

@if ($this->droits['relancer'])
    <x-circuit.modale nom="relancer-modal" icone="bell-alert" ton="attention" :titre="__('Relancer :nom', ['nom' => $affectationCourante?->collaborateur?->name])" :courrier="$courrier" action="relancer" :libelle="__('Envoyer la relance')" :etapes="[
        __(':nom reçoit un rappel (notification et e-mail) pour traiter ce courrier.', ['nom' => $affectationCourante?->collaborateur?->name]),
        __('Le statut du courrier ne change pas.'),
        __('La relance est tracée dans l\'historique.'),
    ]">
        <flux:textarea wire:model="messageRelance" rows="3" :label="__('Message (facultatif)')" placeholder="{{ __('Ex. merci de traiter ce courrier avant vendredi') }}" />
    </x-circuit.modale>
@endif

@if ($this->droits['rejeter'] && ! in_array($courrier->statut, ['traite', 'archive', 'rejete'], true))
    <x-circuit.modale nom="rejeter-modal" icone="no-symbol" ton="danger" :titre="__('Rejeter ce courrier')" :courrier="$courrier" action="rejeter" :libelle="__('Confirmer le rejet')" :etapes="[
        __('Le courrier passe « Rejeté » et sort du circuit de traitement.'),
        __('Le rejet et son motif sont tracés dans l\'historique.'),
    ]">
        <div class="flex items-start gap-2 rounded-lg bg-brand-danger-light p-3 text-brand-danger-dark">
            <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0" />
            <span>{{ __('Action définitive : un courrier rejeté ne revient pas dans le circuit.') }}</span>
        </div>
        <flux:textarea wire:model="motifRejet" rows="3" :label="__('Motif du rejet')" />
    </x-circuit.modale>
@endif
