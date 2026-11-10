// Visionneuse de documents (brouillon, courrier, pièce jointe) — aperçu
// SÉCURISÉ depuis le 2026-11-09 (demande « on ne doit pas copier ou
// télécharger un fichier », voir DECISIONS.md « Fichiers : chiffrement,
// aperçu sécurisé, aucun téléchargement »). Le navigateur ne reçoit plus
// jamais le PDF original (l'ancienne version le chargeait entier dans
// pdf.js, donc enregistrable) : seulement des images de pages filigranées
// au nom de la personne par le serveur (App\Services\ApercuSecurise). Ni
// texte à sélectionner, ni clic droit, ni glisser-déposer, ni impression
// (règle @media print de app.css sur [data-document-protege]).
//
// Même contrat qu'avant pour les pages qui l'utilisent (show, edit, liste,
// enregistrement) : DocumentPreview.obtenir(cle) puis charger(url, boite,
// conteneur, avecTexte, limiterHauteur), zoomer(), pageSuivante(),
// pagePrecedente() ; `url` désigne désormais la route apercus.manifeste.
//
// IMPORTANT côté appelant (Blade/Alpine) : tout le code qui utilise ce
// module doit vivre dans une MÉTHODE d'un objet x-data, jamais inliné
// directement comme valeur d'un attribut x-init/x-on — l'évaluateur
// d'expressions d'Alpine n'accepte pas un bloc `try { ... } catch { ... }`
// comme expression brute (« Unexpected token 'try' », vu en conditions
// réelles). Toujours : `x-data="{ async init() { try {...} catch {...} } }"`
// puis `x-init="init()"`.

class ApercuDocument {
    constructor() {
        this.url = null;
        this.manifeste = null; // { pages: [{ url, largeur, hauteur }] }
        this.boite = null;
        this.conteneur = null;
        this.limiterHauteur = false;
        this.echelle = 1;
        // Échelle correspondant à « 100 % » : la page entière tient dans la
        // boîte visible (demande explicite de l'utilisateur : « i don't want
        // scrolling »), calculée sur la première page.
        this.echelleBase = 1;
        // Une page à la fois, avec navigation (demande explicite).
        this.pageActuelle = 1;
        this.numPages = 1;
        // Images déjà reçues, par numéro de page : un zoom ne redemande pas
        // la page au serveur (elle n'est jamais mise en cache par le
        // navigateur, Cache-Control: no-store).
        this.images = new Map();
        // Compteur anti-course (bug réel : deux rendus qui se chevauchent
        // empilaient deux fois la même page).
        this.idRendu = 0;
        this.attente = null;
    }

