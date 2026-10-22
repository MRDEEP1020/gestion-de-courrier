// Aperçu agrandi d'un document (brouillon ou courrier) — modale
// "Aperçu du courrier" (2026-09-17, maquette utilisateur) : rendu PDF sur
// <canvas> via pdfjs-dist plutôt que de laisser le navigateur afficher le
// PDF nativement (<iframe>/<object>) — le lecteur PDF natif de Chrome/
// Firefox suit le thème sombre du SYSTÈME pour son propre habillage
// (letterboxing gris/noir autour de la page), ce qui est impossible à
// forcer en blanc depuis notre CSS puisque ce chrome n'appartient pas au
// document rendu. Un <canvas> que NOUS dessinons est entièrement sous
// notre contrôle (fond blanc garanti, zoom réel par nouveau rendu à
// l'échelle demandée).
//
// Recherche : une vraie couche de texte pdf.js (TextLayer, la même classe
// que le lecteur officiel de Mozilla utilise) est superposée à chaque page
// canvas — la recherche marque les blocs de texte contenant l'occurrence
// avec la classe `.highlight` déjà fournie par le CSS pdf_viewer.css de
// pdfjs-dist. Optionnelle (voir `avecTexte` ci-dessous) : la petite
// miniature de la carte "Aperçu du document" n'en a pas besoin.
//
// Le rendu du <canvas> (la partie visible, indispensable) est protégé par
// son propre try/catch, SÉPARÉ de celui de la couche de texte (accessoire) :
// une erreur pdf.js sur la couche de texte ne doit jamais empêcher la page
// elle-même de s'afficher.
//
// IMPORTANT côté appelant (Blade/Alpine) : tout le code qui utilise ce
// module doit vivre dans une MÉTHODE d'un objet x-data, jamais inliné
// directement comme valeur d'un attribut x-init/x-on — l'évaluateur
// d'expressions d'Alpine (livewire.js embarque sa propre copie d'Alpine)
// n'accepte pas un bloc `try { ... } catch { ... }` comme expression brute
// ("Unexpected token 'try'", vu en conditions réelles) ; il attend une
// expression ou un appel de fonction, pas un bloc de contrôle au premier
// niveau. Toujours : `x-data="{ async init() { try {...} catch {...} } }"`
// puis `x-init="init()"` — jamais le try/catch inline dans x-init lui-même.
import * as pdfjsLib from 'pdfjs-dist';
import pdfjsWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { TextLayer } from 'pdfjs-dist';
import 'pdfjs-dist/web/pdf_viewer.css';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfjsWorkerUrl;

class ApercuDocument {
    constructor() {
        this.pdf = null;
        this.conteneur = null;
        this.echelle = 1;
        // Échelle correspondant à "100 %" — calculée pour que la PAGE
        // ENTIÈRE tienne dans la boîte visible (voir charger() ci-dessous),
        // au lieu d'une constante fixe qui ne correspond à la taille
        // réelle d'aucun conteneur en particulier et forçait un défilement
        // (demande explicite de l'utilisateur : "i don't want scrolling").
        this.echelleBase = 1;
        this.avecTexte = true;
        // Une page à la fois, avec navigation (page suivante/précédente),
        // plutôt que toutes les pages empilées avec défilement vertical —
        // demande explicite de l'utilisateur.
        this.pageActuelle = 1;
        this.numPages = 1;
        this.pages = []; // { textDivs, textStrings } — une seule entrée : la page actuelle
        // Compteur de génération anti-course (voir rendre() ci-dessous) —
        // corrige un bug réel constaté par l'utilisateur (capture d'écran :
        // la même page rendue deux fois, empilée verticalement) : si deux
        // rendre() se chevauchent (ex. init() rappelé pendant qu'un premier
        // rendu était encore en cours — plausible pendant un remorphage
        // Livewire déclenché par un événement temps réel juste après
        // l'ouverture de la fiche), les DEUX vidaient puis remplissaient
        // this.conteneur, mais l'ordre réel des opérations pouvait
        // intercaler les deux vidages AVANT les deux ajouts — laissant les
        // deux pages empilées au lieu d'une seule.
        this.idRendu = 0;
    }

