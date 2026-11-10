{{--
    Pièce jointe consultable en aperçu sécurisé (2026-11-09, voir DECISIONS.md
    « Fichiers : chiffrement, aperçu sécurisé, aucun téléchargement ») : le nom
    ouvre une modale avec la même visionneuse que le document principal
    (pages filigranées, jamais le fichier). Remplace l'ancien lien de
    téléchargement. `suffixe` distingue deux listes de la même page (onglet
    « Pièces jointes » et carte « Fichiers joints » de la fiche) : chaque
    modale doit porter un nom unique.

    Droits revérifiés côté serveur à chaque page (ApercuSecurise::autoriser) ;
    l'appelant n'affiche ce composant qu'à qui peut voir le contenu.
--}}
@props(['pieceJointe', 'suffixe' => 'liste', 'reference' => null])

@php
    $modale = 'apercu-pj-'.$pieceJointe->id.'-'.$suffixe;
@endphp

<button type="button" x-on:click="$dispatch('modal-show', { name: @js($modale) })" {{ $attributes->class('inline-flex min-w-0 items-center gap-1.5 text-left text-brand-accent hover:underline') }}>
    <flux:icon.eye variant="micro" class="size-4 shrink-0" />
    <span class="truncate">{{ $pieceJointe->nom_original }}</span>
</button>

<div
    wire:key="{{ $modale }}"
    wire:ignore
    x-data="{
        erreur: null,
        zoom: 100,
        pageActuelle: 1,
        numPages: 1,
        url: @js(route('apercus.manifeste', ['piece-jointe', $pieceJointe->id])),
        instance() {
            const apercu = window.DocumentPreview.obtenir(this.url + ':' + @js($suffixe));
            apercu.boite = document.getElementById(@js($modale.'-corps'));
            apercu.conteneur = document.getElementById(@js($modale.'-conteneur'));

            return apercu;
        },
        async rendre() {
            try {
                // Une image après l'ouverture de la modale : sa taille est
                // alors connue (calcul du zoom « 100 % »).
                await new Promise((fin) => requestAnimationFrame(() => fin()));
                const apercu = this.instance();
                window.DocumentPreview.activerDeplacement(apercu.boite);
                window.DocumentPreview.activerZoomMolette(apercu.boite, (pas) => this.zoomer(Math.min(Math.max(this.zoom + pas, 50), 200)));
                await apercu.charger(this.url, apercu.boite, apercu.conteneur, false, true);
                this.zoom = Math.round((apercu.echelle / apercu.echelleBase) * 100);
                this.pageActuelle = apercu.pageActuelle;
                this.numPages = apercu.numPages;
            } catch (e) {
                console.error('[aperçu pièce jointe]', e);
                this.erreur = e.message ?? String(e);
            }
        },
        async zoomer(nouveauZoom) {
            this.zoom = nouveauZoom;

            try {
                await this.instance().zoomer(this.zoom);
            } catch (e) {
                this.erreur = e.message ?? String(e);
            }
        },
        zoomIn() { this.zoomer(Math.min(this.zoom + 25, 200)); },
        zoomOut() { this.zoomer(Math.max(this.zoom - 25, 50)); },
        async pageSuivante() {
            const apercu = this.instance();
            await apercu.pageSuivante();
            this.pageActuelle = apercu.pageActuelle;
        },
        async pagePrecedente() {
            const apercu = this.instance();
            await apercu.pagePrecedente();
            this.pageActuelle = apercu.pageActuelle;
        },
    }"
    x-on:modal-show.document="if ($event.detail.name === @js($modale)) { rendre(); }"
>
    {{-- Hauteur imposée sur la balise flux:modal elle-même (variant bare),
         jamais display forcé : voir le même motif dans showCourrier.blade.php. --}}
    <flux:modal :name="$modale" variant="bare" class="h-[88vh]! max-h-[88vh]! w-full! max-w-5xl! overflow-hidden! rounded-xl! bg-white! p-0! shadow-lg! dark:bg-zinc-900!">
        <div class="flex h-full min-h-0 flex-col">
            <x-apercu.barre-outils
                variant="modale"
                :nom-fichier="$pieceJointe->nom_original"
                :numero-reference="$reference"
                :fermer-modale="$modale"
            />
            <x-apercu.visionneuse :id="$modale" grandir />
        </div>
    </flux:modal>
</div>
