import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Temps réel par l'adresse même de la page (2026-10-27) quand VITE_REVERB_PATH
// est défini : wss://<page>/gec-reverb/app/... relayé vers Reverb par le serveur
// web — marche en local, depuis le lien de partage Herd (qui ne transmet pas le
// port 8080) et, plus tard, derrière le domaine de production. Sans chemin :
// connexion directe à VITE_REVERB_HOST:VITE_REVERB_PORT comme avant.
// Voir DECISIONS.md « Temps réel par l'adresse du site ».
const chemin = import.meta.env.VITE_REVERB_PATH;
const pageHttps = window.location.protocol === 'https:';

// 2026-11-11 (« slowliness on the guest machines … sharing with Expose ») :
// Reverb arrêté, chaque page ouverte redemandait la connexion temps réel toutes
// les 2 s (33 requêtes sur 100 dans le journal du lien, chacune bloquée 2 s) et
// encombrait le lien de partage. Après un échec (« unavailable »), la
// connexion est abandonnée puis retentée de plus en plus rarement — 10 s, 20 s,
// 40 s, puis chaque minute — et repart à zéro dès qu'elle aboutit. Reverb
// démarré, rien ne change : notifications et OCR en direct comme avant.
function ralentirLesReconnexions(echo) {
    const connexion = echo.connector?.pusher?.connection;

    if (!connexion) {
        return;
    }

    let echecs = 0;
    let minuteur = null;

    connexion.bind('state_change', ({ current }) => {
        if (current === 'connected') {
            echecs = 0;
            clearTimeout(minuteur);
        } else if (current === 'unavailable') {
            echecs++;
            echo.disconnect();
            clearTimeout(minuteur);
            minuteur = setTimeout(() => echo.connect(), Math.min(60000, 10000 * 2 ** (echecs - 1)));
        }
    });
}

window.Echo = new Echo(chemin
    ? {
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: window.location.hostname,
        wsPort: window.location.port || 80,
        wssPort: window.location.port || 443,
        wsPath: chemin,
        forceTLS: pageHttps,
        enabledTransports: ['ws', 'wss'],
    }
    : {
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

ralentirLesReconnexions(window.Echo);