    // `boite` : élément DOM dont on lit clientWidth (et clientHeight si
    // `limiterHauteur`) pour calculer l'échelle "100 %". `conteneur` est
    // l'élément où les pages sont réellement rendues (descendant de
    // `boite`, dont les dimensions seraient faussées par son propre
    // contenu une fois rendu, d'où la mesure séparée sur `boite`).
    //
    // `limiterHauteur` distingue deux contextes réels de cette page :
    // - false (panneau "Aperçu du document", colonne latérale, hauteur
    //   libre) : "100 %" remplit la LARGEUR, et rendre() élargit ensuite la
    //   boîte pour épouser exactement la hauteur du rendu — jamais d'espace
    //   vide, jamais de recadrage, jamais de défilement.
    // - true (modale "Agrandir", hauteur plafonnée à 75vh par la mise en
    //   page de la modale — on ne peut pas l'élargir sans casser cette
    //   mise en page) : "100 %" est le plus petit des deux ratios largeur/
    //   hauteur, pour ne jamais dépasser la boîte dans AUCUNE dimension —
    //   au prix d'un éventuel (mineur) espace vide sur la dimension non
    //   contraignante, acceptable dans une modale spacieuse.
    async charger(url, boite, conteneur, avecTexte = true, limiterHauteur = false) {
        // Erreur explicite plutôt qu'un cryptique "Cannot read properties of
        // undefined (reading 'clientWidth')" — vu en conditions réelles
        // quand l'élément référencé (boîte de mesure ou conteneur de rendu)
        // n'existe pas encore au moment de l'appel.
        if (! boite || ! conteneur) {
            throw new Error('Conteneur d’aperçu introuvable dans la page.');
        }

        this.boite = boite;
        this.conteneur = conteneur;
        this.avecTexte = avecTexte;
        this.limiterHauteur = limiterHauteur;

        // Si cette instance (voir le registre en bas de fichier) a déjà
        // chargé ce PDF précédemment — typiquement parce que Livewire a
        // remorphé/recréé l'élément DOM hôte et que init() a été rappelé —
        // on NE refait PAS la requête réseau ni le calcul d'échelle : on se
        // contente de redessiner dans le (peut-être nouveau) conteneur, à
        // l'échelle et la page où l'utilisateur en était. Sans ça, chaque
        // remorphage réinitialiserait silencieusement le zoom/la page.
        if (this.pdf) {
            await this.rendre();

            return;
        }

        // Le fichier est récupéré nous-mêmes via fetch() (données brutes en
        // mémoire) plutôt que de laisser pdfjsLib.getDocument(url) gérer le
        // réseau lui-même (requêtes par plage HTTP) — cette route a
        // toujours fonctionné pour <img>/<iframe>/téléchargement, donc un
        // fetch() simple retire toute incertitude propre au transport
        // réseau interne de pdf.js.
        const reponse = await fetch(url, { credentials: 'same-origin' });

        if (reponse.status === 404) {
            throw new Error('Document introuvable sur le stockage — signalez-le à un administrateur.');
        }

        if (! reponse.ok) {
            throw new Error(`Réponse HTTP ${reponse.status} en récupérant le document.`);
        }

        const donnees = await reponse.arrayBuffer();

        this.pdf = await pdfjsLib.getDocument({ data: donnees }).promise;
        this.numPages = this.pdf.numPages;
        this.pageActuelle = 1;

        // Ajustement sur la PREMIÈRE page (les pages suivantes d'un même
        // document ont presque toujours le même format). Marge de 2 % dans
        // les deux cas : sans elle, un écart d'arrondi entre la largeur
        // mesurée et la largeur réellement disponible (bordure, ombre du
        // canvas, hauteur de ligne de la couche de texte) suffit à
        // déclencher une barre de défilement même quand la page "devrait"
        // tenir exactement.
        const page1 = await this.pdf.getPage(1);
        this.viewportNatif = page1.getViewport({ scale: 1 });

        this.echelleBase = this.limiterHauteur
            ? Math.min(boite.clientWidth / this.viewportNatif.width, boite.clientHeight / this.viewportNatif.height) * 0.96
            : (boite.clientWidth / this.viewportNatif.width) * 0.98;
        this.echelle = this.echelleBase;

        await this.rendre();
    }

