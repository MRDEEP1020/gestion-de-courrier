<div>
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Courriers') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Numérisation & OCR') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Chrome de page aligné sur "Utilisateurs & Accès"/"Services"
         (2026-09-22, demande explicite de l'utilisateur : "it should look
         like userlist") — icône en cercle plein + titre + sous-titre, page
         pleine largeur (plus de max-w-2xl qui écrasait le tableau
         ci-dessous dans une colonne étroite). --}}
    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
                {{-- Même icône que sidebar.blade.php pour cette page (cohérence). --}}
                <flux:icon.camera class="size-5" />
            </div>
            <div>
                <flux:heading level="1">{{ __('Numérisation & OCR') }}</flux:heading>
                <flux:subheading>{{ __('Scannez d\'abord le document — le système extrait ce qu\'il peut (numéro de tampon, date) pendant que vous continuez ; vous confirmerez l\'enregistrement juste après.') }}</flux:subheading>
            </div>
        </div>
    </div>

    {{-- ===== Carte "Numériser un document" ===== --}}
    <div class="mt-6 rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading level="2">{{ __('Numériser un document') }}</flux:heading>

        {{-- x-show masque aussi pendant l'état 'chargement' (pas seulement
             'en_surveillance') : bug réel trouvé en revue de code
             (2026-09-10) — init() est asynchrone (IndexedDB +
             queryPermission), la surveillance peut démarrer automatiquement
             sans qu'un clic ne l'annonce ; le formulaire manuel restait
             utilisable pendant cette courte fenêtre, et les deux flux
             uploadant vers la MÊME propriété document pouvaient alors se
             marcher dessus (celui qui committe en dernier écrase
             silencieusement l'autre). --}}
        <form wire:submit="numeriser" class="mt-4 space-y-4" x-show="!surveillanceActive" x-data="{ surveillanceActive: false }" x-on:scan-watcher-etat.window="surveillanceActive = ['chargement', 'en_surveillance'].includes($event.detail.etat)">
            <flux:input type="file" wire:model="document" :label="__('Document (PDF, JPG, PNG, TIFF — 20 Mo max)')" />

            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="document,numeriser">
                {{ __('Numériser') }}
            </flux:button>
        </form>
    </div>

    @assets
        @vite('resources/js/scan-watcher.js')
    @endassets

    {{-- ===== Carte "Importer automatiquement depuis un dossier" ===== --}}
    {{-- Mode 'execution' (2026-09-24, voir DECISIONS.md "Dossier surveillé :
         configuration dans l'administration") : cette page importe, elle ne
         configure plus — choisir/activer le dossier se fait dans
         Administration › Dossier surveillé. Pas de x-init="init()" : Alpine
         appelle déjà init() d'un Alpine.data() (le second appel démarrait
         deux boucles de sondage). --}}
    <div
        class="mt-6 rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
        x-data="surveillanceDossier()"
        x-effect="window.dispatchEvent(new CustomEvent('scan-watcher-etat', { detail: { etat } }))"
        data-mode="execution"
        data-msg-erreur-acces="{{ __('Impossible d\'accéder à ce dossier.') }}"
        data-msg-acces-refuse="{{ __('Accès au dossier refusé.') }}"
        data-msg-droits-perdus="{{ __('Vous n\'êtes plus autorisé à numériser — surveillance en pause.') }}"
        data-msg-erreur-temporaire="{{ __('Échec temporaire — nouvel essai au prochain cycle.') }}"
        data-label-importe="{{ __('Importé') }}"
        data-label-rejete="{{ __('Rejeté') }}"
        data-label-attente="{{ __('En attente') }}"
        data-label-reessai="{{ __('Nouvel essai...') }}"
    >
        {{-- Entrée fichier réelle, cachée : $wire.upload() appelé
             directement en JS reste bloqué sans jamais atteindre le réseau
             (constaté en conditions réelles, voir DECISIONS.md) alors que
             le même champ, rempli nativement (clic + sélection), fonctionne
             parfaitement. Contourne le problème en déclenchant le même
             évènement natif "change" que Livewire sait déjà gérer, plutôt
             que d'appeler l'API JS directement — voir
             resources/js/scan-watcher.js, fonction televerser(). --}}
        {{-- id plutôt que x-ref seul : $refs.entreeCachee restait undefined
             en conditions réelles (probablement la même cause que $wire
             documentée dans DECISIONS.md — une référence mise en cache
             invalidée par un morph Livewire), document.getElementById()
             est sans ambiguïté et fonctionne — voir scan-watcher.js. --}}
        <input type="file" wire:model="document" id="scan-watcher-entree-cachee" class="hidden" tabindex="-1" aria-hidden="true">

        <flux:heading level="2">{{ __('Import automatique depuis le dossier surveillé') }}</flux:heading>
        <flux:subheading>
            {{ __('Chaque nouveau document déposé dans le dossier de scan de ce poste est envoyé automatiquement, sans recharger la page.') }}
        </flux:subheading>

        <template x-if="etat === 'non_pris_en_charge'">
            <flux:callout variant="secondary" class="mt-4" icon="exclamation-triangle">
                {{ __('Cette fonctionnalité nécessite Google Chrome ou Microsoft Edge sur ordinateur (non disponible sur Firefox/Safari, ni sur mobile).') }}
            </flux:callout>
        </template>

        <template x-if="etat === 'non_configure'">
            <flux:text class="mt-4 text-sm text-zinc-500">
                {{ __('L\'import automatique n\'est pas activé sur ce poste.') }}
                @if (auth()->user()->hasPrivilege('administration.dossier_surveille'))
                    <flux:link :href="route('admin.dossier-surveille')" wire:navigate>{{ __('Le configurer') }}</flux:link>
                @else
                    {{ __('Demandez à un administrateur de le configurer.') }}
                @endif
            </flux:text>
        </template>

        {{-- Chrome redemande parfois l'accès au dossier (après un
             redémarrage) : un clic suffit, sans reconfigurer. --}}
        <template x-if="etat === 'a_reprendre'">
            <div class="mt-4 flex items-center gap-3">
                <flux:text x-text="nomDossier"></flux:text>
                <flux:button variant="primary" size="sm" icon="folder-open" x-on:click="reprendre()">{{ __('Autoriser l\'import automatique') }}</flux:button>
            </div>
        </template>

        <template x-if="etat === 'en_pause_erreur'">
            <flux:callout variant="danger" class="mt-4" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Surveillance interrompue') }}</flux:callout.heading>
                <flux:callout.text x-text="messageErreur"></flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" x-on:click="reprendre()">{{ __('Réessayer') }}</flux:button>
                </x-slot>
            </flux:callout>
        </template>

        <template x-if="etat === 'en_surveillance'">
            <div class="mt-4 space-y-3">
                <div class="flex items-center gap-3">
                    {{-- Span habillé directement, pas flux:badge color= (palette
                         Tailwind nommée de Flux Pro, ne peut pas produire les hex
                         exacts du système de couleurs GEC, 2026-09-17) — "Actif"
                         rejoint Success. --}}
                    <span class="inline-flex items-center rounded-full bg-brand-success-light px-2 py-0.5 text-xs font-medium text-brand-success-dark">{{ __('Surveillance active') }}</span>
                    <flux:text x-text="nomDossier"></flux:text>
                </div>

                <flux:text class="text-sm text-zinc-500">
                    <span x-text="totalImportes"></span> {{ __('document(s) importé(s)') }},
                    <span x-text="totalRejetes"></span> {{ __('rejeté(s)') }}
                </flux:text>

                <flux:text class="text-sm font-medium" x-show="Object.keys(journal).length">{{ __('Documents détectés dans le dossier') }}</flux:text>

                <ul class="max-h-48 space-y-1 overflow-y-auto text-sm" x-show="Object.keys(journal).length">
                    <template x-for="[nom, info] in Object.entries(journal)" :key="nom">
                        <li class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                x-bind:class="{ importe: 'bg-brand-success-light text-brand-success-dark', rejete: 'bg-brand-danger-light text-brand-danger-dark', erreur_temporaire: 'bg-brand-warning-light text-brand-warning-dark' }[info.statut] ?? 'bg-brand-disabled-bg text-brand-disabled-text'"
                                x-text="{ importe: messages.labelImporte, rejete: messages.labelRejete, erreur_temporaire: messages.labelReessai }[info.statut] ?? messages.labelAttente"
                            ></span>
                            <span x-text="nom"></span>
                            <span x-show="info.raison" x-text="info.raison" class="text-zinc-500"></span>
                        </li>
                    </template>
                </ul>
            </div>
        </template>
    </div>

    {{-- ===== "Documents importés depuis le dossier surveillé" — table
         admin persistante (2026-09-22, demande explicite de l'utilisateur :
         "since enregistre already has them [the pending list] make it as a
         list table showing to the admin the import and documents inside
         the dossier surveille", puis "it should look like userlist") :
         historique complet des documents importés spécifiquement via le
         dossier surveillé, visible même après rechargement de la page ou
         depuis un autre poste — contrairement au journal Alpine ci-dessus
         (éphémère, uniquement pendant que la surveillance tourne dans CE
         navigateur), gardé tel quel en plus de ce tableau. Même gabarit que
         userList.blade.php : carte de recherche séparée + carte tableau. --}}
    <div class="mt-6 flex items-center gap-3">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent-pale text-brand-accent">
            <flux:icon.archive-box class="size-5" />
        </div>
        <div>
            <flux:heading level="2">{{ __('Documents importés depuis le dossier surveillé') }}</flux:heading>
            <flux:subheading>{{ __('Historique persistant de tous les documents reçus via l\'import automatique — visible même après rechargement de la page.') }}</flux:subheading>
        </div>
    </div>

    <div class="mt-4 rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:input wire:model.live.debounce.300ms="rechercheImports" icon="magnifying-glass" :placeholder="__('Rechercher par nom de fichier…')" />
    </div>

    @php $peutToutVoir = auth()->user()->hasPrivilege('brouillons.utiliser_tout'); @endphp

    <div class="mt-4">
        @if ($this->importsDossierSurveille->isEmpty())
            <flux:text class="text-zinc-500">{{ __('Aucun document importé depuis un dossier surveillé pour l\'instant.') }}</flux:text>
        @else
            <div class="overflow-x-auto rounded-2xl border border-brand-border bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <table class="w-full text-sm">
                    <thead class="bg-brand-table-header text-left text-sm font-medium text-zinc-600 dark:bg-zinc-800">
                        <tr>
                            <th class="py-3 pl-4 pr-3">{{ __('Nom du fichier') }}</th>
                            @if ($peutToutVoir)
                                <th class="py-3 pr-3">{{ __('Importé par') }}</th>
                            @endif
                            <th class="py-3 pr-3">{{ __('Date d\'import') }}</th>
                            <th class="py-3 pr-3">{{ __('Statut OCR') }}</th>
                            <th class="py-3 pr-4">{{ __('Document') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-border dark:divide-zinc-700">
                        @foreach ($this->importsDossierSurveille as $import)
                            <tr wire:key="import-{{ $import->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <td class="py-3 pl-4 pr-3 font-medium">{{ $import->nom_original }}</td>
                                @if ($peutToutVoir)
                                    <td class="py-3 pr-3 text-zinc-600 dark:text-zinc-400">{{ $import->creePar?->name ?? '—' }}</td>
                                @endif
                                <td class="py-3 pr-3 text-zinc-600 dark:text-zinc-400">{{ $import->created_at->format('d/m/Y H:i') }}</td>
                                <td class="py-3 pr-3">
                                    {{-- Même badge inline que showCourrier.blade.php (OCR) —
                                         pas flux:badge color= (palette Tailwind nommée de
                                         Flux Pro, ne produit pas les hex exacts du système
                                         de couleurs GEC). --}}
                                    <span class="{{ match ($import->ocr_statut) {
                                        'reussi' => 'bg-brand-success-light text-brand-success-dark',
                                        'en_cours' => 'bg-brand-info-light text-brand-info-dark',
                                        'echec_qualite', 'echec' => 'bg-brand-danger-light text-brand-danger-dark',
                                        default => 'bg-brand-disabled-bg text-brand-disabled-text',
                                    } }} inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize">
                                        {{ str_replace('_', ' ', $import->ocr_statut) }}
                                    </span>
                                </td>
                                <td class="py-3 pr-4">
                                    @if ($import->finalise_le)
                                        <flux:text class="text-sm text-zinc-500">{{ __('Enregistré le :date', ['date' => $import->finalise_le->format('d/m/Y H:i')]) }}</flux:text>
                                    @elsecan('create', App\Models\Courrier::class)
                                        <flux:button size="sm" variant="outline" :href="route('courriers.nouveau', ['brouillonId' => $import->id])" wire:navigate>
                                            {{ __('Continuer l\'enregistrement') }}
                                        </flux:button>
                                    @else
                                        {{-- Opérateur de scan sans courriers.creer (2026-09-23). --}}
                                        <flux:text class="text-sm text-zinc-500">{{ __('En attente d\'enregistrement') }}</flux:text>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $this->importsDossierSurveille->links() }}</div>
        @endif
    </div>

    <flux:text class="mt-6 block text-sm text-zinc-500">
        {{ __('Un courrier déjà enregistré ? La numérisation de son document se fait depuis sa fiche.') }}
        @can('create', App\Models\Courrier::class)
            {{ __('Vous préférez saisir à la main sans scanner ?') }}
            <flux:link :href="route('courriers.nouveau')" wire:navigate>{{ __('Enregistrer directement') }}</flux:link>
        @endcan
    </flux:text>
</div>
