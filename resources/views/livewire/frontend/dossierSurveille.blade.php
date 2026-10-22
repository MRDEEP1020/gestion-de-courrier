{{-- Administration › Dossier surveillé (2026-09-24, voir DECISIONS.md
     "Dossier surveillé : configuration dans l'administration") — mode
     'config' du composant Alpine de resources/js/scan-watcher.js : choisit,
     active et désactive le dossier de scan DE CE POSTE ; n'importe jamais
     rien lui-même (l'import tourne sur Numérisation / Nouveau courrier). --}}
<section class="w-full">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Administration') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Dossier surveillé') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex items-center gap-3">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
            <flux:icon.folder-open class="size-5" />
        </div>
        <div>
            <flux:heading level="1">{{ __('Dossier surveillé') }}</flux:heading>
            <flux:subheading>{{ __('Import automatique des documents scannés : chaque fichier déposé dans le dossier de scan est envoyé sans clic ni rechargement.') }}</flux:subheading>
        </div>
    </div>

    <flux:callout class="mt-6" icon="information-circle">
        <flux:callout.heading>{{ __('Réglage propre à ce poste') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Le navigateur n\'autorise l\'accès à un dossier que sur l\'ordinateur où il est choisi. Faites ce réglage sur le poste de la réception (celui où le scanner enregistre ses fichiers), dans Chrome ou Edge. L\'import tourne ensuite tant que la page Numérisation ou Nouveau courrier est ouverte sur ce poste.') }}
        </flux:callout.text>
    </flux:callout>

    @assets
        @vite('resources/js/scan-watcher.js')
    @endassets

    <div
        class="mt-6 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
        x-data="surveillanceDossier()"
        data-mode="config"
        data-msg-erreur-acces="{{ __('Impossible d\'accéder à ce dossier.') }}"
        data-msg-acces-refuse="{{ __('Accès au dossier refusé.') }}"
        data-msg-fichiers-reinitialises="{{ __('Fichiers déjà traités oubliés — tout ce qui se trouve dans le dossier sera réimporté au prochain cycle.') }}"
    >
        <flux:heading level="2">{{ __('Configuration sur ce poste') }}</flux:heading>

        <template x-if="etat === 'chargement'">
            <flux:text class="mt-4 text-sm text-zinc-500">{{ __('Chargement…') }}</flux:text>
        </template>

        <template x-if="etat === 'non_pris_en_charge'">
            <flux:callout variant="secondary" class="mt-4" icon="exclamation-triangle">
                {{ __('Cette fonctionnalité nécessite Google Chrome ou Microsoft Edge sur ordinateur (non disponible sur Firefox/Safari, ni sur mobile).') }}
            </flux:callout>
        </template>

        <template x-if="etat === 'a_choisir'">
            <div class="mt-4 space-y-3">
                <flux:text class="text-sm">{{ __('Aucun dossier n\'est encore configuré sur ce poste.') }}</flux:text>
                <flux:button variant="primary" icon="folder-open" x-on:click="choisirDossier()">{{ __('Choisir le dossier et démarrer la surveillance') }}</flux:button>
            </div>
        </template>

        <template x-if="etat === 'configure'">
            <div class="mt-4 space-y-4">
                <dl class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Dossier') }}</dt>
                        <dd class="mt-1 flex items-center gap-2 font-medium"><flux:icon.folder class="size-4 text-brand-accent" /><span x-text="nomDossier"></span></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Import automatique') }}</dt>
                        <dd class="mt-1">
                            <span x-show="actif" class="inline-flex items-center rounded-full bg-brand-success-light px-2 py-0.5 text-xs font-medium text-brand-success-dark">{{ __('Activé') }}</span>
                            <span x-show="!actif" class="inline-flex items-center rounded-full bg-brand-disabled-bg px-2 py-0.5 text-xs font-medium text-brand-disabled-text">{{ __('Désactivé') }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-zinc-500">{{ __('Accès au dossier') }}</dt>
                        <dd class="mt-1">
                            <span x-show="permission === 'granted'" class="inline-flex items-center rounded-full bg-brand-success-light px-2 py-0.5 text-xs font-medium text-brand-success-dark">{{ __('Autorisé') }}</span>
                            <span x-show="permission !== 'granted'" class="inline-flex items-center rounded-full bg-brand-warning-light px-2 py-0.5 text-xs font-medium text-brand-warning-dark">{{ __('À autoriser') }}</span>
                        </dd>
                    </div>
                </dl>

                <div class="flex flex-wrap gap-2">
                    <flux:button x-show="!actif" variant="primary" icon="play" x-on:click="activer()">{{ __('Démarrer la surveillance') }}</flux:button>
                    <flux:button x-show="actif && permission !== 'granted'" variant="primary" icon="lock-open" x-on:click="activer()">{{ __('Autoriser l\'accès') }}</flux:button>
                    <flux:button x-show="actif" variant="outline" icon="pause" x-on:click="desactiver()">{{ __('Arrêter la surveillance') }}</flux:button>
                    <flux:button variant="outline" icon="folder-open" x-on:click="choisirDossier()">{{ __('Changer de dossier') }}</flux:button>
                    {{-- 2026-10-08, retour utilisateur ("reset the app so
                         those olds ones can be new again") — un fichier déjà
                         importé/rejeté par le passé est autrement ignoré en
                         silence (voir scan-watcher.js, fichierTraite()) ;
                         ce bouton ne touche QUE ce suivi, jamais la
                         configuration du dossier elle-même (reste actif,
                         pas besoin de re-choisir le dossier). --}}
                    <flux:button variant="outline" icon="arrow-path" x-on:click="reinitialiserFichiersTraites()">{{ __('Réinitialiser les fichiers déjà traités') }}</flux:button>
                    <flux:button variant="ghost" icon="trash" x-on:click="oublier()">{{ __('Retirer la configuration') }}</flux:button>
                </div>
            </div>
        </template>

        <flux:text x-show="messageErreur" x-text="messageErreur" class="mt-3 text-sm text-brand-danger"></flux:text>
        <flux:text x-show="messageConfirmation" x-text="messageConfirmation" class="mt-3 text-sm text-brand-success-dark"></flux:text>
    </div>

    {{-- Derniers imports, tous postes confondus : preuve que la configuration
         fonctionne, sans quitter la page. Historique complet sur Numérisation. --}}
    <div class="mt-6 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-3">
            <flux:heading level="2">{{ __('Derniers documents importés') }}</flux:heading>
            @can('numeriser', App\Models\Courrier::class)
                <flux:link :href="route('courriers.numeriser-nouveau')" wire:navigate class="text-sm">{{ __('Voir tout l\'historique') }}</flux:link>
            @endcan
        </div>

        @if ($this->derniersImports->isEmpty())
            <flux:text class="mt-3 text-sm text-zinc-500">{{ __('Aucun document importé depuis un dossier surveillé pour l\'instant.') }}</flux:text>
        @else
            <ul class="mt-3 divide-y divide-brand-border text-sm dark:divide-zinc-700">
                @foreach ($this->derniersImports as $import)
                    <li wire:key="import-{{ $import->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="font-medium">{{ $import->nom_original }}</span>
                        <span class="text-zinc-500">{{ $import->creePar?->name ?? '—' }} · {{ $import->created_at->format('d/m/Y H:i') }} · {{ str_replace('_', ' ', $import->ocr_statut) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