    // `pourcentage` : 100 = échelle de base (page remplit la largeur de la
    // boîte), 200 = deux fois plus grand que cette base, etc. — pas une
    // échelle pdf.js brute (voir echelleBase ci-dessus).
    async zoomer(pourcentage) {
        this.echelle = this.echelleBase * (pourcentage / 100);

        await this.rendre();
    }

    // Ne rend que this.pageActuelle — pas toutes les pages empilées (voir
    // pageSuivante()/pagePrecedente() ci-dessous pour naviguer).
    async rendre() {
        if (! this.pdf || ! this.conteneur) {
            return;
        }

        // Cet appel devient la référence ; tout appel plus ANCIEN encore en
        // cours doit s'arrêter dès qu'il s'en aperçoit, à chaque point de
        // reprise après un await, plutôt que de continuer à modifier le DOM
        // en parallèle d'un rendu plus récent (voir le compteur dans le
        // constructeur ci-dessus).
        const idRendu = ++this.idRendu;
        const numero = this.pageActuelle;

        try {
            const page = await this.pdf.getPage(numero);

            if (idRendu !== this.idRendu) {
                return;
            }

            const viewport = page.getViewport({ scale: this.echelle });

            this.conteneur.innerHTML = '';
            this.pages = [];

            const enveloppe = document.createElement('div');
            enveloppe.className = 'relative mx-auto';
            enveloppe.style.width = `${viewport.width}px`;
            enveloppe.style.height = `${viewport.height}px`;

            // Résolution du buffer de dessin MULTIPLIÉE par le ratio de
            // pixels de l'écran (devicePixelRatio) — sur un écran haute
            // densité (Retina et équivalents), un canvas dessiné à la
            // résolution CSS "logique" (1 pixel canvas = 1 pixel CSS)
            // s'affiche flou, l'OS l'étirant sur plus de pixels physiques
            // qu'il n'en contient réellement. Le contexte 2D est mis à
            // l'échelle en conséquence (ctx.scale) pour que le CODE de
            // dessin (viewport, page.render) continue de raisonner en
            // pixels CSS ; seule la résolution du buffer et la mise à
            // l'échelle du contexte changent.
            const ratio = window.devicePixelRatio || 1;

            const canvas = document.createElement('canvas');
            canvas.width = viewport.width * ratio;
            canvas.height = viewport.height * ratio;
            // Taille d'AFFICHAGE forcée en style inline, en plus des
            // attributs width/height (résolution du buffer de dessin) —
            // un <canvas> sans CSS explicite s'affiche normalement au
            // pixel près de son buffer, mais une règle globale (reset
            // Tailwind, ex. `width: 100%` sur les éléments type média)
            // peut l'étirer pour remplir son conteneur quelle que soit
            // sa résolution réelle, rendant le zoom invisible (le buffer
            // change bien de taille, l'affichage non). Style inline =
            // priorité maximale, aucune règle globale ne peut l'emporter.
            canvas.style.width = `${viewport.width}px`;
            canvas.style.height = `${viewport.height}px`;
            canvas.className = 'block bg-white shadow-sm';
            enveloppe.appendChild(canvas);

            this.conteneur.appendChild(enveloppe);

            const contexte = canvas.getContext('2d');
            contexte.scale(ratio, ratio);

            await page.render({ canvasContext: contexte, viewport }).promise;

            if (idRendu !== this.idRendu) {
                return;
            }

            if (this.avecTexte) {
                try {
                    const calqueTexte = document.createElement('div');
                    calqueTexte.className = 'textLayer';
                    enveloppe.appendChild(calqueTexte);

                    const textContent = await page.getTextContent();
                    const couche = new TextLayer({ textContentSource: textContent, container: calqueTexte, viewport });
                    await couche.render();

                    if (idRendu !== this.idRendu) {
                        return;
                    }

                    this.pages.push({ textDivs: couche.textDivs, textStrings: couche.textContentItemsStr });
                } catch (erreurTexte) {
                    // La page reste visible (déjà rendue sur le canvas
                    // ci-dessus) même si la couche de texte échoue — seule
                    // la recherche sur cette page sera indisponible.
                    console.error('[document-preview] couche de texte indisponible pour la page', numero, erreurTexte);
                }
            }
        } catch (erreurPage) {
            console.error('[document-preview] échec du rendu de la page', numero, erreurPage);

            return;
        }

        if (idRendu !== this.idRendu) {
            return;
        }

        // La boîte visible épouse exactement le contenu rendu (largeur déjà
        // fixée par CSS, hauteur ajustée ici) — jamais de bande vide autour
        // de la page, jamais de barre de défilement, à n'importe quel
        // niveau de zoom. Uniquement quand la hauteur n'est pas plafonnée
        // (voir limiterHauteur dans charger()) : la modale "Agrandir" a une
        // hauteur maximale fixée par sa mise en page (75vh), qu'on ne doit
        // jamais élargir.
        if (this.boite && ! this.limiterHauteur) {
            this.boite.style.height = `${this.conteneur.scrollHeight}px`;
        }
    }

