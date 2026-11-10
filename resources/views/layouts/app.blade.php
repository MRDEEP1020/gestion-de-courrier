{{-- Fond posé ICI directement, pas seulement sur <body> (2026-09-18,
     demande explicite de l'utilisateur : le fond de la zone de contenu
     principal ne correspondait pas à celui de l'image) — la zone
     [grid-area:main] de <flux:main> n'a par défaut AUCUN fond propre
     (transparente, voir vendor/livewire/flux/stubs/.../flux/main.blade.php),
     dépendant donc de ce qui est visuellement derrière elle plutôt que
     d'afficher la couleur de façon garantie. Posé explicitement sur cette
     boîte précise pour ne plus dépendre de cette transparence. Couleur
     exacte #F5F8FC (2026-09-18, demande explicite de l'utilisateur) — déjà
     le token --color-brand-surface (app.css, "fond de page général") plutôt
     qu'une nouvelle valeur ad hoc. --}}
<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="bg-brand-surface dark:bg-zinc-800">
        {{-- Garde-fou de déploiement (2026-10-30, voir DECISIONS.md "Archivage :
             conformité") : un planificateur arrêté coupe alertes, relances,
             archivage et contrôles sans aucune erreur visible — signalé ici
             aux seuls profils qui ont accès à Sécurité & Audit. --}}
        @if (auth()->user()?->hasPrivilege('administration.audit') && ! App\Services\EtatSysteme::planificateurActif())
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
                <flux:callout.heading>{{ __('Le planificateur ne tourne pas') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Alertes SLA, relances, archivage, statistiques, contrôles d\'intégrité et copies PDF/A sont arrêtés.') }}
                    <flux:link :href="route('admin.securite-audit', ['onglet' => 'systeme'])" wire:navigate>{{ __('Voir l\'état du système') }}</flux:link>
                </flux:callout.text>
            </flux:callout>
        @endif
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
