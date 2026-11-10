import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
                'resources/js/scan-watcher.js',
                'resources/js/document-preview.js',
            ],
            refresh: true,
            // 2026-11-10 — Geist, la police du site NSIA (nsiaholdingassurances.com).
            // Les options sont le 2e argument : l'ancien appel bunny('Kumbh Sans',
            // 'Sans serif', {...}) ignorait les graisses et ne chargeait que la 400.
            fonts: [
                bunny('Geist', {
                    weights: [400, 500, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
