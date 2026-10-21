// Module 1/2 — dossier surveillé (2026-09-09, voir DECISIONS.md "Import
// automatique depuis un dossier surveillé") : fonctions utilitaires pures
// exposées sur window.ScanWatcher, consommées par le bloc Alpine x-data de
// resources/views/livewire/frontend/scanPremier.blade.php — même
// découpage que resources/js/passkeys.js (JS = fonctions sans état,
// l'état/la boucle de sondage vivent dans x-data).
//
// API navigateur : File System Access (showDirectoryPicker) + IndexedDB.
// Chromium uniquement (Chrome/Edge) — pas de repli pour Firefox/Safari, la
// vue affiche un message explicatif dans ce cas plutôt que de planter.

const EXTENSIONS_ACCEPTEES = ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff'];
// Filtre côté client volontairement un peu plus large que la règle serveur
// (`mimes:pdf,jpg,jpeg,png,tiff`, qui sniffe le contenu réel du fichier,
// pas son extension) — le serveur reste seul juge final (Règle n°6),
// ce filtre n'est qu'une aide pour ignorer le bruit évident (.ds_store,
// fichiers temporaires du scanner, etc.).

const DB_NOM = 'gec-scan-watcher';
const DB_VERSION = 1;
const STORE_DOSSIER = 'dossier';
const STORE_FICHIERS = 'fichiers';
const CLE_DOSSIER_COURANT = 'courant';
const PURGE_APRES_JOURS = 90;

function estPriseEnCharge() {
    return 'showDirectoryPicker' in window && 'indexedDB' in window;
}

function ouvrirDB() {
    return new Promise((resolve, reject) => {
        const requete = window.indexedDB.open(DB_NOM, DB_VERSION);

        requete.onupgradeneeded = () => {
            const db = requete.result;

            if (!db.objectStoreNames.contains(STORE_DOSSIER)) {
                db.createObjectStore(STORE_DOSSIER, { keyPath: 'id' });
            }

            if (!db.objectStoreNames.contains(STORE_FICHIERS)) {
                db.createObjectStore(STORE_FICHIERS, { keyPath: 'cle' });
            }
        };

        requete.onsuccess = () => resolve(requete.result);
        requete.onerror = () => reject(requete.error);
    });
}

function transaction(db, store, mode) {
    return db.transaction(store, mode).objectStore(store);
}

function demande(requeteIdb) {
    return new Promise((resolve, reject) => {
        requeteIdb.onsuccess = () => resolve(requeteIdb.result);
        requeteIdb.onerror = () => reject(requeteIdb.error);
    });
}

async function dossierEnregistre(db) {
    const resultat = await demande(transaction(db, STORE_DOSSIER, 'readonly').get(CLE_DOSSIER_COURANT));

    return resultat ?? null;
}

async function enregistrerDossier(db, handle) {
    await demande(transaction(db, STORE_DOSSIER, 'readwrite').put({
        id: CLE_DOSSIER_COURANT,
        handle,
        nom: handle.name,
        enregistreLe: Date.now(),
        actif: true,
    }));
    signalerChangement();
}

async function oublierDossier(db) {
    await demande(transaction(db, STORE_DOSSIER, 'readwrite').delete(CLE_DOSSIER_COURANT));
    signalerChangement();
}

// 2026-10-08 (retour utilisateur : "reset the app so those olds ones can
// be new again") — vide UNIQUEMENT le suivi "déjà traité" (STORE_FICHIERS,
// voir fichierTraite()/marquerFichierTraite()), jamais la configuration du
// dossier (STORE_DOSSIER) elle-même : un fichier déjà présent dans le
// dossier surveillé, auparavant importé (ou rejeté), redevient éligible à
// un nouvel import au prochain cycle — utile en test/démo, sans devoir
// re-choisir le dossier ni couper la surveillance. signalerChangement()
// n'est PAS appelé ici : contrairement à oublierDossier()/definirActif(),
// vider ce store ne change ni l'état affiché (etat/actif/permission) ni
// rien que les autres onglets auraient besoin de resynchroniser.
async function viderFichiersTraites(db) {
    await demande(transaction(db, STORE_FICHIERS, 'readwrite').clear());
}

