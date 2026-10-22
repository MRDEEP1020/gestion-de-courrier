/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

// Module 5 — chronomètre de traitement (2026-09-24, voir
// resources/views/components/chronometre.blade.php). Compte à rebours
// purement client, recalé sur l'heure du serveur (data-maintenant) pour ne
// pas dépendre de l'horloge du poste. Libellés traduits lus en data-* (même
// raison que scan-watcher.js : apostrophes incompatibles avec x-data="...").
document.addEventListener('alpine:init', () => {
    window.Alpine.data('chronometre', () => ({
        etat: 'ok', // ok | risque | retard | fige | fige_retard
        affichage: '',
        libelle: '',
        progression: 0,
        intervalle: null,

        // 2026-10-02 (retour utilisateur réel : "when i refrech it shows
        // good data for 2sec then goes back to the wrong data time" — sur
        // une page listant PLUSIEURS chronomètres à la fois, un ou
        // plusieurs finissaient par afficher la valeur d'UN AUTRE courrier
        // après le premier tic). Donnée serveur vérifiée correcte et
        // distincte par ligne (voir DECISIONS.md) : le bug était donc
        // forcément côté JS. debut/fin/arret/textes n'étaient lus qu'UNE
        // SEULE FOIS dans init() puis réutilisés tels quels par CHAQUE tic
        // de calculer() ensuite — si quoi que ce soit course-conditionne
        // l'assignation de ces propriétés d'instance entre deux composants
        // Alpine distincts (plusieurs chronomètres s'initialisant dans la
        // même frame, state partagé via une closure mal isolée...), le
        // second tic pouvait lire les valeurs d'un AUTRE composant.
        // Corrigé en ne gardant plus AUCUN état caché pour debut/fin/arret :
        // calculer() relit `this.$el.dataset` en entier à CHAQUE appel, pas
        // seulement au premier — la source de vérité reste alors toujours
        // les attributs data-* réels de CET élément précis, à chaque tic,
        // sans aucune possibilité de contamination entre instances.
        init() {
            this.textes = this.$el.dataset;
            this.decalage = Number(this.$el.dataset.maintenant) - Date.now();

            this.calculer();

            if (! Number(this.$el.dataset.arret)) {
                this.intervalle = setInterval(() => this.calculer(), 1000);
            }
        },

        destroy() {
            clearInterval(this.intervalle);
        },

        calculer() {
            const d = this.$el.dataset;
            const debut = Number(d.debut) || null;
            const fin = Number(d.fin);
            const arret = Number(d.arret) || null;
            const maintenant = arret ?? (Date.now() + this.decalage);
            const total = debut ? Math.max(fin - debut, 1) : null;

            if (arret) {
                const enRetard = arret > fin;
                this.etat = enRetard ? 'fige_retard' : 'fige';
                this.libelle = enRetard ? this.textes.labelTraiteRetard : this.textes.labelTraite;
                // 2026-10-12, retour utilisateur direct (capture d'écran
                // annotée, le badge affichait "2j 21:40:00" alors que
                // l'échéance n'était dépassée que d'environ 21h40) :
                // affichait jusqu'ici la durée TOTALE de traitement
                // (arrêt - début) même une fois en retard, ce qui se lisait
                // comme un dépassement alors que ce n'en était pas un. En
                // retard, affiche désormais le VRAI dépassement
                // (arrêt - échéance) ; la durée totale de traitement reste
                // inchangée pour une clôture dans les temps (fige).
                this.affichage = this.formater(enRetard ? arret - fin : (debut ? arret - debut : 0));
                this.progression = 100;

                return;
            }

            const restant = fin - maintenant;

            if (restant < 0) {
                this.etat = 'retard';
                this.libelle = this.textes.labelRetard;
                this.affichage = this.formater(-restant);
                this.progression = 100;

                return;
            }

            // "À risque" : dernières 24 h, ou dernier quart du délai s'il est court.
            this.etat = restant <= Math.min(24 * 3600 * 1000, (total ?? Infinity) / 4) ? 'risque' : 'ok';
            this.libelle = this.textes.labelRestant;
            this.affichage = this.formater(restant);
            this.progression = total ? Math.min(100, Math.max(0, ((total - restant) / total) * 100)) : 0;
        },

        // "2 j 04:12:33" — jours seulement s'il y en a.
        formater(ms) {
            const s = Math.floor(ms / 1000);
            const jours = Math.floor(s / 86400);
            const hms = [Math.floor((s % 86400) / 3600), Math.floor((s % 3600) / 60), s % 60]
                .map((n) => String(n).padStart(2, '0'))
                .join(':');

            return jours > 0 ? `${jours} ${this.textes.jour} ${hms}` : hms;
        },
    }));

    // Squelette de chargement — durée minimale d'affichage (2026-09-24,
    // retour utilisateur : "am not seeing animation when i refresh or
    // switch pages"). Une requête Livewire locale peut se terminer en
    // quelques millisecondes — trop vite pour qu'un humain perçoive le
    // balayage du shimmer (1,5s de cycle, voir app.css) même si le
    // squelette s'affiche techniquement. Ce composant observe une
    // SENTINELLE invisible (`wire:loading.class.remove="hidden"` — même
    // mécanisme par classe que le correctif du bug .table-row-group,
    // jamais une valeur de display, pour rester uniforme et éviter la
    // liste fixe de modificateurs que Livewire reconnaît) et pilote un
    // état `visible` PARTAGÉ avec le vrai squelette ET le contenu réel
    // (x-show="visible" / x-show="!visible"), en retardant seulement la
    // DISPARITION du squelette d'au moins dureeMinimaleMs — jamais son
    // apparition (donc aucun retard perçu pour une requête réellement
    // longue).
    window.Alpine.data('squeletteMinimum', (dureeMinimaleMs = 400) => ({
        visible: false,
        _debutVisible: 0,
        _minuteur: null,
        _observateur: null,

        init() {
            const sentinelle = this.$refs.sentinelle;

            this._observateur = new MutationObserver(() => this.synchroniser(sentinelle));
            this._observateur.observe(sentinelle, { attributes: true, attributeFilter: ['class'] });

            this.synchroniser(sentinelle);
        },

        destroy() {
            this._observateur?.disconnect();
            clearTimeout(this._minuteur);
        },

        synchroniser(sentinelle) {
            const enCours = !sentinelle.classList.contains('hidden');

            clearTimeout(this._minuteur);

            if (enCours) {
                if (!this.visible) {
                    this.visible = true;
                    this._debutVisible = performance.now();
                }

                return;
            }

            if (!this.visible) {
                return;
            }

            const ecoule = performance.now() - this._debutVisible;
            const restant = Math.max(0, dureeMinimaleMs - ecoule);

            this._minuteur = setTimeout(() => { this.visible = false; }, restant);
        },
    }));
});
