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