// 2026-09-24 (voir DECISIONS.md "Dossier surveillé : configuration dans
// l'administration") — activation/désactivation PERSISTANTE de l'import
// automatique sur ce poste, pilotée depuis la page d'administration.
// Un enregistrement antérieur sans champ `actif` est considéré actif
// (comportement d'avant ce changement).
function estActif(enregistrement) {
    return enregistrement !== null && enregistrement.actif !== false;
}

async function definirActif(db, actif) {
    const enregistrement = await dossierEnregistre(db);

    if (enregistrement === null) {
        return;
    }

    await demande(transaction(db, STORE_DOSSIER, 'readwrite').put({ ...enregistrement, actif }));
    signalerChangement();
}

// Prévient les autres onglets du même navigateur (page Numérisation /
// Nouveau courrier déjà ouvertes) qu'une configuration a changé : ils
// démarrent ou arrêtent l'import sans rechargement.
const CANAL = 'BroadcastChannel' in window ? new BroadcastChannel('gec-scan-watcher') : null;

function signalerChangement() {
    CANAL?.postMessage('configuration');
}

// Renvoie la fonction de désabonnement (appelée par destroy() du composant).
function surChangement(rappel) {
    CANAL?.addEventListener('message', rappel);

    return () => CANAL?.removeEventListener('message', rappel);
}

function cleFichier({ name, size, lastModified }) {
    return `${name}|${size}|${lastModified}`;
}

async function fichierTraite(db, cle) {
    const resultat = await demande(transaction(db, STORE_FICHIERS, 'readonly').get(cle));

    return resultat ?? null;
}

async function marquerFichierTraite(db, entree) {
    await demande(transaction(db, STORE_FICHIERS, 'readwrite').put(entree));
}

// Housekeeping — appelé une fois à l'ouverture : évite une croissance
// illimitée du store 'fichiers' sur un poste utilisé pendant des mois.
async function purgerFichiersAnciens(db) {
    const store = transaction(db, STORE_FICHIERS, 'readwrite');
    const seuil = Date.now() - PURGE_APRES_JOURS * 24 * 60 * 60 * 1000;
    const tous = await demande(store.getAll());

    for (const entree of tous) {
        if (entree.traiteLe < seuil) {
            store.delete(entree.cle);
        }
    }
}

async function listerEntrees(handle) {
    const entrees = [];

    for await (const [nom, h] of handle.entries()) {
        if (h.kind !== 'file') {
            continue;
        }

        const extension = nom.split('.').pop()?.toLowerCase();

        if (!EXTENSIONS_ACCEPTEES.includes(extension)) {
            continue;
        }

        entrees.push({ nom, handle: h });
    }

    return entrees;
}