    async pageSuivante() {
        if (this.pageActuelle >= this.numPages) {
            return;
        }

        this.pageActuelle++;

        await this.rendre();
    }

    async pagePrecedente() {
        if (this.pageActuelle <= 1) {
            return;
        }

        this.pageActuelle--;

        await this.rendre();
    }

    // Renvoie le nombre total d'occurrences trouvées (une occurrence = un
    // bloc de texte pdf.js contenant la requête, voir commentaire d'en-tête).
    rechercher(requete) {
        let total = 0;
        const requeteNormalisee = requete.trim().toLowerCase();

        this.pages.forEach((p) => {
            p.textDivs.forEach((div, index) => {
                const correspond = requeteNormalisee !== '' && p.textStrings[index].toLowerCase().includes(requeteNormalisee);

                div.classList.toggle('highlight', correspond);

                if (correspond) {
                    total++;
                }
            });
        });

        return total;
    }

    allerAuPremierResultat() {
        const premier = this.conteneur?.querySelector('.textLayer .highlight');
        premier?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Registre PAR CLÉ (pas une seule instance partagée, et pas non plus une
// simple fabrique jetable) : la miniature de la carte "Aperçu du document"
// ET la modale "Aperçu du courrier" agrandie peuvent toutes les deux être
// montées en même temps sur la page — chacune a besoin de son propre état
// (this.pages, this.conteneur), sinon la seconde à s'initialiser écraserait
// le rendu de la première. Le registre vit au niveau du MODULE (jamais sur
// un nœud DOM) : cette page contient de nombreux champs wire:model.live
// qui déclenchent chacun un remorphage Livewire, et un remorphage peut
// recréer l'élément DOM qui héberge le composant Alpine — une instance
// stockée sur ce nœud (this.$el._apercu, tenté d'abord) disparaît alors
// silencieusement avec lui, alors qu'une instance dans ce registre persiste
// quel que soit le sort du DOM. `obtenir(cle)` renvoie l'instance existante
// pour cette clé si elle existe déjà (charger() la détecte alors déjà
// chargée et redessine simplement dans le nouveau conteneur, sans refaire
// la requête réseau ni réinitialiser le zoom/la page — voir charger()).
const registre = new Map();

// Cliquer-glisser (2026-09-24, retour utilisateur : "why can i move it
// like click move without having to schrolling") — pan à la souris/au
// doigt/au stylet sur un conteneur en défilement (document zoomé
// au-delà de la boîte visible), au lieu de devoir viser la barre de
// défilement. Pointer Events (API unifiée souris/tactile/stylet) avec
// setPointerCapture : le déplacement continue de suivre le pointeur même
// s'il quitte momentanément les limites de l'élément pendant un
// glissement rapide. Idempotent (élément marqué via _panActif) : peut
// être rappelée sans risque à chaque rendre()/init() — ces conteneurs
// sont `wire:ignore`, le même nœud DOM persiste entre plusieurs
// ouvertures de la modale/plusieurs chargements du panneau, ré-attacher
// les écouteurs à chaque fois dupliquerait le comportement.
function activerDeplacement(element) {
    if (! element || element._panActif) {
        return;
    }

    element._panActif = true;
    element.style.cursor = 'grab';

    let enCours = false;
    let depart = { x: 0, y: 0, scrollLeft: 0, scrollTop: 0 };

    element.addEventListener('pointerdown', (e) => {
        // Bouton principal uniquement ; jamais sur un bouton/lien de la
        // barre d'outils, ni sur la couche de texte pdf.js (la sélection
        // de texte, pour la recherche/le copier-coller, doit rester
        // possible au clic-glisser normal).
        if (e.button !== 0 || e.target.closest('button, a, .textLayer')) {
            return;
        }

        enCours = true;
        depart = { x: e.clientX, y: e.clientY, scrollLeft: element.scrollLeft, scrollTop: element.scrollTop };
        element.style.cursor = 'grabbing';
        element.setPointerCapture(e.pointerId);
    });

    element.addEventListener('pointermove', (e) => {
        if (! enCours) {
            return;
        }

        element.scrollLeft = depart.scrollLeft - (e.clientX - depart.x);
        element.scrollTop = depart.scrollTop - (e.clientY - depart.y);
    });

    const relacher = () => {
        enCours = false;
        element.style.cursor = 'grab';
    };

    element.addEventListener('pointerup', relacher);
    element.addEventListener('pointercancel', relacher);
}

// Molette pour zoomer (2026-09-24, retour utilisateur : "why can[t] [i]
// zoom without the + sign") — Ctrl/Cmd + molette, comme la plupart des
// lecteurs PDF/éditeurs (Google Docs, Figma…) ; la molette SEULE reste
// réservée au défilement normal du document (le conteneur est en
// overflow-auto une fois zoomé). `onZoom(pas)` est fourni par l'appelant
// (le composant Alpine du Blade) — cette fonction ne fait qu'écouter
// l'évènement DOM, la logique de zoom elle-même (echelle, redessin pdf.js)
// reste dans ApercuDocument.zoomer(), déjà appelée via `this.zoomer(...)`
// côté Alpine (même principe de séparation que activerDeplacement()
// ci-dessus : le JS partagé gère l'interaction DOM brute, jamais l'état
// réactif d'Alpine). Idempotent (élément marqué via _zoomMoletteActif) —
// même raison que activerDeplacement (conteneurs wire:ignore, nœud DOM
// persistant entre plusieurs ouvertures).
function activerZoomMolette(element, onZoom) {
    if (! element || element._zoomMoletteActif) {
        return;
    }

    element._zoomMoletteActif = true;

    element.addEventListener('wheel', (e) => {
        if (! (e.ctrlKey || e.metaKey)) {
            return;
        }

        // Sans ce preventDefault, Ctrl+molette zoome aussi la PAGE ENTIÈRE
        // du navigateur (comportement natif) en plus du document — les deux
        // zooms combinés donneraient une expérience incohérente.
        e.preventDefault();
        onZoom(e.deltaY < 0 ? 10 : -10);
    }, { passive: false });
}

window.DocumentPreview = {
    obtenir(cle) {
        if (! registre.has(cle)) {
            registre.set(cle, new ApercuDocument());
        }

        return registre.get(cle);
    },
    activerDeplacement,
    activerZoomMolette,
};
