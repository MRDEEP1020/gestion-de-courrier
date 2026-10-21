{{-- Nouvelle maquette "Modifier le courrier" fournie par l'utilisateur
     (2026-09-18) — remplace ENTIÈREMENT l'ancienne mise en page à une
     colonne ("this is how i want the modify/edit design to look like...
     drop that orther one"). Clarifié avec l'utilisateur (AskUserQuestion) :
     (1) les champs génuinement nouveaux de la maquette (Direction d'origine,
     Échéance, Type de traitement, Fonction de l'expéditeur, Commentaire/
     Note interne) sont ajoutés pour de vrai en base (voir la migration
     2026_09_18_070000) plutôt que fabriqués côté vue — TOUS nullable, sans
     astérisque "obligatoire" puisqu'aucune règle métier ne les impose
     encore ; (2) "Type"/"Catégorie"/"Service émetteur" de la maquette sont
     de simples relibellés des champs existants sens/type_document/
     service_id — jamais dupliqués sous un nouveau nom ; (3) seuls 2 onglets
     réels ("Informations générales" et "Pièces jointes") — Documents/
     Historique/Affectation & Transfert/Traitement & Workflow/Suivi &
     Traçabilité de la maquette dupliqueraient la logique de circuit qui
     vit déjà sur showCourrier.blade.php, hors périmètre de cette demande. --}}
@assets
    @vite('resources/js/document-preview.js')
@endassets

<section class="w-full" x-data="{ onglet: 'general' }">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('courriers.rechercher')" wire:navigate>{{ __('Courriers') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('courriers.rechercher')" wire:navigate>{{ __('Tous les courriers') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('courriers.show', $courrierId)" wire:navigate>{{ $numeroReference }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Modifier') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <form wire:submit="enregistrerModification">
        <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
                    <flux:icon.envelope class="size-5" />
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading level="1">{{ __('Modifier le courrier') }}</flux:heading>
                        <x-statut-badge :statut="$this->courrier->statut" />
                        <x-chronometre :courrier="$this->courrier" />
                    </div>
                    <flux:subheading>{{ $numeroReference }} — {{ __('le numéro de référence ne peut pas être modifié.') }}</flux:subheading>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button variant="outline" icon="arrow-left" :href="route('courriers.show', $courrierId)" wire:navigate>
                    {{ __('Retour') }}
                </flux:button>
                <flux:button variant="primary" icon="check" type="submit">
                    {{ __('Enregistrer') }}
                </flux:button>
                <flux:button variant="outline" icon="x-mark" :href="route('courriers.show', $courrierId)" wire:navigate>
                    {{ __('Annuler') }}
                </flux:button>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div>
                <div class="overflow-x-auto rounded-2xl border border-brand-border bg-white p-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex min-w-max items-center gap-1">
                        <flux:button size="sm" x-on:click="onglet = 'general'" x-bind:variant="onglet === 'general' ? 'primary' : 'ghost'" icon="document-text">
                            {{ __('Informations générales') }}
                        </flux:button>
                        <flux:button size="sm" x-on:click="onglet = 'pieces-jointes'" x-bind:variant="onglet === 'pieces-jointes' ? 'primary' : 'ghost'" icon="paper-clip">
                            {{ __('Pièces jointes') }} ({{ $this->courrier->piecesJointes->count() }})
                        </flux:button>
                    </div>
                </div>

                {{-- ONGLET INFORMATIONS GÉNÉRALES --}}
                <div x-show="onglet === 'general'" x-cloak class="mt-4 space-y-6">
                    <div class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="mb-4 flex items-center gap-2">
                            <flux:icon.information-circle class="size-4 text-brand-accent" />
                            <flux:heading level="3">{{ __('Informations générales') }}</flux:heading>
                        </div>

                        <div class="grid gap-6 sm:grid-cols-2">
                            <flux:input value="{{ $numeroReference }}" :label="__('Numéro du courrier')" disabled />

                            <flux:select wire:model="directionOrigineId" :label="__('Direction d\'origine')" placeholder="{{ __('— Non renseignée —') }}">
                                @foreach ($this->services as $service)
                                    <flux:select.option value="{{ $service->id }}">{{ $service->nom }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            {{-- "Type" de la maquette = notre champ "Sens" existant
                                 (Entrant/Sortant) — relibellé, pas dupliqué. --}}
                            <flux:select wire:model.live="form.sens" :label="__('Type')" required>
                                <flux:select.option value="entrant">{{ __('Courrier entrant') }}</flux:select.option>
                                <flux:select.option value="sortant">{{ __('Courrier sortant') }}</flux:select.option>
                            </flux:select>

                            {{-- Le service n'est plus saisi/modifié par l'agent pour un
                                 courrier ENTRANT (2026-09-15, voir DECISIONS.md
                                 "Synchronisation avec le nouveau document SRS-GEC.pdf") —
                                 c'est le DGA/ADJ DGA qui le choisit au moment de valider le
                                 transfert. Le sortant garde le champ, requis comme avant.
                                 "Service émetteur" de la maquette = notre champ
                                 "service_id" existant, relibellé. --}}
                            @if ($form->sens === 'sortant')
                                <flux:select wire:model="form.service_id" :label="__('Service émetteur')" placeholder="{{ __('Sélectionner un service') }}" required>
                                    @foreach ($this->services as $service)
                                        <flux:select.option value="{{ $service->id }}">{{ $service->nom }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @else
                                <flux:text class="self-end pb-2 text-sm text-zinc-500">
                                    {{ __('Le service sera choisi par le DGA/ADJ DGA au moment de valider le transfert.') }}
                                </flux:text>
                            @endif

                            <flux:input wire:model="form.objet" :label="__('Objet')" required />

                            {{-- "Catégorie" de la maquette = notre champ "Type de
                                 document" existant, relibellé (specifications-modules-
                                 GEC.md emploie déjà "catégorie" comme synonyme). --}}
                            <flux:field>
                                @if ($typeDocumentPersonnalise)
                                    <flux:input wire:model="form.type_document" :label="__('Catégorie')" placeholder="{{ __('Précisez la catégorie…') }}" required />
                                    <flux:link href="#" wire:click.prevent="choisirTypeDocumentDansLaListe" class="text-sm">{{ __('Choisir dans la liste plutôt') }}</flux:link>
                                @else
                                    <flux:select wire:model.live="form.type_document" :label="__('Catégorie')" placeholder="{{ __('— Choisir —') }}" required>
                                        @foreach (\App\Livewire\Backend\Forms\CourrierForm::typesDocument() as $type)
                                            <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                                        @endforeach
                                        <flux:select.option value="__autre__">{{ __('Autre (préciser)') }}</flux:select.option>
                                    </flux:select>
                                @endif
                            </flux:field>

                            <flux:input type="date" wire:model="form.date_mouvement" :label="__('Date de réception / d\'envoi')" required />

                            <flux:select wire:model="form.priorite" :label="__('Priorité')" required>
                                <flux:select.option value="basse">{{ __('Basse') }}</flux:select.option>
                                <flux:select.option value="normale">{{ __('Normale') }}</flux:select.option>
                                <flux:select.option value="haute">{{ __('Haute') }}</flux:select.option>
                                <flux:select.option value="urgente">{{ __('Urgente') }}</flux:select.option>
                            </flux:select>

                            <flux:select wire:model="form.mode_reception" :label="__('Mode de réception')" required>
                                <flux:select.option value="depot_physique">{{ __('Dépôt physique') }}</flux:select.option>
                                <flux:select.option value="email">{{ __('Email') }}</flux:select.option>
                                <flux:select.option value="poste">{{ __('Poste') }}</flux:select.option>
                                <flux:select.option value="fax">{{ __('Fax') }}</flux:select.option>
                            </flux:select>

                            <flux:input type="date" wire:model="echeance" :label="__('Échéance')" />

                            <flux:select wire:model="form.confidentialite" :label="__('Confidentialité')" required>
                                @foreach (range(1, \App\Models\User::niveauConfidentialiteMax()) as $niveau)
                                    <flux:select.option value="{{ $niveau }}">{{ __('Niveau :n', ['n' => $niveau]) }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:select wire:model="form.sous_type_sinistre" :label="__('Sous-type (si sinistre)')" placeholder="{{ __('— Sans objet —') }}">
                                <flux:select.option value="">{{ __('— Sans objet —') }}</flux:select.option>
                                <flux:select.option value="materiel">{{ __('Matériel') }}</flux:select.option>
                                <flux:select.option value="corporel">{{ __('Corporel') }}</flux:select.option>
                            </flux:select>

                            <flux:field>
                                <flux:label>{{ __('État actuel') }}</flux:label>
                                <div class="mt-1.5"><x-statut-avec-echeance :courrier="$this->courrier" /></div>
                            </flux:field>

                            <flux:input wire:model="typeTraitement" :label="__('Type de traitement')" placeholder="{{ __('ex. Traitement classique, urgent…') }}" />

                            <flux:input wire:model="referenceExterne" :label="__('Référence externe')" placeholder="{{ __('Référence donnée par l\'expéditeur, si connue') }}" />

                            <flux:input wire:model="dossierReference" :label="__('Dossier lié')" placeholder="{{ __('ex. DOS-2026-0001') }}" />

                            <flux:input type="number" wire:model="slaJours" :label="__('SLA (jours)')" min="0" max="365" />

                            <flux:select wire:model="serviceResponsableId" :label="__('Service responsable')" placeholder="{{ __('— Non renseigné —') }}">
                                @foreach ($this->services as $service)
                                    <flux:select.option value="{{ $service->id }}">{{ $service->nom }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="mb-4 flex items-center gap-2">
                            <flux:icon.user-plus class="size-4 text-brand-accent" />
                            <flux:heading level="3">{{ __('Expéditeur / Destinataire') }}</flux:heading>
                        </div>

                        <div class="grid gap-6 sm:grid-cols-2">
                            <flux:input wire:model="form.expediteur_nom" :label="__('Expéditeur')" />
                            <flux:input wire:model="expediteurFonction" :label="__('Fonction')" placeholder="{{ __('ex. Chef de service') }}" />
                        </div>
                        <div class="mt-6 grid gap-6 sm:grid-cols-2">
                            <flux:input wire:model="form.expediteur_organisation" :label="__('Organisation')" />
                            <flux:input wire:model="form.expediteur_telephone" :label="__('Téléphone')" />
                        </div>
                        <div class="mt-6 grid gap-6 sm:grid-cols-2">
                            <flux:input type="email" wire:model="form.expediteur_email" :label="__('Email')" />
                            <flux:input wire:model="form.expediteur_adresse" :label="__('Adresse')" />
                        </div>
                        <div class="mt-6 grid gap-6 sm:grid-cols-2">
                            <flux:input wire:model="form.expediteur_rc" :label="__('RC (Registre du Commerce)')" />
                            <flux:input wire:model="form.expediteur_niu" :label="__('NIU (Numéro d\'Identifiant Unique)')" />
                        </div>

                        <flux:textarea wire:model="form.destinataire" :label="__('Destinataire(s)')" rows="2" class="mt-6" />
                    </div>

                    <div class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="mb-4 flex items-center gap-2">
                            <flux:icon.chat-bubble-bottom-center-text class="size-4 text-brand-accent" />
                            <flux:heading level="3">{{ __('Résumé / Commentaire') }}</flux:heading>
                        </div>

                        <flux:field>
                            <flux:textarea wire:model="noteInterne" :label="__('Commentaire / Note interne')" rows="3" maxlength="1000" placeholder="{{ __('Note visible uniquement par les agents internes…') }}" />
                            <flux:description class="text-end">{{ strlen($noteInterne ?? '') }}/1000</flux:description>
                        </flux:field>
                    </div>
                </div>

                {{-- ONGLET PIÈCES JOINTES --}}
                <div x-show="onglet === 'pieces-jointes'" x-cloak class="mt-4 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    @if (auth()->user()->cannot('voirPiecesJointes', $this->courrier))
                        {{-- courriers.voir_pieces_jointes (2026-09-23). --}}
                        <flux:text class="text-zinc-500">{{ __('Vous n\'avez pas accès aux pièces jointes de ce courrier.') }}</flux:text>
                    @elseif ($this->courrier->piecesJointes->isEmpty())
                        <flux:text class="text-zinc-500">{{ __('Aucune pièce jointe pour ce courrier.') }}</flux:text>
                    @else
                        {{-- Liens de téléchargement derrière courriers.telecharger (2026-09-23). --}}
                        @php $peutTelecharger = auth()->user()->can('telecharger', $this->courrier); @endphp
                        <ul class="space-y-2">
                            @foreach ($this->courrier->piecesJointes as $pieceJointe)
                                <li class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <flux:icon name="paper-clip" class="size-4 shrink-0 text-zinc-400" />
                                        @if ($peutTelecharger)
                                            <flux:link :href="route('pieces-jointes.telecharger', $pieceJointe)">{{ $pieceJointe->nom_original }}</flux:link>
                                        @else
                                            <span>{{ $pieceJointe->nom_original }}</span>
                                        @endif
                                    </div>
                                    @if ($peutTelecharger)
                                        <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('pieces-jointes.telecharger', $pieceJointe)" :aria-label="__('Télécharger')" />
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <flux:separator class="my-4" text="{{ __('Ajouter une pièce jointe') }}" />

                    <flux:field>
                        <flux:label>{{ __('Fichier (optionnel — copie déjà numérique, ex. pièce jointe d\'un email)') }}</flux:label>
                        <input type="file" wire:model="pieceJointe" accept=".pdf,.jpg,.jpeg,.png"
                            class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:hover:file:bg-zinc-600" />
                        <flux:description>{{ __('PDF, JPG ou PNG, 10 Mo maximum.') }}</flux:description>
                        <flux:error name="pieceJointe" />
                        <div wire:loading wire:target="pieceJointe" class="mt-1 text-sm text-zinc-500">{{ __('Envoi en cours…') }}</div>
                    </flux:field>
                </div>
            </div>

            {{-- Colonne latérale : "Document principal" (aperçu, même motif
                 canevas pdfjs-dist/document-preview.js que showCourrier.blade.php
                 et "Tous les courriers") + résumé complémentaire, TOUJOURS
                 affichée, jamais masquée — demande explicite de l'utilisateur
                 étendue à toutes les pages à panneau aperçu, rendue sticky
                 (voir le journal de développement, 2026-09-18 05:30). --}}
            <div class="sticky top-20 self-start space-y-6">
                <div class="rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center gap-2 border-b border-brand-border p-4 dark:border-zinc-700">
                        <flux:icon.document class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Document principal') }}</flux:heading>
                    </div>

                    <div class="p-4">
                        @if ($this->courrier->fichier_path)
                            @php $telechargerUrlEdition = auth()->user()->can('telecharger', $this->courrier) ? route('courriers.document', $this->courrier) : null; @endphp
                            <div
                                wire:ignore
                                x-data="{
                                    erreur: null,
                                    zoom: 100,
                                    pageActuelle: 1,
                                    numPages: 1,
                                    url: @js(route('courriers.document.apercu', $this->courrier)),
                                    instance() {
                                        const apercu = window.DocumentPreview.obtenir(this.url + ':edition');
                                        apercu.boite = document.getElementById('apercu-edition-corps');
                                        apercu.conteneur = document.getElementById('apercu-edition-conteneur');

                                        return apercu;
                                    },
                                    async init() {
                                        try {
                                            const apercu = this.instance();
                                            // Cliquer-glisser (2026-09-24) —
                                            // plutôt que de devoir viser la
                                            // barre de défilement une fois
                                            // zoomé au-delà de 100 %.
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
                                            console.error('[aperçu édition courrier]', e);
                                            this.erreur = e.message ?? String(e);
                                        }
                                    },
                                    async zoomer(nouveauZoom) {
                                        this.zoom = nouveauZoom;

                                        try {
                                            await this.instance().zoomer(this.zoom);
                                        } catch (e) {
                                            console.error('[aperçu édition courrier] zoom', e);
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
                                            console.error('[aperçu édition courrier] page suivante', e);
                                            this.erreur = e.message ?? String(e);
                                        }
                                    },
                                    async pagePrecedente() {
                                        try {
                                            const apercu = this.instance();
                                            await apercu.pagePrecedente();
                                            this.pageActuelle = apercu.pageActuelle;
                                        } catch (e) {
                                            console.error('[aperçu édition courrier] page précédente', e);
                                            this.erreur = e.message ?? String(e);
                                        }
                                    },
                                }"
                                x-init="init()"
                            >
                                <x-apercu.barre-outils
                                    variant="panneau"
                                    :nom-fichier="basename($this->courrier->fichier_path)"
                                    agrandir-evenement="apercu-edition-agrandi"
                                    :telecharger-url="$telechargerUrlEdition"
                                />
                                <x-apercu.visionneuse id="apercu-edition" />
                            </div>

                            {{-- Modale "Agrandir" — même patron corrigé que les autres
                                 pages (hauteur imposée EXPLICITEMENT sur <flux:modal>
                                 lui-même, corps en flex-1 min-h-0). Instance pdf.js
                                 séparée du petit panneau ci-dessus. --}}
                            <div
                                wire:key="apercu-edition-agrandi-{{ $this->courrier->id }}"
                                wire:ignore
                                x-data="{
                                    erreur: null,
                                    zoom: 100,
                                    url: @js(route('courriers.document.apercu', $this->courrier)),
                                    pageActuelle: 1,
                                    numPages: 1,
                                    instance() {
                                        const apercu = window.DocumentPreview.obtenir(this.url + ':edition-agrandi');
                                        apercu.boite = document.getElementById('apercu-edition-agrandi-corps');
                                        apercu.conteneur = document.getElementById('apercu-edition-agrandi-conteneur');

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
                                            console.error('[aperçu édition agrandi]', e);
                                            this.erreur = e.message ?? String(e);
                                        }
                                    },
                                    async zoomer(nouveauZoom) {
                                        this.zoom = nouveauZoom;

                                        try {
                                            await this.instance().zoomer(this.zoom);
                                        } catch (e) {
                                            console.error('[aperçu édition agrandi] zoom', e);
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
                                            console.error('[aperçu édition agrandi] page suivante', e);
                                            this.erreur = e.message ?? String(e);
                                        }
                                    },
                                    async pagePrecedente() {
                                        try {
                                            const apercu = this.instance();
                                            await apercu.pagePrecedente();
                                            this.pageActuelle = apercu.pageActuelle;
                                        } catch (e) {
                                            console.error('[aperçu édition agrandi] page précédente', e);
                                            this.erreur = e.message ?? String(e);
                                        }
                                    },
                                }"
                                x-on:modal-show.document="if ($event.detail.name === 'apercu-edition-agrandi') { rendre(); }"
                            >
                                {{-- 2026-09-24 — SANS "display: flex" forcé (`flex!`) ICI :
                                     ça écrasait la règle par défaut du navigateur qui cache un
                                     popover fermé (`display: none`), laissant la modale réserver
                                     ses 88vh de hauteur EN PERMANENCE dans le flux de la page,
                                     même fermée (grand espace vide sous la fiche). La mise en
                                     page en colonne reste sur le DIV intérieur juste en dessous. --}}
                                <flux:modal name="apercu-edition-agrandi" variant="bare" class="h-[88vh]! max-h-[88vh]! w-full! max-w-7xl! overflow-hidden! rounded-xl! bg-white! p-0! shadow-lg! dark:bg-zinc-900!">
                                    <div class="flex h-full min-h-0 flex-col">
                                        <x-apercu.barre-outils
                                            variant="modale"
                                            :nom-fichier="basename($this->courrier->fichier_path)"
                                            :numero-reference="$this->courrier->numero_reference"
                                            :telecharger-url="$telechargerUrlEdition"
                                            fermer-modale="apercu-edition-agrandi"
                                        />

                                        <div class="flex min-h-0 flex-1 flex-col min-[900px]:flex-row">
                                            <x-apercu.visionneuse id="apercu-edition-agrandi" grandir />
                                            <div class="h-64 shrink-0 min-[900px]:h-auto min-[900px]:w-90">
                                                <x-apercu.panneau-correspondance
                                                    :objet="$this->courrier->objet"
                                                    :numero-reference="$this->courrier->numero_reference"
                                                    :expediteur-nom="$this->courrier->expediteur_nom"
                                                    :expediteur-organisation="$this->courrier->expediteur_organisation"
                                                    :expediteur-fonction="$this->courrier->expediteur_fonction"
                                                    :expediteur-adresse="$this->courrier->expediteur_adresse"
                                                    :expediteur-telephone="$this->courrier->expediteur_telephone"
                                                    :expediteur-email="$this->courrier->expediteur_email"
                                                    :destinataire="$this->courrier->destinataire"
                                                    :date="$this->courrier->date_mouvement"
                                                    :nom-fichier="basename($this->courrier->fichier_path)"
                                                    :telecharger-url="$telechargerUrlEdition"
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

                <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-3 flex items-center gap-2">
                        <flux:icon.squares-2x2 class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Informations complémentaires') }}</flux:heading>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs uppercase text-zinc-500">{{ __('Demandeur') }}</dt>
                            <dd class="font-medium">{{ $this->courrier->expediteur_nom ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-zinc-500">{{ __('Téléphone') }}</dt>
                            <dd class="font-medium">{{ $this->courrier->expediteur_telephone ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-zinc-500">{{ __('Pièce(s) jointe(s)') }}</dt>
                            <dd class="font-medium">{{ __(':n fichier(s)', ['n' => $this->courrier->piecesJointes->count()]) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-zinc-500">{{ __('Confidentialité') }}</dt>
                            <dd class="mt-0.5">
                                {{-- Niveau NUMÉRIQUE (2026-09-21, "numbers ... not
                                     confidential or whatever", puis "THE LABEL SHOULD BE
                                     NIVEAU 1 OR LEVEL 1"). --}}
                                @if ($this->courrier->confidentialite <= 1)
                                    <span class="inline-flex items-center rounded-full bg-brand-success-light px-2 py-0.5 text-xs font-medium text-brand-success-dark">{{ __('Niveau :n', ['n' => $this->courrier->confidentialite]) }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-brand-navy px-2 py-0.5 text-xs font-medium text-white">
                                        {{ __('Niveau :n', ['n' => $this->courrier->confidentialite]) }}
                                    </span>
                                @endif
                            </dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-xs uppercase text-zinc-500">{{ __('Référence interne') }}</dt>
                            <dd class="font-medium">{{ $numeroReference }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="flex items-start gap-2 rounded-2xl bg-brand-accent-pale p-4 text-sm text-brand-accent-medium">
                    <flux:icon.information-circle class="size-4 shrink-0" />
                    <span>{{ __('Les modifications seront enregistrées dans l\'historique du courrier et tracées dans le journal des actions.') }}</span>
                </div>
            </div>
        </div>
    </form>
</section>