// $wire.upload() appelé directement depuis ce composant Alpine ne produit
// jamais de requête réseau (constaté en conditions réelles, cause identique
// à celle documentée sur creerBrouillon() ci-dessous — voir DECISIONS.md
// 2026-09-09). Le scan manuel (input réel + clic) fonctionne, lui,
// parfaitement, via le même mécanisme Livewire déclenché par un vrai
// évènement natif. On reproduit ce chemin : un <input type="file"> caché
// (voir scanPremier.blade.php, wire:model="document"), rempli via
// DataTransfer et un vrai évènement "change".
function televerser(inputCache, file) {
    return new Promise((resolve, reject) => {
        let etabli = false;

        const nettoyer = () => {
            inputCache.removeEventListener('livewire-upload-finish', surFin);
            inputCache.removeEventListener('livewire-upload-error', surErreur);
        };

        const delai = setTimeout(() => {
            if (etabli) {
                return;
            }

            etabli = true;
            nettoyer();
            console.warn('[scan-watcher] televersement de', file.name, ': aucune réponse après 20s, abandon.');
            reject({ type: 'televersement_delai' });
        }, 20000);

        function surFin() {
            if (etabli) {
                return;
            }

            etabli = true;
            clearTimeout(delai);
            nettoyer();
            resolve();
        }

        function surErreur() {
            if (etabli) {
                return;
            }

            etabli = true;
            clearTimeout(delai);
            nettoyer();
            reject({ type: 'televersement' }); // Livewire ne transmet aucun détail d'erreur exploitable ici
        }

        inputCache.addEventListener('livewire-upload-finish', surFin);
        inputCache.addEventListener('livewire-upload-error', surErreur);

        const transfert = new DataTransfer();
        transfert.items.add(file);
        inputCache.files = transfert.files;
        inputCache.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

// Root cause trouvée le 2026-09-09 (voir DECISIONS.md) : this.$wire (magic
// Alpine, vendor/livewire/livewire/dist/livewire.esm.js:13592) résout le
// composant via findComponentByEl(el) à CHAQUE accès, avec un catch
// silencieux qui renvoie un no-op () => {} si el n'est plus rattaché à un
// composant suivi (ex. après un morph Livewire qui remplace le nœud DOM
// d'origine) — typeof "function" mais renvoie undefined à l'appel. C'est
// aussi ce qui a très probablement bloqué $wire.upload() ci-dessus.
// window.Livewire.find(id) interroge un registre global par id STABLE,
// sans dépendre d'un élément DOM mis en cache : cet id est capturé une
// seule fois dans init() (là où $wire est confirmé fiable) et réutilisé
// pour toute la durée de la surveillance — ne jamais garder this.$wire
// au-delà de l'appel immédiat qui l'a résolu depuis ce genre de contexte
// (boucle setInterval, callback asynchrone tardif).
function creerBrouillon(wireId) {
    return new Promise((resolve, reject) => {
        let etabli = false;

        const delai = setTimeout(() => {
            if (etabli) {
                return;
            }

            etabli = true;
            reject({ type: 'numeriser_delai' });
        }, 20000);

        const wire = window.Livewire.find(wireId);

        if (!wire) {
            etabli = true;
            clearTimeout(delai);
            console.error('[scan-watcher] Livewire.find(wireId) : composant introuvable pour', wireId);
            reject({ type: 'numeriser', status: null, errors: null });
            return;
        }

        wire.numeriserAutomatique()
            .then((resultat) => {
                if (etabli) {
                    return;
                }

                etabli = true;
                clearTimeout(delai);
                resolve(resultat);
            })
            .catch((erreur) => {
                if (etabli) {
                    return;
                }

                etabli = true;
                clearTimeout(delai);
                reject({ type: 'numeriser', status: erreur?.status ?? null, errors: erreur?.errors ?? null });
            });
    });
}

window.ScanWatcher = {
    estPriseEnCharge,
    ouvrirDB,
    dossierEnregistre,
    enregistrerDossier,
    oublierDossier,
    viderFichiersTraites,
    estActif,
    definirActif,
    surChangement,
    purgerFichiersAnciens,
    cleFichier,
    fichierTraite,
    marquerFichierTraite,
    listerEntrees,
    televerser,
    creerBrouillon,
};

// Composant Alpine enregistré ici plutôt qu'inline dans le Blade
// (x-data="{ ... }") : les messages traduits (__()) contiennent des
// apostrophes, et @js() les encode en JSON avec des guillemets doubles —
// incompatible avec un attribut HTML x-data lui-même délimité par des
// guillemets doubles. Les traductions arrivent donc en attributs data-*
// (échappement HTML normal, aucun conflit), lus une fois dans init().
document.addEventListener('alpine:init', () => {
    window.Alpine.data('surveillanceDossier', () => ({
        // Deux modes (2026-09-24, voir DECISIONS.md "Dossier surveillé :
        // configuration dans l'administration"), lus sur data-mode :
        //   - 'config' (page Administration › Dossier surveillé) : choisir le
        //     dossier, activer/désactiver, autoriser l'accès — n'importe
        //     jamais rien lui-même ;
        //   - 'execution' (Numérisation, Nouveau courrier) : importe
        //     automatiquement, sans aucun bouton de configuration.
        // États : chargement | non_pris_en_charge
        //   config    : a_choisir | configure
        //   execution : non_configure | a_reprendre | en_surveillance | en_pause_erreur
        mode: 'execution',
        etat: 'chargement',
        actif: false,
        permission: null,
        db: null,
        handle: null,
        nomDossier: '',
        totalImportes: 0,
        totalRejetes: 0,
        // Objet {nomFichier: {statut, raison}} — reflète l'état COURANT de
        // chaque fichier détecté dans le dossier, affiché dès la détection
        // (pas seulement une fois importé) : demande explicite de
        // l'utilisateur (2026-09-09), voir DECISIONS.md.
        journal: {},
        messageErreur: '',
        // 2026-10-08 — confirmation après reinitialiserFichiersTraites(),
        // distincte de messageErreur (pas une erreur).
        messageConfirmation: '',
        messages: {},
        intervalId: null,
        desabonner: null,
        enCoursDeTraitement: false,
        tailleVues: {},
        // Id Livewire stable du composant (this.$wire.$id), capturé une
        // seule fois ici pendant que $wire est fiable — voir creerBrouillon()
        // dans scan-watcher.js pour pourquoi on ne réaccède plus jamais à
        // this.$wire directement après ce point.
        wireId: null,

        async init() {
            this.messages = { ...this.$el.dataset };
            this.mode = this.$el.dataset.mode === 'config' ? 'config' : 'execution';

            if (this.mode === 'execution') {
                this.wireId = this.$wire.$id;
            }

            if (!window.ScanWatcher.estPriseEnCharge()) {
                this.etat = 'non_pris_en_charge';
                return;
            }

            this.db = await window.ScanWatcher.ouvrirDB();
            await window.ScanWatcher.purgerFichiersAnciens(this.db);

            await this.charger();

            // Configuration changée dans un autre onglet (page
            // d'administration) : appliquée ici sans rechargement.
            this.desabonner = window.ScanWatcher.surChangement(() => this.charger());
        },

        // Navigation wire:navigate : le composant est détruit mais un
        // setInterval continuerait de sonder le dossier en arrière-plan.
        destroy() {
            this.arreterBoucle();
            this.desabonner?.();
        },

        // (Re)lit la configuration de CE poste et en déduit l'état affiché —
        // appelé au chargement et à chaque changement de configuration.
        async charger() {
            this.messageConfirmation = '';

            const enregistre = await window.ScanWatcher.dossierEnregistre(this.db);

            this.handle = enregistre?.handle ?? null;
            this.nomDossier = enregistre?.nom ?? '';
            this.actif = window.ScanWatcher.estActif(enregistre);

            if (this.mode === 'config') {
                this.permission = this.handle ? await this.handle.queryPermission({ mode: 'read' }) : null;
                this.etat = this.handle ? 'configure' : 'a_choisir';

                return;
            }

            this.arreterBoucle();

            if (!this.handle || !this.actif) {
                this.etat = 'non_configure';

                return;
            }

            // Reprise automatique sans clic (2026-09-09) : queryPermission()
            // ne fait que LIRE l'état de la permission, sans geste
            // utilisateur. Sinon (jamais accordé sur ce profil, ou révoqué),
            // 'a_reprendre' : un vrai clic est incontournable
            // (requestPermission() l'exige).
            const permission = await this.handle.queryPermission({ mode: 'read' });

            if (permission === 'granted') {
                this.demarrer();
            } else {
                this.etat = 'a_reprendre';
            }
        },

        // ===== Mode config (Administration › Dossier surveillé) =====

        async choisirDossier() {
            try {
                const handle = await window.showDirectoryPicker({ mode: 'read' });

                await window.ScanWatcher.enregistrerDossier(this.db, handle);
                await this.charger();
            } catch (erreur) {
                // AbortError : l'utilisateur a fermé le sélecteur sans choisir — pas une vraie erreur.
                if (erreur.name !== 'AbortError') {
                    this.messageErreur = this.messages.msgErreurAcces;
                }
            }
        },

        async activer() {
            this.messageErreur = '';

            if (!(await this.autoriserAcces())) {
                return;
            }

            await window.ScanWatcher.definirActif(this.db, true);
            await this.charger();
        },

        async desactiver() {
            await window.ScanWatcher.definirActif(this.db, false);
            await this.charger();
        },

        async oublier() {
            await window.ScanWatcher.oublierDossier(this.db);
            await this.charger();
        },

        // 2026-10-08 — voir viderFichiersTraites() ci-dessus. messageConfirmation
        // distinct de messageErreur (pas une erreur) ; effacé au prochain
        // changement d'état réel (charger()) pour ne jamais rester affiché
        // après une action qui le rend obsolète (ex. changer de dossier).
        async reinitialiserFichiersTraites() {
            await window.ScanWatcher.viderFichiersTraites(this.db);
            this.messageConfirmation = this.messages.msgFichiersReinitialises;
        },

        // requestPermission() exige un vrai geste utilisateur : toujours
        // appelée depuis un clic.
        async autoriserAcces() {
            let permission = await this.handle.queryPermission({ mode: 'read' });

            if (permission !== 'granted') {
                permission = await this.handle.requestPermission({ mode: 'read' });
            }

            this.permission = permission;

            if (permission !== 'granted') {
                this.messageErreur = this.messages.msgAccesRefuse;

                return false;
            }

            return true;
        },

        // ===== Mode execution (Numérisation, Nouveau courrier) =====

        // Repli quand charger() n'a pas pu reprendre silencieusement : la
        // réceptionniste réautorise l'accès d'un clic, sans rien reconfigurer.
        async reprendre() {
            if (!(await this.autoriserAcces())) {
                this.etat = 'en_pause_erreur';

                return;
            }

            this.demarrer();
        },

        demarrer() {
            this.arreterBoucle();
            this.etat = 'en_surveillance';
            this.cycleDeSurveillance();
            this.intervalId = setInterval(() => this.cycleDeSurveillance(), 4000);
        },

        arreterBoucle() {
            clearInterval(this.intervalId);
            this.intervalId = null;
        },

        majJournal(nom, statut, raison = null) {
            this.journal = { ...this.journal, [nom]: { statut, raison } };
        },

        async cycleDeSurveillance() {
            if (this.enCoursDeTraitement) {
                return;
            }

            this.enCoursDeTraitement = true;

            try {
                const entrees = await window.ScanWatcher.listerEntrees(this.handle);
                const nomsVus = new Set(entrees.map((e) => e.nom));

                for (const nom of Object.keys(this.tailleVues)) {
                    if (!nomsVus.has(nom)) {
                        delete this.tailleVues[nom];
                    }
                }

                // Affiche immédiatement tout fichier détecté dans le
                // dossier, avant même la vérification de stabilité —
                // "chercher et afficher" plutôt que de rester muet jusqu'à
                // ce qu'un import réussisse.
                for (const entree of entrees) {
                    if (!(entree.nom in this.journal)) {
                        this.majJournal(entree.nom, 'en_attente');
                    }
                }

                // Séquentiel, un fichier à la fois : document est une
                // propriété unique côté serveur, jamais un tableau.
                for (const entree of entrees) {
                    await this.traiterEntree(entree);
                }
            } catch (erreur) {
                // Sans ce catch, une exception ici serait une "unhandled
                // promise rejection" silencieuse (déclenché par setInterval,
                // pas par une action utilisateur directe).
                console.error('[scan-watcher] erreur dans le cycle de surveillance :', erreur);
            } finally {
                this.enCoursDeTraitement = false;
            }
        },

        // Un fichier n'est éligible que si sa taille est identique sur 2
        // cycles consécutifs (évite d'uploader un PDF que le scanner est
        // encore en train d'écrire).
        async traiterEntree(entree) {
            const fichier = await entree.handle.getFile();
            const cle = window.ScanWatcher.cleFichier(fichier);

            // Déjà traité lors d'un chargement de page précédent (le journal
            // ci-dessus, lui, repart à vide à chaque rechargement — sans ce
            // contrôle un fichier resterait affiché "en attente" pour
            // toujours alors qu'il a déjà été importé/rejeté par le passé).
            const connu = await window.ScanWatcher.fichierTraite(this.db, cle);

            if (connu) {
                this.majJournal(fichier.name, connu.statut, connu.raison);
                delete this.tailleVues[fichier.name];

                return;
            }

            const vu = this.tailleVues[fichier.name];

            if (!vu || vu.taille !== fichier.size) {
                this.tailleVues[fichier.name] = { taille: fichier.size, vues: 1 };
                this.majJournal(fichier.name, 'en_attente');

                return;
            }

            this.tailleVues[fichier.name].vues++;

            if (this.tailleVues[fichier.name].vues < 2) {
                this.majJournal(fichier.name, 'en_attente');

                return;
            }

            // id plutôt que x-ref : $refs restait undefined en conditions
            // réelles (2026-09-09, cause probablement identique à celle de
            // this.$wire documentée sur creerBrouillon() — un DOM morph qui
            // invalide une référence mise en cache), un id est sans
            // ambiguïté et fonctionne de façon fiable.
            const inputCache = document.getElementById('scan-watcher-entree-cachee');

            // Verrou inter-onglets (2026-09-10, revue de code) : sans lui,
            // deux onglets surveillant le même dossier (ex. ScanPremier +
            // RegistrationForm ouverts en même temps) peuvent chacun
            // constater indépendamment "pas encore traité" ci-dessus avant
            // que l'un des deux n'écrive sa marque via
            // marquerFichierTraite() — uploadant alors le même scan deux
            // fois (deux CourrierBrouillon, deux OCR pour un seul document).
            // Web Locks API : verrou inter-onglets natif du navigateur,
            // disponible sur Chrome/Edge depuis bien plus longtemps que
            // l'API File System Access déjà exigée par cette fonctionnalité
            // — aucune détection de compatibilité supplémentaire nécessaire.
            await navigator.locks.request(`gec-scan-watcher:${cle}`, async () => {
                // Second contrôle, À L'INTÉRIEUR du verrou cette fois : un
                // autre onglet a pu traiter ce même fichier entre le premier
                // contrôle ci-dessus et l'obtention du verrou.
                if (await window.ScanWatcher.fichierTraite(this.db, cle)) {
                    return;
                }

                try {
                    await window.ScanWatcher.televerser(inputCache, fichier);
                    const resultat = await window.ScanWatcher.creerBrouillon(this.wireId);

                    await window.ScanWatcher.marquerFichierTraite(this.db, {
                        cle, nom: fichier.name, statut: 'importe',
                        brouillonId: resultat.brouillonId, raison: null, traiteLe: Date.now(),
                    });

                    this.totalImportes++;
                    this.majJournal(fichier.name, 'importe');
                    delete this.tailleVues[fichier.name];
                } catch (erreur) {
                    await this.gererEchec(fichier, cle, erreur);
                }
            });
        },

        async gererEchec(fichier, cle, erreur) {
            if (erreur.status === 403) {
                // Droits perdus : probablement tout le dossier concerné, pas
                // seulement ce fichier — pause plutôt que de marteler le serveur.
                this.etat = 'en_pause_erreur';
                this.messageErreur = this.messages.msgDroitsPerdus;
                this.arreterBoucle();

                return;
            }

            if (erreur.status === 422) {
                // Fichier réellement invalide (résolution trop faible, etc.)
                // — marqué "traité" pour ne JAMAIS le retenter en boucle.
                const raison = Object.values(erreur.errors?.document ?? {}).flat().join(' ');

                await window.ScanWatcher.marquerFichierTraite(this.db, {
                    cle, nom: fichier.name, statut: 'rejete',
                    brouillonId: null, raison, traiteLe: Date.now(),
                });

                this.totalRejetes++;
                this.majJournal(fichier.name, 'rejete', raison);
                delete this.tailleVues[fichier.name];

                return;
            }

            // Panne réseau ou échec opaque (upload, ou composant Livewire
            // introuvable) : PAS marqué "traité" en base — retenté au cycle
            // suivant. Logué explicitement (Règle n°1, esprit "échec
            // exploitable, pas de catch silencieux") — une vraie exception
            // JS inattendue ici serait sinon classée à tort "temporaire"
            // sans jamais montrer son vrai message.
            console.error('[scan-watcher]', fichier.name, ': échec, nouvel essai au prochain cycle —', erreur);
            this.majJournal(fichier.name, 'erreur_temporaire', this.messages.msgErreurTemporaire);
        },
    }));
});
