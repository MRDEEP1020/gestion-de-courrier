@php $courrier = $this->courrier; @endphp

{{-- Nouvelle maquette "Détail du courrier" fournie par l'utilisateur
     (2026-09-18, 3 captures) — remplace ENTIÈREMENT la mise en page à une
     colonne précédente par un en-tête d'actions + deux colonnes (onglets à
     gauche, panneau "Aperçu du courrier" TOUJOURS affiché à droite, jamais
     masqué — même demande que pour "Tous les courriers", clarifiée sur
     cette même page). AUCUNE logique de circuit (affecter/transférer/
     valider/rejeter/mettre en attente...) n'est modifiée : chaque
     formulaire existant est simplement déplacé sous l'onglet "Circuit de
     traitement" tel quel, avec exactement les mêmes conditions PHP
     qu'avant. Onglets "Réponses"/"Commentaires" de la maquette : AUCUN
     modèle de réponse/commentaire distinct de l'historique n'existe à ce
     jour — marqués "Bientôt" plutôt que fabriqués, même principe que les
     entrées de sidebar dans le même cas. --}}
@assets
    @vite('resources/js/document-preview.js')
@endassets

<section class="w-full" x-data="{ onglet: @js($ongletInitial) }">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" wire:navigate>{{ __('Accueil') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('courriers.rechercher')" wire:navigate>{{ __('Courriers') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Détail du courrier') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
                <flux:icon.envelope class="size-5" />
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    {{-- Dossier de classement — affiché en préfixe du numéro,
                         style chemin "Dossier/Référence", plutôt qu'en badge à
                         part (2026-10-05, retour utilisateur sur capture :
                         format "DOSSIER_NAME/COURIER_REFERENCE_NUMBER"
                         directement dans le titre). Affiché UNIQUEMENT si
                         classé — "Non classé" reste réservé au champ "Dossier"
                         d'Informations générales plus bas. --}}
                    <flux:heading level="1" class="uppercase">
                        @if ($courrier->dossierClassement)
                            <span class="text-zinc-400 dark:text-zinc-500">{{ $courrier->dossierClassement->nom }}/</span>
                        @endif
                        {{ $courrier->numero_reference }}
                    </flux:heading>
                    <x-statut-badge :statut="$courrier->statut" />
                    {{-- Chronomètre de traitement (SLA) — badge à côté du statut,
                         même forme et mêmes couleurs de marque que lui
                         (2026-09-24, "find a good place up there… consistent
                         with the design"). --}}
                    <x-chronometre :courrier="$courrier" />
                </div>
                <flux:subheading>
                    {{ $courrier->expediteur_organisation ?: $courrier->expediteur_nom ?: __('—') }}
                    · {{ $courrier->service?->nom ?? __('— pas encore transféré —') }}
                    · {{ $courrier->date_mouvement->format('d/m/Y') }}
                </flux:subheading>
                @if ($courrier->numero_tampon_detecte && $courrier->numero_tampon_detecte !== $courrier->numero_reference)
                    <flux:text class="mt-1 text-xs text-brand-warning">
                        {{ __('Numéro détecté sur le tampon (indicatif — à vérifier)') }} : {{ $courrier->numero_tampon_detecte }}
                    </flux:text>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="outline" icon="arrow-left" :href="route('courriers.rechercher')" wire:navigate>
                {{ __('Retour à la liste') }}
            </flux:button>
            @if ($this->peutModifier)
                <flux:button variant="primary" icon="pencil-square" :href="route('courriers.modifier', $courrier->id)" wire:navigate>
                    {{ __('Modifier') }}
                </flux:button>
            @endif
            {{-- 2026-10-13, demande explicite de l'utilisateur ("add the
                 button here") — même raccourci "Traiter moi-même" que dans
                 le bloc "Affecter" plus bas (CourrierPolicy::traiterMoiMeme()),
                 repris ici dans l'en-tête pour un accès direct depuis le
                 haut de la fiche. Ouvre une modale (pas d'action directe,
                 voir "je ne peut pa mettre un delais") : le bouton de
                 l'en-tête n'a aucun champ délai à proximité, contrairement
                 au bloc "Affecter" plus bas. --}}
            @if ($this->peutTraiterMoiMeme)
                <flux:button variant="primary" icon="user-circle" wire:click="ouvrirTraiterMoiMeme">
                    {{ __('Traiter moi-même') }}
                </flux:button>
            @endif
            {{-- courriers.telecharger / courriers.imprimer_bordereau (2026-09-23). --}}
            @if ($courrier->fichier_path && auth()->user()->can('telecharger', $courrier))
                <flux:button variant="outline" icon="arrow-down-tray" :href="route('courriers.document', $courrier->id)">
                    {{ __('Télécharger') }}
                </flux:button>
            @endif
            @can('imprimerBordereau', $courrier)
                <flux:button variant="outline" icon="printer" :href="route('courriers.bordereau', $courrier->id)" target="_blank">
                    {{ __('Imprimer') }}
                </flux:button>
            @endcan
        </div>
    </div>

    {{-- Module 4 — demande explicite de l'utilisateur : le motif d'un renvoi
         pour correction doit être visible tout de suite, pas seulement
         retrouvable dans l'historique en bas de page. Se base sur la
         dernière entrée d'historique (déjà triée par date décroissante,
         voir Courrier::historiques()) : dès que le collaborateur resoumet
         (soumettrePourValidation crée une nouvelle entrée plus récente), ce
         bandeau disparaît de lui-même, sans état supplémentaire à gérer. --}}
    @php $derniereEntreeHistorique = $courrier->historiques->first(); @endphp
    @if ($derniereEntreeHistorique?->action === 'validation_refusee')
        <flux:callout variant="warning" icon="arrow-uturn-left" class="mt-4">
            <flux:callout.heading>{{ __('Renvoyé pour correction') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Motif indiqué par :nom :', ['nom' => $derniereEntreeHistorique->auteur?->name ?? __('le responsable')]) }}
                <strong>{{ $derniereEntreeHistorique->commentaire }}</strong>
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div>
            {{-- Onglets — purement visuels (x-show), comme le wizard
                 d'enregistrement : aucun champ n'est retiré du DOM entre
                 deux onglets. Style "tabs soulignés" (2026-09-18, demande
                 explicite de l'utilisateur — "i want them as tab with a
                 line underneath them when we switch to one not button") :
                 plus de fond/bordure pilule par onglet, seule une ligne
                 (border-b-2) sous l'onglet actif, sur une ligne de base
                 commune à toute la barre. --}}
            {{-- Retour utilisateur (2026-10-05, capture d'écran annotée : "I
                 DON'T WANT THIHS IN RED") — la barre de défilement HORIZONTALE
                 native du navigateur sur cette rangée d'onglets (visible
                 quand elle dépasse la largeur disponible) ressortait en
                 rouge, sans rapport avec la palette de marque. Même
                 traitement déjà appliqué au panneau "Aperçu du courrier"
                 (components/apercu/visionneuse.blade.php) : le défilement
                 reste entièrement fonctionnel, seul son rendu visuel
                 disparaît. --}}
            <div class="overflow-x-auto border-b border-brand-border [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <nav class="flex min-w-max items-center gap-1">
                    <button type="button" x-on:click="onglet = 'general'" :class="onglet === 'general' ? 'border-brand-accent text-brand-accent' : 'border-transparent text-brand-text-secondary hover:border-zinc-300 hover:text-brand-text-primary'" class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors">
                        <flux:icon.document-text class="size-4" />
                        {{ __('Général') }}
                    </button>
                    {{-- Onglets "Pièces jointes" / "Historique" : privilèges de
                         lecture dédiés (2026-09-23), contenu masqué aussi. --}}
                    @if ($this->droits['voirPiecesJointes'])
                        <button type="button" x-on:click="onglet = 'pieces-jointes'" :class="onglet === 'pieces-jointes' ? 'border-brand-accent text-brand-accent' : 'border-transparent text-brand-text-secondary hover:border-zinc-300 hover:text-brand-text-primary'" class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors">
                            <flux:icon.paper-clip class="size-4" />
                            {{ __('Pièces jointes') }} ({{ $courrier->piecesJointes->count() }})
                        </button>
                    @endif
                    <button type="button" x-on:click="onglet = 'circuit'" :class="onglet === 'circuit' ? 'border-brand-accent text-brand-accent' : 'border-transparent text-brand-text-secondary hover:border-zinc-300 hover:text-brand-text-primary'" class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors">
                        <flux:icon.arrow-path-rounded-square class="size-4" />
                        {{ __('Circuit de traitement') }}
                    </button>
                    @if ($this->droits['voirHistorique'])
                        <button type="button" x-on:click="onglet = 'historique'" :class="onglet === 'historique' ? 'border-brand-accent text-brand-accent' : 'border-transparent text-brand-text-secondary hover:border-zinc-300 hover:text-brand-text-primary'" class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors">
                            <flux:icon.clock class="size-4" />
                            {{ __('Historique') }}
                        </button>
                    @endif
                    {{-- "Réponses"/"Commentaires" (maquette) : aucun modèle
                         dédié — les commentaires vivent aujourd'hui DANS
                         chaque entrée d'historique (voir onglet
                         "Historique"), jamais comme fil de discussion
                         séparé. Désactivés plutôt que fabriqués. --}}
                    <flux:tooltip content="{{ __('Bientôt disponible') }}">
                        <button type="button" disabled class="-mb-px flex items-center gap-1.5 border-b-2 border-transparent px-3 py-2.5 text-sm font-medium text-brand-text-secondary opacity-50">
                            <flux:icon.chat-bubble-left-right class="size-4" />
                            {{ __('Réponses') }}
                        </button>
                    </flux:tooltip>
                    <flux:tooltip content="{{ __('Bientôt disponible') }}">
                        <button type="button" disabled class="-mb-px flex items-center gap-1.5 border-b-2 border-transparent px-3 py-2.5 text-sm font-medium text-brand-text-secondary opacity-50">
                            <flux:icon.chat-bubble-bottom-center-text class="size-4" />
                            {{ __('Commentaires') }}
                        </button>
                    </flux:tooltip>
                </nav>
            </div>

            {{-- ONGLET GÉNÉRAL --}}
            <div x-show="onglet === 'general'" x-cloak class="mt-4 space-y-6">
                <div class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-4 flex items-center gap-2">
                        <flux:icon.information-circle class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Informations générales') }}</flux:heading>
                    </div>

                    {{-- Maquette "Détail du courrier" (2026-09-18, "use this
                         design exactly") : résumé en 3 colonnes. "Service
                         émetteur"/"Service destinataire" de la maquette ne
                         sont PAS de nouveaux champs — le premier réutilise
                         l'organisation expéditrice externe
                         (expediteur_organisation), le second le service
                         interne existant (service_id), déjà distincts en
                         base. --}}
                    <div class="grid gap-6 sm:grid-cols-3" x-data="{ copie: false }">
                        <div class="space-y-4">
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('N° Courrier') }}</flux:text>
                                <div class="flex items-center gap-1.5">
                                    <flux:text class="font-bold">{{ $courrier->numero_reference }}</flux:text>
                                    <button type="button" x-on:click="navigator.clipboard.writeText('{{ $courrier->numero_reference }}'); copie = true; setTimeout(() => copie = false, 1500)" :aria-label="__('Copier')" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                                        <flux:icon.clipboard x-show="!copie" class="size-3.5" />
                                        <flux:icon.check x-show="copie" x-cloak class="size-3.5 text-brand-success" />
                                    </button>
                                </div>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Type') }}</flux:text>
                                <div class="mt-0.5">
                                    <span class="inline-flex items-center rounded-full bg-brand-accent-pale px-2 py-0.5 text-xs font-medium text-brand-accent">
                                        {{ $courrier->sens === 'entrant' ? __('Courrier entrant') : __('Courrier sortant') }}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Objet') }}</flux:text>
                                <flux:text class="font-bold">{{ $courrier->objet }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Priorité') }}</flux:text>
                                <div class="mt-0.5 flex items-center gap-1.5">
                                    <span class="size-2 rounded-full {{ match ($courrier->priorite) {
                                        'urgente' => 'bg-brand-danger',
                                        'haute' => 'bg-brand-warning',
                                        'basse' => 'bg-zinc-300',
                                        default => 'bg-brand-success',
                                    } }}"></span>
                                    <flux:text class="font-bold capitalize">{{ $courrier->priorite }}</flux:text>
                                </div>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Confidentialité') }}</flux:text>
                                {{-- Niveau NUMÉRIQUE (2026-09-21, "numbers 1,2,3,4,5 etc,
                                     not confidential or whatever", puis "THE LABEL SHOULD
                                     BE NIVEAU 1 OR LEVEL 1") — plus de libellé
                                     "Normale"/"Confidentiel"/"Très confidentiel", mais le
                                     nombre reste préfixé de "Niveau", jamais nu. --}}
                                <flux:text class="font-bold">{{ __('Niveau :n', ['n' => $courrier->confidentialite]) }}</flux:text>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Statut') }}</flux:text>
                                <div class="mt-0.5"><x-statut-avec-echeance :courrier="$courrier" /></div>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Catégorie') }}</flux:text>
                                <flux:text class="font-bold">
                                    {{ $courrier->type_document }}
                                    @if ($courrier->sous_type_sinistre)
                                        <span class="text-zinc-400">({{ $courrier->sous_type_sinistre === 'materiel' ? __('Matériel') : __('Corporel') }})</span>
                                    @endif
                                </flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Service émetteur') }}</flux:text>
                                <flux:text class="font-bold">{{ $courrier->expediteur_organisation ?: $courrier->expediteur_nom ?: __('—') }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Service destinataire') }}</flux:text>
                                {{-- service null-safe : un courrier entrant en attente de transfert
                                     n'a pas encore de service (2026-09-15, voir DECISIONS.md,
                                     synchronisation SRS-GEC.pdf). --}}
                                <flux:text class="font-bold">{{ $courrier->service?->nom ?? __('— pas encore transféré —') }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Date d\'envoi') }}</flux:text>
                                <flux:text class="font-bold">{{ $courrier->date_mouvement->format('d/m/Y') }}</flux:text>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Expéditeur') }}</flux:text>
                                <flux:text class="font-bold">{{ $courrier->expediteur_organisation ?: $courrier->expediteur_nom ?: __('—') }}</flux:text>
                                @if ($courrier->expediteur_adresse)
                                    <flux:text class="text-xs text-zinc-500">{{ $courrier->expediteur_adresse }}</flux:text>
                                @endif
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Destinataire') }}</flux:text>
                                <flux:text class="font-bold">{{ $courrier->destinataire ?: '—' }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Mode de réception') }}</flux:text>
                                <flux:text class="font-bold capitalize">{{ str_replace('_', ' ', $courrier->mode_reception) }}</flux:text>
                            </div>
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Référence externe') }}</flux:text>
                                <flux:text class="font-bold">{{ $courrier->reference_externe ?: '—' }}</flux:text>
                            </div>
                            {{-- Module 3/9 (2026-10-02, retour utilisateur avec capture
                                 d'écran : "where does it represent here that this courier
                                 is inside a folder") — l'info existait déjà plus bas sur
                                 la page ("Dossier lié" dans Détails complémentaires,
                                 "Dossier de classement" dans Actions rapides), mais hors
                                 du premier écran visible sans défiler. Ajoutée ici aussi,
                                 dans le panneau "Informations générales" toujours visible
                                 — même source ($courrier->dossierClassement), jamais une
                                 troisième logique dupliquée. --}}
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Dossier') }}</flux:text>
                                <flux:text class="font-bold">{{ $courrier->dossierClassement?->nom ?? __('Non classé') }}</flux:text>
                            </div>
                        </div>
                    </div>

                    {{-- Coordonnées détaillées de l'expéditeur (RC/NIU/téléphone/
                         email...) — absentes du résumé 3 colonnes de la maquette,
                         mais des données RÉELLES déjà capturées (voir OCR Module
                         1) : jamais rendues invisibles, juste repliées ici plutôt
                         qu'affichées d'office. --}}
                    @if ($courrier->expediteur_telephone || $courrier->expediteur_email || $courrier->expediteur_rc || $courrier->expediteur_niu)
                        <details class="mt-4">
                            <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Coordonnées détaillées de l\'expéditeur') }}</summary>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                @if ($courrier->expediteur_telephone)
                                    <div>
                                        <flux:text class="text-xs uppercase text-zinc-500">{{ __('Téléphone') }}</flux:text>
                                        <flux:text class="font-bold">{{ $courrier->expediteur_telephone }}</flux:text>
                                    </div>
                                @endif
                                @if ($courrier->expediteur_email)
                                    <div>
                                        <flux:text class="text-xs uppercase text-zinc-500">{{ __('Email') }}</flux:text>
                                        <flux:text class="font-bold">{{ $courrier->expediteur_email }}</flux:text>
                                    </div>
                                @endif
                                @if ($courrier->expediteur_rc)
                                    <div>
                                        <flux:text class="text-xs uppercase text-zinc-500">{{ __('RC') }}</flux:text>
                                        <flux:text class="font-bold">{{ $courrier->expediteur_rc }}</flux:text>
                                    </div>
                                @endif
                                @if ($courrier->expediteur_niu)
                                    <div>
                                        <flux:text class="text-xs uppercase text-zinc-500">{{ __('NIU') }}</flux:text>
                                        <flux:text class="font-bold">{{ $courrier->expediteur_niu }}</flux:text>
                                    </div>
                                @endif
                            </div>
                        </details>
                    @endif
                </div>

                <div class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-4 flex items-center gap-2">
                        <flux:icon.map class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Parcours du courrier') }}</flux:heading>
                    </div>
                    {{-- Étapes purement AFFICHÉES d'après le statut réel — aucune
                         nouvelle transition, aucun bouton ici (voir l'onglet
                         "Circuit de traitement" pour les actions elles-mêmes).
                         Calculées côté composant, voir
                         ShowCourrier::etapesParcours(). --}}
                    {{-- Correctif (2026-09-18, capture d'écran de l'utilisateur
                         comparant sa maquette à ce qui s'affichait réellement) :
                         (1) "Création" (et toute étape franchie AVANT franchissement)
                         reste cochée en vert même sur une piste annexe (transfert,
                         attente, rejet) — voir ShowCourrier::etapesParcours() ; (2)
                         toute étape franchie OU en cours affiche une coche, pas un
                         numéro — seule une étape vraiment FUTURE affiche une icône
                         neutre (horloge), jamais son numéro d'ordre. --}}
                    <div class="flex min-w-max items-center overflow-x-auto pb-2">
                        @foreach ($this->etapesParcours as $etape)
                            <div class="flex flex-col items-center text-center">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ match (true) {
                                    $loop->first && $etape['atteinte'] => 'bg-brand-success text-white',
                                    $etape['atteinte'] || $etape['courante'] => 'bg-brand-accent text-white',
                                    default => 'bg-brand-disabled-bg text-brand-disabled-text',
                                } }}">
                                    @if ($etape['atteinte'] || $etape['courante'])
                                        <flux:icon.check class="size-4" />
                                    @else
                                        <flux:icon.clock class="size-4" />
                                    @endif
                                </span>
                                <span class="mt-1 w-24 text-xs font-medium {{ $etape['atteinte'] || $etape['courante'] ? 'text-brand-text-primary' : 'text-brand-text-muted' }}">{{ $etape['libelle'] }}</span>
                                <span class="w-24 text-xs {{ $etape['courante'] ? 'text-brand-warning' : 'text-brand-text-muted' }}">{{ $etape['sousLibelle'] }}</span>
                            </div>
                            @if (! $loop->last)
                                <div class="mx-2 h-px w-10 shrink-0 {{ $etape['atteinte'] ? 'bg-brand-accent' : 'bg-brand-border' }}"></div>
                            @endif
                        @endforeach
                    </div>
                    @if (in_array($courrier->statut, ['rejete', 'en_attente_information', 'en_attente_de_transfert', 'en_cours_de_transfert'], true))
                        <flux:text class="mt-2 text-xs text-zinc-500">
                            {{ __('Statut actuel hors du parcours standard ci-dessus') }} : <x-statut-badge :statut="$courrier->statut" />
                        </flux:text>
                    @endif
                </div>

                <div class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-4 flex items-center gap-2">
                        <flux:icon.squares-2x2 class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Détails complémentaires') }}</flux:heading>
                    </div>

                    {{-- Maquette "Détail du courrier" (2026-09-18) — champs
                         réellement nouveaux (voir migration 2026_09_18_100000
                         et EditForm, où ils sont éditables). 2026-09-22 :
                         "Dossier lié" montrait `dossier_reference` (texte
                         libre, sans rapport) — confusion réelle signalée par
                         l'utilisateur ("dossier lie not functioning") une
                         fois le VRAI classement en dossier construit (Module
                         3, voir "Actions rapides" ci-contre) : cette case
                         montre désormais le vrai dossier de classement (le
                         seul que ce libellé désigne pour l'utilisateur), et
                         la référence externe libre — un champ distinct,
                         toujours éditable depuis "Modifier" — s'affiche en
                         second, clairement identifiée pour ne plus les
                         confondre. --}}
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex items-start gap-2">
                            <flux:icon.folder class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Dossier lié') }}</flux:text>
                                <flux:text>{{ $courrier->dossierClassement?->nom ?? __('Non classé') }}</flux:text>
                                @if ($courrier->dossier_reference)
                                    <flux:text class="block text-xs text-zinc-400">{{ __('Réf. externe : :ref', ['ref' => $courrier->dossier_reference]) }}</flux:text>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.calendar class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Date de traitement prévue') }}</flux:text>
                                {{-- 2026-09-23 : date limite SLA réelle (échéance saisie,
                                     sinon SLA du courrier/de son type — voir
                                     SlaCalculatorService), plus seulement l'échéance manuelle. --}}
                                @php $statutDelai = app(\App\Services\SlaCalculatorService::class)->calculerStatutDelai($courrier); @endphp
                                <flux:text class="flex flex-wrap items-center gap-2">
                                    {{ $courrier->date_limite?->format('d/m/Y') ?: '—' }}
                                    @if ($statutDelai === \App\Services\SlaCalculatorService::EN_RETARD)
                                        <flux:badge size="sm" color="red">{{ __('En retard') }}</flux:badge>
                                    @elseif ($statutDelai === \App\Services\SlaCalculatorService::A_RISQUE)
                                        <flux:badge size="sm" color="amber">{{ __('Bientôt en retard') }}</flux:badge>
                                    @endif
                                </flux:text>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.user class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Collaborateur assigné') }}</flux:text>
                                <flux:text>{{ $courrier->affectationCourante?->collaborateur->name ?? '—' }}</flux:text>
                            </div>
                        </div>
                        {{-- 2026-09-24 — le chronomètre en direct est en
                             en-tête de la fiche ("mais le plutôt en haut
                             ici") ; ici, le rappel de l'origine du délai et
                             de l'échéance exacte. --}}
                        <div class="flex items-start gap-2">
                            <flux:icon.clock class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('SLA') }}</flux:text>
                                <flux:text>
                                    @if ($courrier->chrono_fin_le)
                                        {{ __('délai fixé par le responsable') }} — {{ $courrier->chrono_fin_le->format('d/m/Y H:i') }}
                                    @else
                                        {{ __('délai SLA : :duree', ['duree' => trans_choice(':n jour|:n jours', \App\Services\SlaCalculatorService::delaiJours($courrier->sla_jours, $courrier->type_document), ['n' => \App\Services\SlaCalculatorService::delaiJours($courrier->sla_jours, $courrier->type_document)])]) }}
                                    @endif
                                </flux:text>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.building-office-2 class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Service responsable') }}</flux:text>
                                <flux:text>{{ $courrier->serviceResponsable?->nom ?? '—' }}</flux:text>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.chat-bubble-bottom-center-text class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div>
                                <flux:text class="text-xs uppercase text-zinc-500">{{ __('Motif / Commentaire') }}</flux:text>
                                <flux:text>{{ $courrier->note_interne ?: '—' }}</flux:text>
                            </div>
                        </div>
                    </div>

                    {{-- 2026-10-12, demande explicite de l'utilisateur
                         ("not everybody has to see this ... if you don't
                         have the permission the card stop at detail
                         complementaire") — tout le bloc OCR/Classement/
                         mots-clés ci-dessous est gouverné par un seul
                         privilège (voir CourrierPolicy::voirTraitementDocument()) ;
                         sans lui, la carte s'arrête ici, juste après
                         "Détails complémentaires". --}}
                    @if ($this->droits['voirTraitementDocument'])
                    <flux:separator text="{{ __('Document principal (numérisation)') }}" class="mt-6" />
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        {{-- Span habillé directement (pas flux:badge color=, palette Tailwind
                             nommée de Flux Pro, ne peut pas produire les hex exacts du
                             système de couleurs GEC) — "en_cours" rejoint Information/bleu,
                             explicitement listé ("OCR") sous ce statut dans le système
                             fourni par l'utilisateur (2026-09-17). --}}
                        <span class="{{ match ($courrier->ocr_statut) {
                            'reussi' => 'bg-brand-success-light text-brand-success-dark',
                            'en_cours' => 'bg-brand-info-light text-brand-info-dark',
                            'echec_qualite', 'echec' => 'bg-brand-danger-light text-brand-danger-dark',
                            default => 'bg-brand-disabled-bg text-brand-disabled-text',
                        } }} inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize">
                            {{ str_replace('_', ' ', $courrier->ocr_statut) }}
                        </span>

                        @if ($courrier->ocr_confiance !== null)
                            <flux:text class="text-sm text-zinc-500">{{ __('confiance') }} {{ $courrier->ocr_confiance }} %</flux:text>
                        @endif

                        @if ($courrier->ocr_statut === 'en_cours')
                            <flux:text class="text-sm text-zinc-500">{{ __('traitement en cours — la fiche se mettra à jour automatiquement') }}</flux:text>
                        @endif

                        {{-- 'renumeriser' = modifier ce courrier + courriers.numeriser (2026-09-23). --}}
                        @can('renumeriser', $courrier)
                            @if ($courrier->ocr_statut !== 'reussi')
                                <flux:button :href="route('courriers.numeriser', $courrier->id)" wire:navigate size="sm">
                                    {{ $courrier->fichier_path ? __('Re-numériser') : __('Numériser') }}
                                </flux:button>
                            @endif
                        @endcan
                    </div>

                    {{-- courriers.voir_texte_ocr (2026-09-23). --}}
                    @if ($courrier->texte_ocr && $this->droits['voirTexteOcr'])
                        <details class="mt-3">
                            <summary class="cursor-pointer text-sm text-zinc-500">
                                {{ __('Texte extrait (OCR)') }}
                                <span class="text-zinc-400">— {{ mb_strlen($courrier->texte_ocr) }} {{ __('caractères') }}@if ($courrier->ocr_traite_le), {{ __('extrait le') }} {{ $courrier->ocr_traite_le->format('d/m/Y à H:i') }}@endif</span>
                            </summary>
                            <flux:text class="mt-2 text-xs text-zinc-500">
                                {{ __('Texte brut reconnu automatiquement, utilisé pour la recherche. Il peut contenir des erreurs de lecture — le document original fait foi.') }}
                            </flux:text>
                            <pre class="mt-2 max-h-72 overflow-auto whitespace-pre-wrap rounded-lg bg-zinc-50 p-3 font-mono text-sm leading-relaxed text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $courrier->texte_ocr }}</pre>
                        </details>
                    @endif

                    <flux:separator text="{{ __('Classement') }}" class="mt-6" />

                    @if ($courrier->classement_statut === 'propose')
                        <flux:callout variant="secondary" icon="sparkles">
                            <flux:callout.heading>{{ __('Le système propose un classement') }}</flux:callout.heading>
                            <flux:callout.text>
                                @if ($courrier->type_document_propose)
                                    {{ __('Type') }} : <strong>{{ $courrier->type_document_propose }}</strong>
                                    @if ($courrier->type_document_propose !== $courrier->type_document)<span class="text-zinc-500">({{ __('saisi') }} : {{ $courrier->type_document }})</span>@endif
                                    <br>
                                @endif
                                @if ($courrier->servicePropose)
                                    {{ __('Service') }} : <strong>{{ $courrier->servicePropose->nom }}</strong>
                                    {{-- $courrier->service peut être null (pas encore transféré,
                                         voir DECISIONS.md, synchronisation SRS-GEC.pdf) — rien
                                         à contraster dans ce cas, la proposition seule suffit. --}}
                                    @if ($courrier->service && $courrier->service_propose_id !== $courrier->service_id)<span class="text-zinc-500">({{ __('saisi') }} : {{ $courrier->service->nom }})</span>@endif
                                    <br>
                                @endif
                                @if ($courrier->regleClassement)
                                    <span class="text-xs text-zinc-500">{{ __('règle') }} « {{ $courrier->regleClassement->nom }} »</span>
                                @else
                                    <span class="text-xs text-zinc-500">{{ __('proposé automatiquement — voir le détail dans l\'historique') }}</span>
                                @endif
                            </flux:callout.text>
                            @if ($this->droits['validerClassement'])
                                <x-slot name="actions">
                                    <flux:button size="sm" variant="primary" wire:click="validerClassement">{{ __('Valider') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="ignorerClassement">{{ __('Ignorer') }}</flux:button>
                                </x-slot>
                            @endif
                        </flux:callout>
                    @elseif ($courrier->classement_statut === 'valide')
                        <flux:text class="text-sm text-zinc-500">{{ __('Classement automatique validé.') }}</flux:text>
                    @elseif ($courrier->classement_statut === 'ignore')
                        <flux:text class="text-sm text-zinc-500">{{ __('Proposition automatique ignorée — valeurs saisies conservées.') }}</flux:text>
                    @elseif ($courrier->classement_analyse_le === null)
                        <flux:text class="text-sm text-zinc-500">
                            <flux:icon name="arrow-path" class="mr-1 inline size-4 animate-spin" />
                            {{ __('Analyse automatique en attente de traitement — la fiche se mettra à jour automatiquement.') }}
                        </flux:text>
                    @else
                        <flux:text class="text-sm text-zinc-500">
                            {{ __('Aucune règle de classement ne correspond.') }}
                            <span class="text-xs text-zinc-400">({{ __('analysé le') }} {{ $courrier->classement_analyse_le->format('d/m/Y à H:i') }})</span>
                        </flux:text>
                    @endif

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <flux:text class="text-xs uppercase text-zinc-500">{{ __('Mots-clés') }}</flux:text>
                        @forelse ($courrier->motsCles as $motCle)
                            <span class="{{ match ($motCle->pivot->source) {
                                'manuel' => 'bg-brand-info-light text-brand-info-dark',
                                'regle' => 'bg-brand-success-light text-brand-success-dark',
                                default => 'bg-brand-disabled-bg text-brand-disabled-text',
                            } }} inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium">
                                {{ $motCle->libelle }}
                                @if ($this->droits['gererMotsCles'])
                                    <button type="button" wire:click="retirerMotCle({{ $motCle->id }})" class="ml-1 opacity-60 hover:opacity-100" title="{{ __('Retirer') }}">×</button>
                                @endif
                            </span>
                        @empty
                            <flux:text class="text-sm text-zinc-400">{{ __('aucun') }}</flux:text>
                        @endforelse
                    </div>

                    @if ($this->droits['gererMotsCles'])
                        <form wire:submit="ajouterMotCle" class="mt-2 flex items-start gap-2">
                            <flux:input wire:model="nouveauMotCle" size="sm" placeholder="{{ __('Ajouter un mot-clé') }}" class="max-w-xs" />
                            <flux:button size="sm" type="submit">{{ __('Ajouter') }}</flux:button>
                        </form>
                    @endif
                    @endif
                </div>
            </div>

            {{-- ONGLET PIÈCES JOINTES — courriers.voir_pieces_jointes (2026-09-23) --}}
            @if ($this->droits['voirPiecesJointes'])
            <div x-show="onglet === 'pieces-jointes'" x-cloak class="mt-4 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                @if ($courrier->piecesJointes->isEmpty())
                    <flux:text class="text-zinc-500">{{ __('Aucune pièce jointe pour ce courrier.') }}</flux:text>
                @else
                    {{-- Nom seul sans courriers.telecharger (2026-09-23). --}}
                    @php $peutTelecharger = auth()->user()->can('telecharger', $courrier); @endphp
                    <ul class="space-y-2">
                        @foreach ($courrier->piecesJointes as $pieceJointe)
                            <li class="flex items-center gap-2">
                                <flux:icon name="paper-clip" class="size-4 shrink-0 text-zinc-400" />
                                @if ($peutTelecharger)
                                    <flux:link :href="route('pieces-jointes.telecharger', $pieceJointe)">
                                        {{ $pieceJointe->nom_original }}
                                    </flux:link>
                                @else
                                    <span>{{ $pieceJointe->nom_original }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @endif

            {{-- ONGLET CIRCUIT DE TRAITEMENT — chaque formulaire ci-dessous est
                 déplacé TEL QUEL depuis l'ancienne mise en page à une
                 colonne, mêmes conditions PHP, aucune logique changée. --}}
            <div x-show="onglet === 'circuit'" x-cloak class="mt-4 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                @php $affectationCourante = $courrier->affectationCourante; @endphp

                <flux:text class="text-sm">
                    @if ($affectationCourante)
                        {{ __('Affecté à') }} <strong>{{ $affectationCourante->collaborateur->name }}</strong>
                        <span class="text-zinc-500">
                            — {{ __('par') }} {{ $affectationCourante->affectePar?->name ?? __('système') }},
                            {{ $affectationCourante->created_at->format('d/m/Y H:i') }}
                        </span>
                    @else
                        <span class="text-zinc-400">{{ __('Pas encore affecté.') }}</span>
                    @endif
                </flux:text>

                {{-- Module 1/4 — la réceptionniste transfère explicitement le courrier
                     (2026-09-15, voir DECISIONS.md, synchronisation SRS-GEC.pdf) — plus
                     d'envoi automatique à l'enregistrement. Mise à jour (2026-09-15) :
                     elle choisit désormais QUI dans une modale ("Destinataires de
                     transfert" — DGA, ADJ DGA, ou tout autre destinataire que
                     l'administrateur lui a autorisé) plutôt qu'un envoi générique "au
                     DGA". --}}
                @if ($courrier->statut === 'en_attente_de_transfert' && $this->peutTransferer)
                    <div class="mt-3">
                        <flux:modal.trigger name="transferer-modal">
                            <flux:button size="sm" variant="primary">{{ __('Transférer') }}</flux:button>
                        </flux:modal.trigger>
                    </div>

                    <flux:modal name="transferer-modal" class="w-full max-w-sm">
                        <form wire:submit="transferer" class="space-y-4">
                            <flux:heading size="lg">{{ __('Transférer à') }}</flux:heading>

                            @if ($this->destinatairesTransfert->isEmpty())
                                <flux:text class="text-zinc-500">{{ __('Aucun destinataire autorisé pour votre compte. Contactez un administrateur.') }}</flux:text>
                            @else
                                <flux:radio.group wire:model="destinataireTransfertChoisi">
                                    @foreach ($this->destinatairesTransfert as $destinataire)
                                        <flux:radio value="{{ $destinataire->id }}" label="{{ $destinataire->name }}" />
                                    @endforeach
                                </flux:radio.group>
                                @error('destinataireTransfertChoisi') <flux:text class="mt-1 text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
                            @endif

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button variant="ghost">{{ __('Annuler') }}</flux:button>
                                </flux:modal.close>
                                <flux:button type="submit" variant="primary" :disabled="$this->destinatairesTransfert->isEmpty()">{{ __('Confirmer le transfert') }}</flux:button>
                            </div>
                        </form>
                    </flux:modal>
                @elseif ($courrier->statut === 'en_cours_de_transfert' && ! $this->peutValiderService)
                    <flux:text class="mt-3 text-sm text-zinc-500">{{ __('En cours de transfert — en attente de validation du DGA/ADJ DGA.') }}</flux:text>
                @endif

                {{-- Module 1/4 — validation DGA/ADJ du service (courrier entrant
                     non-sinistre uniquement, voir RegistrationForm::enregistrer()).
                     Module "Organisation" v2 (2026-09-22, spec §16) — cascade
                     Site → Département → Service/Unité au lieu d'un
                     sélecteur plat unique, naviguant la vraie structure
                     organisationnelle. --}}
                @if ($courrier->statut === 'en_cours_de_transfert' && $this->peutValiderService)
                    <form wire:submit="validerService" class="mt-3 flex flex-wrap items-end gap-2">
                        {{-- 2026-09-23, clarification explicite de l'utilisateur : le
                             Site est OPTIONNEL — des Départements réels existent
                             déjà à la racine (sans Site au-dessus) en attendant le
                             vrai nom du/des site(s) réel(s), jamais fabriqué. Option
                             vide RÉELLE (pas seulement le placeholder) pour pouvoir
                             revenir à "racine" une fois un site choisi — même piège
                             que les 3 précédents cette session (flux:select "freeze"). --}}
                        <flux:select wire:model.live="siteSelectionneId" size="sm" :label="__('Site')" placeholder="{{ __('— Aucun (racine) —') }}" class="max-w-xs">
                            <flux:select.option value="">{{ __('— Aucun (racine) —') }}</flux:select.option>
                            @foreach ($this->sitesDisponibles as $site)
                                <flux:select.option value="{{ $site->id }}">{{ $site->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        {{-- 2026-10-09 — niveau Direction (OrganizationUnit::TYPE_DIRECTION),
                             OPTIONNEL comme Site : même wire:key que les niveaux
                             suivants, mêmes raisons (voir commentaire ci-dessous). --}}
                        @if ($this->directionsDisponibles->isNotEmpty())
                            <div wire:key="cascade-direction-{{ $siteSelectionneId ?? 'racine' }}">
                                <flux:select wire:model.live="directionSelectionneeId" size="sm" :label="__('Direction')" placeholder="{{ __('— Aucune (direct) —') }}" class="max-w-xs">
                                    <flux:select.option value="">{{ __('— Aucune (direct) —') }}</flux:select.option>
                                    @foreach ($this->directionsDisponibles as $direction)
                                        <flux:select.option value="{{ $direction->id }}">{{ $direction->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif

                        {{-- 2026-09-23 : wire:key + option vide RÉELLE sur chaque
                             niveau conditionnel, et Service/Unité en .live — sans
                             ça, un sélecteur masqué puis ré-affiché pouvait
                             afficher un choix différent de la valeur réellement
                             envoyée (bug constaté : validé vers "Sinistre Santé"
                             alors que l'écran montrait "Departement Informatique"). --}}
                        @if ($this->departementsDisponibles->isNotEmpty())
                            <div wire:key="cascade-departement-{{ $directionSelectionneeId ?? $siteSelectionneId ?? 'racine' }}">
                                <flux:select wire:model.live="departementSelectionneId" size="sm" :label="__('Département')" class="max-w-xs">
                                    <flux:select.option value="">{{ __('Choisir un département') }}</flux:select.option>
                                    @foreach ($this->departementsDisponibles as $departement)
                                        <flux:select.option value="{{ $departement->id }}">{{ $departement->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif

                        @if ($departementSelectionneId && $this->unitesDisponibles->isNotEmpty())
                            <div wire:key="cascade-unite-{{ $departementSelectionneId }}">
                                <flux:select wire:model.live="uniteSelectionneeId" size="sm" :label="__('Service / Unité')" class="max-w-xs">
                                    <flux:select.option value="">{{ __('— Aucun (le département reçoit directement) —') }}</flux:select.option>
                                    @foreach ($this->unitesDisponibles as $unite)
                                        <flux:select.option value="{{ $unite->id }}">{{ $unite->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                        @endif

                        {{-- 2026-09-21 — demande explicite de l'utilisateur :
                             la DGA confirme/corrige ici le niveau de
                             confidentialité RÉELLEMENT opposable (voir
                             CourrierPolicy::niveauSuffisant()), pré-rempli
                             avec le choix de la réceptionniste à
                             l'enregistrement (voir ShowCourrier::mount()) —
                             jamais vide, un courrier réellement confidentiel
                             dès l'enveloppe reste protégé même avant que la
                             DGA n'ait validé. --}}
                        <div wire:key="cascade-confidentialite">
                            <flux:select wire:model="confidentialiteSelectionnee" size="sm" :label="__('Niveau de confidentialité')" class="max-w-xs">
                                @foreach (range(1, \App\Models\User::niveauConfidentialiteMax()) as $niveau)
                                    <flux:select.option value="{{ $niveau }}">{{ __('Niveau :n', ['n' => $niveau]) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                        <flux:button size="sm" type="submit">{{ __('Confirmer le service') }}</flux:button>
                    </form>
                    @error('uniteSelectionneeId') <flux:text class="mt-1 text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
                    @error('confidentialiteSelectionnee') <flux:text class="mt-1 text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
                @endif

                {{-- Module 1/9 (2026-09-24) — pli confidentiel jamais ouvert :
                     son destinataire le marque comme remis, il est ensuite
                     archivé automatiquement (voir WorkflowService::cloturerConfidentiel()). --}}
                @if ($this->peutCloturerConfidentiel)
                    <div class="mt-3 space-y-2">
                        <flux:text class="text-sm">{{ __('Pli confidentiel qui vous a été remis directement. Une fois traité, marquez-le comme remis : il sera archivé automatiquement.') }}</flux:text>
                        <flux:button size="sm" variant="primary" wire:click="cloturerConfidentiel" wire:confirm="{{ __('Confirmer que ce pli confidentiel vous a bien été remis ?') }}">{{ __('Marquer comme remis') }}</flux:button>
                    </div>
                @elseif ($courrier->confidentiel_direct && $courrier->statut === 'enregistre')
                    <flux:text class="mt-3 text-sm text-zinc-500">{{ __('Pli confidentiel envoyé directement à son destinataire — en attente de sa confirmation de remise.') }}</flux:text>
                @endif

                {{-- Module 6 — première affectation --}}
                @if ($courrier->statut === 'enregistre' && $this->peutAffecter)
                    <form wire:submit="affecter" class="mt-3 flex flex-wrap items-end gap-2">
                        <flux:select wire:model="collaborateurSelectionne" size="sm" :label="__('Affecter à')" placeholder="{{ __('Choisir un collaborateur') }}" class="max-w-xs">
                            @forelse ($this->collaborateursDuService as $c)
                                <flux:select.option value="{{ $c['id'] }}">{{ $c['name'] }} — {{ trans_choice(':n en cours|:n en cours', $c['charge'], ['n' => $c['charge']]) }}</flux:select.option>
                            @empty
                                <flux:select.option value="" disabled>{{ __('Aucun collaborateur dans ce service') }}</flux:select.option>
                            @endforelse
                        </flux:select>
                        {{-- Délai du chronomètre (2026-09-24) — prérempli avec le
                             SLA actuel, le responsable le garde ou l'ajuste. --}}
                        @if ($this->peutFixerDelai)
                            <flux:input type="number" min="1" wire:model="delaiValeur" size="sm" :label="__('Délai de traitement')" class="w-28" />
                            <flux:select wire:model="delaiUnite" size="sm" :label="__('Unité')" class="w-28">
                                <flux:select.option value="jours">{{ __('jours') }}</flux:select.option>
                                <flux:select.option value="heures">{{ __('heures') }}</flux:select.option>
                            </flux:select>
                        @endif
                        <flux:button size="sm" type="submit">{{ __('Affecter') }}</flux:button>
                    </form>
                    @error('collaborateurSelectionne') <flux:text class="mt-1 text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
                    @error('delaiValeur') <flux:text class="mt-1 text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
                @endif

                {{-- Module 5 — modifier le délai en cours de circuit, motif
                     obligatoire (repart de maintenant, tracé dans l'historique). --}}
                @if ($courrier->statut !== 'enregistre' && $this->peutFixerDelai)
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Modifier le délai de traitement') }}</summary>
                        <form wire:submit="modifierDelai" class="mt-2 space-y-2">
                            <div class="flex flex-wrap items-end gap-2">
                                <flux:input type="number" min="1" wire:model="delaiValeur" size="sm" :label="__('Nouveau délai à partir de maintenant')" class="w-56" />
                                <flux:select wire:model="delaiUnite" size="sm" :label="__('Unité')" class="w-28">
                                    <flux:select.option value="jours">{{ __('jours') }}</flux:select.option>
                                    <flux:select.option value="heures">{{ __('heures') }}</flux:select.option>
                                </flux:select>
                            </div>
                            <flux:textarea wire:model="motifDelai" size="sm" rows="2" :label="__('Motif (obligatoire)')" />
                            @error('delaiValeur') <flux:text class="text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
                            @error('motifDelai') <flux:text class="text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
                            <flux:button size="sm" type="submit">{{ __('Mettre à jour le délai') }}</flux:button>
                        </form>
                    </details>
                @endif

                {{-- Module 4 — collaborateur affecté : démarrer / soumettre --}}
                @if ($this->peutTraiter)
                    @if ($courrier->statut === 'affecte')
                        <flux:button size="sm" class="mt-3" wire:click="demarrerTraitement">{{ __('Démarrer le traitement') }}</flux:button>
                    @elseif ($courrier->statut === 'en_traitement' && $this->droits['soumettreValidation'])
                        <div class="mt-3 space-y-2">
                            <flux:textarea wire:model="commentaireCirculation" size="sm" rows="2" :label="__('Commentaire (optionnel)')" placeholder="{{ __('Réponse ou action effectuée…') }}" />
                            <div class="flex items-center gap-2">
                                {{-- 2026-10-14, retour utilisateur ("why can
                                     he validate directly") — raccourci
                                     visible seulement quand le traiteur ET
                                     le validateur sont la même personne
                                     (CourrierPolicy::validerDirectement()),
                                     en plus du "Soumettre" classique
                                     ci-dessous (toujours disponible, pour
                                     qui préfère garder les 2 étapes). --}}
                                @if ($this->droits['validerDirectement'])
                                    <flux:button size="sm" variant="primary" wire:click="validerDirectement">{{ __('Valider directement') }}</flux:button>
                                @endif
                                <flux:button size="sm" variant="{{ $this->droits['validerDirectement'] ? 'outline' : 'primary' }}" wire:click="soumettrePourValidation">{{ __('Soumettre pour validation') }}</flux:button>
                            </div>
                        </div>
                    @endif
                @endif

                {{-- Module 4 — responsable de service : valider ou renvoyer --}}
                @if ($courrier->statut === 'en_validation' && $this->peutValider)
                    <div class="mt-3 space-y-2">
                        <flux:textarea wire:model="commentaireCirculation" size="sm" rows="2" :label="__('Commentaire (optionnel)')" />
                        <div class="flex items-center gap-2">
                            <flux:button size="sm" variant="primary" wire:click="valider">{{ __('Valider') }}</flux:button>
                            {{-- 2026-10-09 — extension du circuit générique
                                 (Signature DG / Trésorerie, entretien terrain
                                 Sinistres) : alternative opt-in à "Valider",
                                 visible seulement pour qui a le privilège
                                 courriers.envoyer_signature_dg (assigné par
                                 département, jamais lié au type de courrier
                                 dans le code). --}}
                            @if ($this->peutEnvoyerSignatureDg)
                                <flux:button size="sm" variant="outline" icon="paper-airplane" wire:click="envoyerSignatureDg">{{ __('Envoyer en signature DG') }}</flux:button>
                            @endif
                        </div>
                        @if ($this->droits['renvoyerCorrection'])
                            <flux:textarea wire:model="motifRenvoi" size="sm" rows="2" :label="__('Motif du renvoi (si correction nécessaire)')" />
                            <flux:button size="sm" variant="ghost" wire:click="renvoyerPourCorrection">{{ __('Renvoyer pour correction') }}</flux:button>
                        @endif
                    </div>
                @endif

                {{-- 2026-10-09 — DG : signe et clôture directement, ou signe
                     et transmet à la trésorerie (courrier de décaissement). --}}
                @if ($courrier->statut === 'en_attente_signature_dg' && $this->peutSignerDg)
                    <div class="mt-3 space-y-2">
                        <flux:textarea wire:model="commentaireCirculation" size="sm" rows="2" :label="__('Commentaire (optionnel)')" />
                        <div class="flex items-center gap-2">
                            <flux:button size="sm" variant="primary" icon="identification" wire:click="signerEtCloturer">{{ __('Signer et clôturer') }}</flux:button>
                            <flux:button size="sm" variant="outline" icon="banknotes" wire:click="signerEtEnvoyerTresorerie">{{ __('Signer et transmettre à la trésorerie') }}</flux:button>
                        </div>
                    </div>
                @elseif ($courrier->statut === 'en_attente_signature_dg')
                    <flux:text class="mt-3 text-sm text-zinc-500">{{ __('En attente de la signature de la DG.') }}</flux:text>
                @endif

                {{-- 2026-10-09 — Trésorerie : clôture après traitement du décaissement. --}}
                @if ($courrier->statut === 'en_attente_tresorerie' && $this->peutTraiterTresorerie)
                    <div class="mt-3 space-y-2">
                        <flux:textarea wire:model="commentaireCirculation" size="sm" rows="2" :label="__('Commentaire (optionnel)')" />
                        <flux:button size="sm" variant="primary" icon="banknotes" wire:click="traiterParTresorerie">{{ __('Marquer comme traité') }}</flux:button>
                    </div>
                @elseif ($courrier->statut === 'en_attente_tresorerie')
                    <flux:text class="mt-3 text-sm text-zinc-500">{{ __('En attente de traitement par la trésorerie.') }}</flux:text>
                @endif

                {{-- Réaffectation — privilège courriers.reaffecter (2026-09-23), tant qu'un circuit est en cours --}}
                @if ($this->droits['reaffecter'] && $affectationCourante && in_array($courrier->statut, ['affecte', 'en_traitement', 'en_validation', 'en_attente_information']))
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Réaffecter') }}</summary>
                        <form wire:submit="reaffecter" class="mt-2 space-y-2">
                            <flux:select wire:model="collaborateurSelectionne" size="sm" :label="__('Nouveau collaborateur')" placeholder="{{ __('Choisir un collaborateur') }}" class="max-w-xs">
                                @foreach ($this->collaborateursDuService as $c)
                                    <flux:select.option value="{{ $c['id'] }}">{{ $c['name'] }} — {{ trans_choice(':n en cours|:n en cours', $c['charge'], ['n' => $c['charge']]) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:textarea wire:model="motifReaffectation" size="sm" rows="2" :label="__('Motif (obligatoire)')" />
                            <flux:button size="sm" type="submit">{{ __('Réaffecter') }}</flux:button>
                        </form>
                        @error('collaborateurSelectionne') <flux:text class="mt-1 text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
                    </details>
                @endif

                {{-- Mise en attente / reprise — collaborateur affecté ou responsable --}}
                @if ($this->droits['mettreEnAttente'] && in_array($courrier->statut, ['affecte', 'en_traitement', 'en_validation'], true))
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm text-zinc-500">{{ __('Mettre en attente d\'information') }}</summary>
                        <form wire:submit="mettreEnAttente" class="mt-2 space-y-2">
                            <flux:textarea wire:model="motifAttente" size="sm" rows="2" :label="__('Motif (obligatoire)')" placeholder="{{ __('Ex. en attente d\'une pièce complémentaire de l\'expéditeur') }}" />
                            <flux:button size="sm" type="submit">{{ __('Mettre en attente') }}</flux:button>
                        </form>
                    </details>
                @elseif ($this->droits['reprendre'] && $courrier->statut === 'en_attente_information')
                    <flux:button size="sm" class="mt-3" wire:click="reprendre">{{ __('Reprendre le traitement') }}</flux:button>
                @endif

                {{-- Rejet — responsable, dérogation tracée --}}
                @if ($this->droits['rejeter'] && ! in_array($courrier->statut, ['traite', 'archive', 'rejete'], true))
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm text-brand-danger">{{ __('Rejeter ce courrier') }}</summary>
                        <form wire:submit="rejeter" class="mt-2 space-y-2">
                            <flux:textarea wire:model="motifRejet" size="sm" rows="2" :label="__('Motif (obligatoire)')" />
                            <flux:button size="sm" variant="danger" type="submit" wire:confirm="{{ __('Confirmer le rejet de ce courrier ?') }}">{{ __('Rejeter') }}</flux:button>
                        </form>
                    </details>
                @endif
            </div>

            {{-- ONGLET HISTORIQUE — courriers.voir_historique (2026-09-23).
                 Redessiné en frise verticale (2026-10-08, retour utilisateur
                 — "improve the design of this historique") : badge circulaire
                 coloré par CATÉGORIE d'action (voir
                 CourrierHistorique::classesIcone(), palette de marque
                 existante uniquement, jamais une teinte Tailwind arbitraire),
                 ligne de connexion entre les entrées, libellé FR lisible
                 (CourrierHistorique::libelle()) à la place de l'ancien
                 str_replace('_', ' ', $action) brut, commentaire éventuel
                 mis en évidence dans son propre encart plutôt qu'en simple
                 texte gris clair (hiérarchie noire du texte, voir
                 app.css — plus de gris clair pour du texte). --}}
            @if ($this->droits['voirHistorique'])
            <div x-show="onglet === 'historique'" x-cloak class="mt-4 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <ul class="space-y-6">
                    @foreach ($courrier->historiques as $entree)
                        <li class="relative flex gap-4">
                            @unless ($loop->last)
                                <span class="absolute left-4 top-9 h-[calc(100%-0.25rem)] w-px -translate-x-1/2 bg-brand-border dark:bg-zinc-700"></span>
                            @endunless

                            <span class="relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full {{ $entree->classesIcone() }}">
                                <flux:icon :icon="$entree->icone()" class="size-4" />
                            </span>

                            <div class="min-w-0 flex-1 pb-0.5">
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                    <flux:text class="font-medium text-brand-text-primary">{{ $entree->libelle() }}</flux:text>
                                    <flux:text class="shrink-0 text-xs text-brand-text-secondary">{{ $entree->created_at->format('d/m/Y à H:i') }}</flux:text>
                                </div>
                                <flux:text class="text-sm text-brand-text-secondary">{{ __('par') }} {{ $entree->auteur?->name ?? __('Système') }}</flux:text>
                                @if ($entree->commentaire)
                                    <flux:text class="mt-1.5 block rounded-lg bg-brand-surface-soft px-3 py-2 text-sm text-brand-text-primary dark:bg-zinc-800">{{ $entree->commentaire }}</flux:text>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>

        {{-- Colonne latérale : panneau "Aperçu du courrier" TOUJOURS affiché
             (demande explicite de l'utilisateur, jamais masqué — même
             principe que "Tous les courriers"), informations expéditeur/
             destinataire, fichiers joints. --}}
        <div class="sticky top-20 self-start space-y-6">
            <div x-data="squeletteMinimum()" class="rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div wire:loading.class.remove="hidden" x-ref="sentinelle" class="hidden" aria-hidden="true"></div>
                <div class="flex items-center gap-2 border-b border-brand-border p-4 dark:border-zinc-700">
                    <flux:icon.eye class="size-4 text-brand-accent" />
                    <flux:heading level="3">{{ __('Aperçu du courrier') }}</flux:heading>
                </div>

                {{-- Squelette (2026-09-24, "loading animation on each
                     table, cards, panels refreshing") — ce panneau se
                     recharge réellement en direct via statutChange()/
                     ocrTermine()/classementPropose() (unset($this->courrier),
                     voir le composant), pas seulement au premier chargement
                     de la page. Durée minimale garantie via
                     squeletteMinimum(), voir resources/js/app.js
                     (2026-09-24, "am not seeing animation... in page"). --}}
                <div x-show="visible" x-cloak>
                    <x-skeleton.panel :lignes="3" />
                </div>

                <div x-show="!visible" class="p-4">
                    @if ($courrier->fichier_path)
                        @php $telechargerUrlFiche = auth()->user()->can('telecharger', $courrier) ? route('courriers.document', $courrier) : null; @endphp
                        {{-- Même motif pdf.js/document-preview.js que "Tous les
                             courriers" et le formulaire d'enregistrement,
                             pointant vers courriers.document.apercu (déjà
                             existant). Navigation de page (précédent/suivant) —
                             maquette "Détail du courrier" (2026-09-18) : même
                             fonctionnalité déjà réelle sur registrationForm.blade.php
                             (pageActuelle/numPages/pageSuivante/pagePrecedente de
                             document-preview.js), jamais utilisée ici jusqu'à
                             présent. --}}
                        <div
                            wire:ignore
                            x-data="{
                                erreur: null,
                                zoom: 100,
                                pageActuelle: 1,
                                numPages: 1,
                                url: @js(route('courriers.document.apercu', $courrier)),
                                instance() {
                                    const apercu = window.DocumentPreview.obtenir(this.url + ':fiche');
                                    apercu.boite = document.getElementById('apercu-fiche-corps');
                                    apercu.conteneur = document.getElementById('apercu-fiche-conteneur');

                                    return apercu;
                                },
                                async init() {
                                    try {
                                        const apercu = this.instance();
                                        // Cliquer-glisser (2026-09-24) — plutôt
                                        // que de devoir viser la barre de
                                        // défilement une fois zoomé au-delà
                                        // de 100 %.
                                        window.DocumentPreview.activerDeplacement(apercu.boite);
                                        // Molette pour zoomer (2026-09-24, retour utilisateur :
                                        // « why can[t] i zoom without the + sign ») — Ctrl/Cmd +
                                        // molette, même bornes que zoomIn()/zoomOut() ci-dessous.
                                        // ATTENTION — jamais de guillemet droit dans un commentaire
                                        // à l'intérieur d'un attribut x-data (délimité par des
                                        // guillemets droits) : ça referme l'attribut HTML en plein
                                        // milieu (voir notes internes — guillemets
                                        // français «» seulement).
                                        window.DocumentPreview.activerZoomMolette(apercu.boite, (pas) => this.zoomer(Math.min(Math.max(this.zoom + pas, 50), 200)));
                                        await apercu.charger(this.url, apercu.boite, apercu.conteneur);
                                        this.zoom = Math.round((apercu.echelle / apercu.echelleBase) * 100);
                                        this.pageActuelle = apercu.pageActuelle;
                                        this.numPages = apercu.numPages;
                                    } catch (e) {
                                        console.error('[aperçu fiche courrier]', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                async zoomer(nouveauZoom) {
                                    this.zoom = nouveauZoom;

                                    try {
                                        await this.instance().zoomer(this.zoom);
                                    } catch (e) {
                                        console.error('[aperçu fiche courrier] zoom', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                zoomIn() { this.zoomer(Math.min(this.zoom + 25, 200)); },
                                zoomOut() { this.zoomer(Math.max(this.zoom - 25, 50)); },
                                async pageSuivante() {
                                    try {
                                        const apercu = this.instance();
                                        await apercu.pageSuivante();
                                        this.pageActuelle = apercu.pageActuelle;
                                    } catch (e) {
                                        console.error('[aperçu fiche courrier] page suivante', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                async pagePrecedente() {
                                    try {
                                        const apercu = this.instance();
                                        await apercu.pagePrecedente();
                                        this.pageActuelle = apercu.pageActuelle;
                                    } catch (e) {
                                        console.error('[aperçu fiche courrier] page précédente', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                            }"
                            x-init="init()"
                        >
                            <x-apercu.barre-outils
                                variant="panneau"
                                :nom-fichier="basename($courrier->fichier_path)"
                                agrandir-evenement="apercu-courrier-agrandi"
                                :telecharger-url="$telechargerUrlFiche"
                            />
                            <x-apercu.visionneuse id="apercu-fiche" />
                        </div>

                        {{-- Modale "Agrandir" (2026-09-24, "make the appercu
                             modal ... more lisable by increasing the modal")
                             — même patron que RegistrationForm ("Agrandir"),
                             instance pdf.js SÉPARÉE de la miniature ci-dessus
                             (voir son commentaire : chaque composant Alpine a
                             son propre état de rendu). Plus large qu'un
                             formulaire de saisie classique (max-w-5xl au lieu
                             de max-w-2xl) et plus haute (85vh) : cette page
                             n'a pas d'autre contenu à côté à préserver, la
                             lisibilité d'un document scanné dense prime. --}}
                        <div
                            wire:key="apercu-courrier-agrandi-{{ $courrier->id }}"
                            wire:ignore
                            x-data="{
                                erreur: null,
                                zoom: 100,
                                url: @js(route('courriers.document.apercu', $courrier)),
                                pageActuelle: 1,
                                numPages: 1,
                                instance() {
                                    const apercu = window.DocumentPreview.obtenir(this.url + ':agrandi');
                                    apercu.boite = document.getElementById('apercu-courrier-agrandi-corps');
                                    apercu.conteneur = document.getElementById('apercu-courrier-agrandi-conteneur');

                                    return apercu;
                                },
                                async rendre() {
                                    try {
                                        const apercu = this.instance();
                                        window.DocumentPreview.activerDeplacement(apercu.boite);
                                        // Molette pour zoomer (2026-09-24, retour utilisateur :
                                        // « why can[t] i zoom without the + sign ») — Ctrl/Cmd +
                                        // molette, même bornes que zoomIn()/zoomOut() ci-dessous.
                                        // ATTENTION — jamais de guillemet droit dans un commentaire
                                        // à l'intérieur d'un attribut x-data (délimité par des
                                        // guillemets droits) : ça referme l'attribut HTML en plein
                                        // milieu (voir notes internes — guillemets
                                        // français «» seulement).
                                        window.DocumentPreview.activerZoomMolette(apercu.boite, (pas) => this.zoomer(Math.min(Math.max(this.zoom + pas, 50), 200)));
                                        await apercu.charger(this.url, apercu.boite, apercu.conteneur, true, true);
                                        this.zoom = Math.round((apercu.echelle / apercu.echelleBase) * 100);
                                        this.pageActuelle = apercu.pageActuelle;
                                        this.numPages = apercu.numPages;
                                    } catch (e) {
                                        console.error('[aperçu courrier agrandi]', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                async zoomer(nouveauZoom) {
                                    this.zoom = nouveauZoom;

                                    try {
                                        await this.instance().zoomer(this.zoom);
                                    } catch (e) {
                                        console.error('[aperçu courrier agrandi] zoom', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                zoomIn() { this.zoomer(Math.min(this.zoom + 25, 200)); },
                                zoomOut() { this.zoomer(Math.max(this.zoom - 25, 50)); },
                                async pageSuivante() {
                                    try {
                                        const apercu = this.instance();
                                        await apercu.pageSuivante();
                                        this.pageActuelle = apercu.pageActuelle;
                                    } catch (e) {
                                        console.error('[aperçu courrier agrandi] page suivante', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                                async pagePrecedente() {
                                    try {
                                        const apercu = this.instance();
                                        await apercu.pagePrecedente();
                                        this.pageActuelle = apercu.pageActuelle;
                                    } catch (e) {
                                        console.error('[aperçu courrier agrandi] page précédente', e);
                                        this.erreur = e.message ?? String(e);
                                    }
                                },
                            }"
                            x-on:modal-show.document="if ($event.detail.name === 'apercu-courrier-agrandi') { rendre(); }"
                        >
                            {{-- 2026-09-24 (retour utilisateur, capture d'écran :
                                 la modale s'ouvrait bien, mais réduite à la
                                 hauteur de son en-tête — un tout petit
                                 document, pas le grand lecteur attendu) —
                                 cause trouvée : `variant="bare"` ("legacy",
                                 aucun style par défaut, voir la doc Flux) ne
                                 garantit AUCUNE hauteur propre ; donner
                                 `h-[85vh]` seulement au corps intérieur ne
                                 suffit pas à faire grandir la modale
                                 elle-même (qui se dimensionne alors sur son
                                 seul en-tête) — le corps se retrouve
                                 mesuré/rendu (pdf.js) dans une boîte quasi
                                 nulle, d'où la vignette minuscule constatée.
                                 Fix : la hauteur est imposée EXPLICITEMENT
                                 sur la balise flux:modal elle-même (comme
                                 documenté pour son dimensionnement en
                                 largeur, voir DECISIONS.md), pas seulement
                                 sur un enfant ; le corps devient une ligne
                                 flex qui occupe tout l'espace restant
                                 (flex-1 min-h-0) sous l'en-tête/la
                                 pagination/la recherche, au lieu de
                                 redemander sa propre unité vh indépendante. --}}
                            {{-- 2026-09-24 — SANS "display: flex" forcé (`flex!`) ICI :
                                 ça écrasait la règle par défaut du navigateur qui cache un
                                 popover fermé (`display: none`), laissant la modale réserver
                                 ses 88vh de hauteur EN PERMANENCE dans le flux de la page,
                                 même fermée. La mise en page en colonne reste sur le DIV
                                 intérieur juste en dessous. --}}
                            <flux:modal name="apercu-courrier-agrandi" variant="bare" class="h-[88vh]! max-h-[88vh]! w-full! max-w-7xl! overflow-hidden! rounded-xl! bg-white! p-0! shadow-lg! dark:bg-zinc-900!">
                                <div class="flex h-full min-h-0 flex-col">
                                    <x-apercu.barre-outils
                                        variant="modale"
                                        :nom-fichier="basename($courrier->fichier_path)"
                                        :numero-reference="$courrier->numero_reference"
                                        :telecharger-url="$telechargerUrlFiche"
                                        fermer-modale="apercu-courrier-agrandi"
                                    />

                                    {{-- Deux colonnes dès 900px (document | correspondance), empilées
                                         en dessous — demande explicite de l'utilisateur. --}}
                                    <div class="flex min-h-0 flex-1 flex-col min-[900px]:flex-row">
                                        <x-apercu.visionneuse id="apercu-courrier-agrandi" grandir />
                                        <div class="h-64 shrink-0 min-[900px]:h-auto min-[900px]:w-90">
                                            <x-apercu.panneau-correspondance
                                                :objet="$courrier->objet"
                                                :numero-reference="$courrier->numero_reference"
                                                :expediteur-nom="$courrier->expediteur_nom"
                                                :expediteur-organisation="$courrier->expediteur_organisation"
                                                :expediteur-fonction="$courrier->expediteur_fonction"
                                                :expediteur-adresse="$courrier->expediteur_adresse"
                                                :expediteur-telephone="$courrier->expediteur_telephone"
                                                :expediteur-email="$courrier->expediteur_email"
                                                :destinataire="$courrier->destinataire"
                                                :date="$courrier->date_mouvement"
                                                :nom-fichier="basename($courrier->fichier_path)"
                                                :telecharger-url="$telechargerUrlFiche"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </flux:modal>
                        </div>
                    @else
                        <div class="flex h-40 flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-brand-border bg-brand-surface-soft text-center">
                            <flux:icon.document class="size-8 text-brand-text-muted" />
                            <span class="text-sm text-brand-text-secondary">{{ __('Aucun document numérisé pour ce courrier.') }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- "Fichiers joints" (maquette 2026-09-18) : mêmes pièces
                 jointes que l'onglet "Pièces jointes", résumées ici avec un
                 lien "Voir tous" qui bascule simplement cet onglet — pas une
                 liste dupliquée avec sa propre logique. --}}
            @if ($courrier->piecesJointes->isNotEmpty() && $this->droits['voirPiecesJointes'])
                <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <flux:icon.paper-clip class="size-4 text-brand-accent" />
                            <flux:heading level="3">{{ __('Fichiers joints') }} ({{ $courrier->piecesJointes->count() }})</flux:heading>
                        </div>
                        <button type="button" x-on:click="onglet = 'pieces-jointes'" class="text-sm text-brand-accent hover:underline">
                            {{ __('Voir tous') }} →
                        </button>
                    </div>
                    <ul class="space-y-2 text-sm">
                        @foreach ($courrier->piecesJointes->take(3) as $pieceJointe)
                            <li class="flex items-center justify-between gap-2">
                                <span class="truncate">{{ $pieceJointe->nom_original }}</span>
                                @can('telecharger', $courrier)
                                    <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('pieces-jointes.telecharger', $pieceJointe)" :aria-label="__('Télécharger')" />
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- "Actions rapides" (maquette 2026-09-18) — "Transférer" et
                 "Imprimer" pointent vers de VRAIES actions déjà existantes
                 sur cette page (respectivement la modale de l'onglet
                 "Circuit de traitement" et la route d'impression déjà dans
                 l'en-tête) ; "Répondre"/"Créer une note" désactivés — aucun
                 modèle de réponse/note distinct de l'historique n'existe à
                 ce jour (même principe que les onglets "Réponses"/
                 "Commentaires" plus haut). 2026-10-08 — retour utilisateur
                 direct ("should be previledges too") : ces deux boutons
                 étaient les SEULS de la page sans aucun privilège ; masqués
                 désormais derrière courriers.repondre/courriers.creer_note
                 (droits()), même si la fonctionnalité réelle reste à
                 construire — cohérent avec le reste de la page où un
                 privilège contrôle la VISIBILITÉ du bouton, pas seulement
                 son état activé/désactivé. --}}
            <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon.bolt class="size-4 text-brand-accent" />
                    <flux:heading level="3">{{ __('Actions rapides') }}</flux:heading>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    @if ($this->droits['repondre'])
                        <flux:tooltip content="{{ __('Bientôt disponible') }}">
                            <flux:button size="sm" variant="outline" icon="arrow-uturn-left" disabled class="w-full">{{ __('Répondre') }}</flux:button>
                        </flux:tooltip>
                    @endif
                    @if ($courrier->statut === 'en_attente_de_transfert' && $this->peutTransferer)
                        <flux:button size="sm" variant="outline" icon="arrow-right-circle" class="w-full" x-on:click="onglet = 'circuit'; $dispatch('modal-show', { name: 'transferer-modal' })">
                            {{ __('Transférer') }}
                        </flux:button>
                    @else
                        <flux:tooltip content="{{ __('Non applicable au statut actuel') }}">
                            <flux:button size="sm" variant="outline" icon="arrow-right-circle" disabled class="w-full">{{ __('Transférer') }}</flux:button>
                        </flux:tooltip>
                    @endif
                    @if ($this->droits['creerNote'])
                        <flux:tooltip content="{{ __('Bientôt disponible') }}">
                            <flux:button size="sm" variant="outline" icon="document-plus" disabled class="w-full">{{ __('Créer une note') }}</flux:button>
                        </flux:tooltip>
                    @endif
                    @can('imprimerBordereau', $courrier)
                        <flux:button size="sm" variant="outline" icon="printer" :href="route('courriers.bordereau', $courrier->id)" target="_blank" class="w-full">
                            {{ __('Imprimer') }}
                        </flux:button>
                    @else
                        <flux:button size="sm" variant="outline" icon="printer" disabled class="w-full">{{ __('Imprimer') }}</flux:button>
                    @endcan
                </div>

                {{-- Module 3/9 — "Classer dans un dossier" (2026-09-22, demande
                     explicite de l'utilisateur, "les trois" points d'entrée) :
                     toujours actionnable, aucune dépendance au statut du
                     courrier (contrairement à Transférer ci-dessus) — un
                     courrier archivé reste classable (Module 9,
                     retrouvabilité). --}}
                @if ($this->peutClasser)
                    <div class="mt-3 border-t border-brand-border pt-3 dark:border-zinc-700">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <flux:text class="text-xs text-zinc-500">{{ __('Dossier de classement') }}</flux:text>
                                <div class="truncate text-sm font-medium">
                                    {{ $courrier->dossierClassement?->nom ?? __('Non classé') }}
                                </div>
                            </div>
                            <flux:button size="sm" variant="outline" icon="folder" wire:click="ouvrirClassement">
                                {{ $courrier->dossier_classement_id ? __('Changer') : __('Classer') }}
                            </flux:button>
                        </div>
                    </div>
                @endif

                {{-- Module 9 — "Décharge" (2026-10-05, specifications-modules-GEC.md
                     point 5) : uniquement sur un courrier ARCHIVÉ — un
                     original ne se "prête" qu'une fois le dossier clos.
                     Jamais deux décharges actives en même temps pour le même
                     courrier (voir Courrier::dechargeActive(), un seul
                     emprunt non rendu possible — WorkflowService::emettreDecharge()
                     le revérifie aussi côté serveur). --}}
                @if ($this->peutEmettreDecharge)
                    <div class="mt-3 border-t border-brand-border pt-3 dark:border-zinc-700">
                        @if ($courrier->dechargeActive)
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <flux:text class="text-xs text-zinc-500">{{ __('Original emprunté') }}</flux:text>
                                    <div class="truncate text-sm font-medium">
                                        {{ $courrier->dechargeActive->numero_reference }} — {{ $courrier->dechargeActive->emprunteur->name }}
                                    </div>
                                    <flux:text class="text-xs text-zinc-400">{{ $courrier->dechargeActive->emprunte_le->format('d/m/Y H:i') }}</flux:text>
                                </div>
                                <flux:button size="sm" variant="outline" icon="arrow-uturn-left" wire:click="marquerDechargeRendue">
                                    {{ __('Marquer rendu') }}
                                </flux:button>
                            </div>
                        @else
                            <div class="flex items-center justify-between gap-2">
                                <flux:text class="text-xs text-zinc-500">{{ __('Original physique') }}</flux:text>
                                <flux:button size="sm" variant="outline" icon="arrow-up-tray" wire:click="ouvrirDecharge">
                                    {{ __('Emprunter l\'original') }}
                                </flux:button>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Module 3/9 — modale "Classer dans un dossier" : le déclencheur
                 vit dans "Actions rapides" ci-dessus, mais un flux:modal se
                 réfère par nom (pas par position DOM) — placée ici pour
                 rester proche de son déclencheur. Gated par peutClasser comme
                 le bouton lui-même — l'autorisation réelle est de toute façon
                 revérifiée côté serveur (ouvrirClassement/classerDansDossier/
                 retirerDuDossier), ceci évite juste d'exposer le formulaire
                 dans le DOM à qui ne peut pas l'utiliser. --}}
            @if ($this->peutClasser)
                <flux:modal name="classement-dossier-modal" class="w-full max-w-sm">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ __('Classer dans un dossier') }}</flux:heading>

                        @if ($this->dossiersAccessibles->isEmpty())
                            <flux:text class="text-zinc-500">{{ __('Aucun dossier de classement accessible. Créez-en un depuis "Dossiers & Archives".') }}</flux:text>
                        @else
                            {{-- 2026-10-12, bug réel (capture d'écran utilisateur,
                                 "Choisissez un dossier" affiché deux fois ET malgré
                                 un dossier visiblement affiché) — deux correctifs :
                                 1. <flux:select> (via <flux:with-field>, voir
                                    vendor/livewire/flux/stubs/.../with-field.blade.php
                                    ligne 49) rend DÉJÀ <flux:error :name="..."/>
                                    automatiquement — le @error(...) manuel qui
                                    suivait dupliquait le même message.
                                 2. :placeholder="..." rend un <option value=""
                                    disabled selected> (vendor, select/variants/
                                    default.blade.php) — un <select> natif ne peut
                                    PAS fiablement garder une option DÉSACTIVÉE
                                    "sélectionnée" après le morph Livewire qui
                                    synchronise wire:model=null : le navigateur
                                    retombe silencieusement sur la première option
                                    RÉELLE (ACTIVÉE) du menu, qui s'affiche donc
                                    comme choisie alors que dossierAClasserId reste
                                    bien null côté serveur (d'où le rejet correct à
                                    la soumission, mais trompeur visuellement — un
                                    seul dossier existant au moment du bug rendait
                                    le symptôme particulièrement visible). Remplacé
                                    par notre PROPRE première option, vide mais
                                    ACTIVÉE — un <select> natif peut toujours
                                    afficher fiablement une option activée. --}}
                            <flux:select wire:model="dossierAClasserId" :label="__('Dossier')">
                                <flux:select.option value="">{{ __('— Choisir un dossier —') }}</flux:select.option>
                                @foreach ($this->dossiersAccessibles as $dossier)
                                    <flux:select.option value="{{ $dossier->id }}">{{ $dossier->nom }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif

                        <div class="flex items-center justify-between gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                            @if ($courrier->dossier_classement_id)
                                <flux:button variant="ghost" icon="folder-minus" wire:click="retirerDuDossier">{{ __('Retirer du dossier') }}</flux:button>
                            @else
                                <span></span>
                            @endif
                            <div class="flex gap-2">
                                <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                                <flux:button variant="primary" icon="check" wire:click="classerDansDossier" :disabled="$this->dossiersAccessibles->isEmpty()">{{ __('Classer') }}</flux:button>
                            </div>
                        </div>
                    </div>
                </flux:modal>
            @endif

            {{-- Module 9 — modale "Émettre une décharge" (2026-10-05), même
                 patron que la modale "Classer dans un dossier" juste
                 au-dessus : déclencheur dans "Actions rapides", gated par
                 peutEmettreDecharge côté vue ET revérifié côté serveur
                 (ouvrirDecharge/emettreDecharge). --}}
            @if ($this->peutEmettreDecharge)
                <flux:modal name="decharge-modal" class="w-full max-w-sm">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ __('Emprunter l\'original') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Une décharge sera générée avec un numéro de reçu unique, tracée dans l\'historique du courrier.') }}
                        </flux:text>

                        {{-- 2026-10-12 — mêmes deux correctifs que la modale
                             "Classer dans un dossier" ci-dessus (@error
                             redondant avec <flux:with-field>, placeholder
                             désactivé peu fiable après un morph Livewire) : voir
                             son commentaire détaillé pour le mécanisme exact. --}}
                        <flux:select wire:model="dechargeEmprunteurId" :label="__('Emprunteur')">
                            <flux:select.option value="">{{ __('— Choisir un utilisateur —') }}</flux:select.option>
                            @foreach ($this->utilisateursPourDecharge as $utilisateur)
                                <flux:select.option value="{{ $utilisateur->id }}">{{ $utilisateur->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:textarea wire:model="dechargeMotif" :label="__('Motif (optionnel)')" rows="2" />

                        @if ($courrier->dossierClassement?->reference_localisation_physique)
                            <flux:text class="text-xs text-zinc-400">
                                {{ __('Rangement : :lieu', ['lieu' => $courrier->dossierClassement->reference_localisation_physique]) }}
                            </flux:text>
                        @endif

                        <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                            <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                            <flux:button variant="primary" icon="check" wire:click="emettreDecharge">{{ __('Émettre la décharge') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>
            @endif

            {{-- 2026-10-13, retour utilisateur ("je ne peut pa mettre un
                 delais") — le bouton "Traiter moi-même" de l'en-tête (plus
                 haut dans ce fichier) ouvre désormais cette modale plutôt
                 que d'agir directement : même patron que "Émettre une
                 décharge" juste au-dessus (déclencheur → ouvrirXxx() →
                 modale → action réelle), pour que le délai reste réglable
                 avant de confirmer, préempli avec le SLA par défaut
                 (ouvrirTraiterMoiMeme()). --}}
            @if ($this->peutTraiterMoiMeme)
                <flux:modal name="traiter-moi-meme-modal" class="w-full max-w-sm">
                    <div class="space-y-4">
                        <flux:heading size="lg">{{ __('Traiter moi-même') }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Ce courrier vous sera affecté et son traitement démarrera immédiatement.') }}
                        </flux:text>

                        <div class="flex flex-wrap items-end gap-2">
                            <flux:input type="number" min="1" wire:model="delaiValeur" size="sm" :label="__('Délai de traitement')" class="w-28" />
                            <flux:select wire:model="delaiUnite" size="sm" :label="__('Unité')" class="w-28">
                                <flux:select.option value="jours">{{ __('jours') }}</flux:select.option>
                                <flux:select.option value="heures">{{ __('heures') }}</flux:select.option>
                            </flux:select>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                            <flux:modal.close><flux:button variant="ghost">{{ __('Annuler') }}</flux:button></flux:modal.close>
                            <flux:button variant="primary" icon="check" wire:click="traiterMoiMeme">{{ __('Confirmer') }}</flux:button>
                        </div>
                    </div>
                </flux:modal>
            @endif

            @if ($courrier->expediteur_nom || $courrier->expediteur_organisation || $courrier->expediteur_telephone || $courrier->expediteur_email)
                <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 flex items-center gap-2">
                        <flux:icon.user class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Informations sur l\'expéditeur') }}</flux:heading>
                    </div>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Nom') }}</dt><dd class="font-medium">{{ $courrier->expediteur_nom ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Organisation') }}</dt><dd class="font-medium">{{ $courrier->expediteur_organisation ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Email') }}</dt><dd class="font-medium">{{ $courrier->expediteur_email ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Téléphone') }}</dt><dd class="font-medium">{{ $courrier->expediteur_telephone ?: '—' }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-zinc-500">{{ __('Adresse') }}</dt><dd class="text-end font-medium">{{ $courrier->expediteur_adresse ?: '—' }}</dd></div>
                    </dl>
                </div>
            @endif

            @if ($courrier->destinataire)
                <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 flex items-center gap-2">
                        <flux:icon.users class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Informations sur le destinataire') }}</flux:heading>
                    </div>
                    <flux:text>{{ $courrier->destinataire }}</flux:text>
                </div>
            @endif
        </div>
    </div>
</section>