    // `boite` : élément dont on lit clientWidth (et clientHeight si
    // `limiterHauteur`) pour l'échelle « 100 % » ; `conteneur` : où la page
    // est dessinée. `limiterHauteur` : false dans les panneaux latéraux (la
    // boîte épouse la hauteur de la page), true dans les modales
    // « Agrandir » (hauteur plafonnée, la page tient dans les deux sens).
    // `avecTexte` n'a plus d'effet (aucune couche de texte : rien à copier),
    // gardé pour ne pas toucher les appelants.
    async charger(url, boite, conteneur, avecTexte = true, limiterHauteur = false) {
        if (! boite || ! conteneur) {
            throw new Error('Conteneur d’aperçu introuvable dans la page.');
        }

        this.url = url;
        this.boite = boite;
        this.conteneur = conteneur;
        this.limiterHauteur = limiterHauteur;
        proteger(boite);

        // Instance déjà chargée (Livewire a recréé l'élément hôte) : on
        // redessine à la page et au zoom où en était l'utilisateur.
        if (this.manifeste) {
            await this.rendre();
            this.signaler();

            return;
        }

        const reponse = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });

        if (reponse.status === 403) {
            throw new Error('Accès refusé à ce document.');
        }

        if (reponse.status === 404) {
            throw new Error('Document introuvable sur le stockage — signalez-le à un administrateur.');
        }

        if (! reponse.ok) {
            throw new Error(`Réponse HTTP ${reponse.status} en récupérant l’aperçu.`);
        }

        const donnees = await reponse.json();

        if (! donnees.pret) {
            this.attendre(donnees);

            return;
        }

        if (! donnees.pages.length) {
            this.afficherMessage(donnees.message);

            return;
        }

        this.manifeste = donnees;
        this.numPages = donnees.pages.length;
        this.pageActuelle = 1;
        this.images.clear();

        // Marge de 2 à 4 % : sans elle, un écart d'arrondi suffit à faire
        // apparaître une barre de défilement.
        const premiere = donnees.pages[0];
        this.echelleBase = limiterHauteur
            ? Math.min(boite.clientWidth / premiere.largeur, boite.clientHeight / premiere.hauteur) * 0.96
            : (boite.clientWidth / premiere.largeur) * 0.98;
        this.echelle = this.echelleBase;

        await this.rendre();
        this.signaler();
    }

    // `pourcentage` : 100 = échelle de base, 200 = deux fois plus grand.
    async zoomer(pourcentage) {
        this.echelle = this.echelleBase * (pourcentage / 100);

        await this.rendre();
    }

    async rendre() {
        if (! this.manifeste || ! this.conteneur) {
            return;
        }

        const idRendu = ++this.idRendu;
        const numero = this.pageActuelle;
        const page = this.manifeste.pages[numero - 1];
        let image = this.images.get(numero);

        if (! image) {
            image = new Image();
            image.alt = '';
            image.draggable = false;
            image.className = 'block select-none bg-white shadow-sm pointer-events-none';
            image.src = page.url;

            try {
                await image.decode();
            } catch (erreur) {
                console.error('[document-preview] page indisponible', numero, erreur);

                if (idRendu === this.idRendu) {
                    this.afficherMessage('Page indisponible — réessayez dans un instant.');
                }

                return;
            }

            this.images.set(numero, image);
        }

        if (idRendu !== this.idRendu) {
            return;
        }

        const largeur = Math.round(page.largeur * this.echelle);
        const hauteur = Math.round(page.hauteur * this.echelle);

        // Taille d'affichage en style inline : priorité sur toute règle
        // globale (reset Tailwind) qui étirerait l'image et rendrait le zoom
        // invisible.
        image.style.width = `${largeur}px`;
        image.style.height = `${hauteur}px`;

        const enveloppe = document.createElement('div');
        enveloppe.className = 'relative mx-auto';
        enveloppe.style.width = `${largeur}px`;
        enveloppe.style.height = `${hauteur}px`;
        enveloppe.appendChild(image);

        this.conteneur.replaceChildren(enveloppe);
        this.ajusterHauteur();
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

    // Pages pas encore prêtes (document tout juste déposé, ou ancien
    // document jamais affiché) : le serveur les prépare en file d'attente et
    // annonce la fin par l'événement temps réel apercu.pret — la visionneuse
    // se recharge alors d'elle-même, sans interroger le serveur en boucle.
    // Bouton « Réessayer » si le temps réel est indisponible.
    attendre(donnees) {
        this.afficherMessage(donnees.message, true);

        if (this.attente || ! window.Echo || ! donnees.canal) {
            return;
        }

        const canal = window.Echo.private(donnees.canal);
        const surPret = () => {
            canal.stopListening(donnees.evenement, surPret);
            this.attente = null;
            this.recharger();
        };

        canal.listen(donnees.evenement, surPret);
        this.attente = { canal, evenement: donnees.evenement, surPret };
    }

    async recharger() {
        this.manifeste = null;

        try {
            await this.charger(this.url, this.boite, this.conteneur, true, this.limiterHauteur);
        } catch (erreur) {
            this.afficherMessage(erreur.message ?? String(erreur));
        }
    }

    afficherMessage(texte, avecReessai = false) {
        if (! this.conteneur) {
            return;
        }

        const bloc = document.createElement('div');
        bloc.className = 'flex flex-col items-center gap-2 p-6 text-center text-sm text-zinc-600';
        bloc.textContent = texte;

        if (avecReessai) {
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = 'text-sm text-blue-700 underline';
            bouton.textContent = 'Réessayer';
            bouton.addEventListener('click', () => this.recharger());
            bloc.appendChild(bouton);
        }

        this.conteneur.replaceChildren(bloc);
        this.ajusterHauteur();
    }

    // La boîte épouse exactement le contenu (jamais de bande vide ni de
    // défilement), sauf dans une modale à hauteur plafonnée.
    ajusterHauteur() {
        if (this.boite && ! this.limiterHauteur) {
            this.boite.style.height = `${this.conteneur.scrollHeight}px`;
        }
    }

    // Prévient le x-data Alpine (écouteur dans visionneuse.blade.php) quand
    // le document arrive après coup : nombre de pages, page courante.
    signaler() {
        this.boite?.dispatchEvent(new CustomEvent('apercu-charge', {
            bubbles: true,
            detail: { numPages: this.numPages, pageActuelle: this.pageActuelle },
        }));
    }
}

// Clic droit, glisser-déposer et copie désactivés sur la zone du document.
// Idempotent (conteneurs wire:ignore, le même nœud persiste).
function proteger(element) {
    if (! element || element._protege) {
        return;
    }

    element._protege = true;

    for (const evenement of ['contextmenu', 'dragstart', 'copy', 'cut']) {
        element.addEventListener(evenement, (e) => e.preventDefault());
    }
}

// Registre PAR CLÉ : la miniature et la modale « Agrandir » d'un même
// document vivent en même temps sur la page, chacune avec son état. Au
// niveau du module (jamais sur un nœud DOM, qu'un remorphage Livewire peut
// recréer) : `obtenir(cle)` renvoie l'instance existante, et charger() la
// redessine simplement dans le nouveau conteneur.
const registre = new Map();

// Cliquer-glisser (2026-09-24, retour utilisateur : « why can i move it like
// click move without having to schrolling ») — Pointer Events avec
// setPointerCapture. Idempotent (élément marqué via _panActif).
function activerDeplacement(element) {
    if (! element || element._panActif) {
        return;
    }

    element._panActif = true;
    element.style.cursor = 'grab';

    let enCours = false;
    let depart = { x: 0, y: 0, scrollLeft: 0, scrollTop: 0 };

    element.addEventListener('pointerdown', (e) => {
        // Bouton principal uniquement ; jamais sur un bouton/lien.
        if (e.button !== 0 || e.target.closest('button, a')) {
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

// Ctrl/Cmd + molette pour zoomer (2026-09-24, retour utilisateur : « why
// can[t] [i] zoom without the + sign ») ; la molette seule fait défiler.
// `onZoom(pas)` est fourni par le x-data appelant. Idempotent.
function activerZoomMolette(element, onZoom) {
    if (! element || element._zoomMoletteActif) {
        return;
    }

    element._zoomMoletteActif = true;

    element.addEventListener('wheel', (e) => {
        if (! (e.ctrlKey || e.metaKey)) {
            return;
        }

        // Sinon Ctrl+molette zoome aussi la page entière du navigateur.
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
