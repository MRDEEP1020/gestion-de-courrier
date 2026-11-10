{{-- Mise en page alignée sur "Organisation" (2026-09-23, demande explicite
     de l'utilisateur : "use the design of organisation for parametre a
     side panel showing all config with a main displaying them") : panneau
     latéral gauche STICKY listant toutes les catégories de paramètres
     (même carte + <nav> que organisationIndex.blade.php, "Structure de
     l'organisation") + colonne principale affichant la catégorie active.
     Switch purement Alpine (x-show, pas de propriété Livewire dédiée) —
     même patron que les onglets de showCourrier.blade.php/editForm.blade.php
     — un SEUL formulaire sous-jacent : "Enregistrer" soumet toutes les
     catégories d'un coup, quel que soit l'onglet affiché à l'écran. --}}
<section class="w-full" x-data="{ section: 'numero' }">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Administration') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Paramètres système') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex items-center gap-3">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
            <flux:icon.adjustments-horizontal class="size-5" />
        </div>
        <div>
            <flux:heading level="1">{{ __('Paramètres système') }}</flux:heading>
            <flux:subheading>{{ __('Réglages transversaux — plus besoin de modifier le code pour les changer.') }}</flux:subheading>
        </div>
    </div>

    <form wire:submit="enregistrer" class="mt-6 grid gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
        {{-- ===== Panneau latéral — toutes les catégories ===== --}}
        <div class="sticky top-20 self-start space-y-3">
            <div class="rounded-2xl border border-brand-border bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-2 px-1">
                    <flux:text class="text-xs font-medium uppercase text-zinc-500">{{ __('Catégories') }}</flux:text>
                </div>

                <nav class="space-y-0.5">
                    @foreach ([
                        ['cle' => 'numero', 'label' => __('Numéro de référence'), 'icon' => 'hashtag'],
                        ['cle' => 'sla', 'label' => __('SLA — Délais'), 'icon' => 'clock'],
                        ['cle' => 'notifications', 'label' => __('Préférences de notification'), 'icon' => 'bell'],
                        ['cle' => 'sla_type', 'label' => __('SLA — Par type de document'), 'icon' => 'queue-list'],
                        ['cle' => 'qualite', 'label' => __('Confidentialité & qualité scan/OCR'), 'icon' => 'shield-check'],
                        ['cle' => 'liste_type_document', 'label' => __('Types de document'), 'icon' => 'document-text'],
                        ['cle' => 'liste_mode_reception', 'label' => __('Modes de réception'), 'icon' => 'inbox-arrow-down'],
                        ['cle' => 'liste_priorite', 'label' => __('Priorités'), 'icon' => 'flag'],
                        ['cle' => 'decharge', 'label' => __('Modèle de décharge'), 'icon' => 'document-check'],
                    ] as $categorie)
                        <button
                            type="button"
                            x-on:click="section = '{{ $categorie['cle'] }}'"
                            :class="section === '{{ $categorie['cle'] }}' ? 'bg-brand-accent-pale text-brand-accent' : 'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/60'"
                            class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-sm font-medium transition-colors"
                        >
                            <flux:icon :icon="$categorie['icon']" class="size-4 shrink-0" />
                            <span class="truncate">{{ $categorie['label'] }}</span>
                        </button>
                    @endforeach
                </nav>
            </div>

            <flux:button type="submit" variant="primary" icon="check" class="w-full">{{ __('Enregistrer') }}</flux:button>
        </div>

        {{-- ===== Colonne principale — catégorie active ===== --}}
        <div class="space-y-4">
            {{-- ----- Numéro de référence ----- --}}
            <div x-show="section === 'numero'" x-cloak class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-1 flex items-center gap-2">
                    <flux:icon.hashtag class="size-4 text-brand-accent" />
                    <flux:heading level="2" class="text-base">{{ __('Numéro de référence') }}</flux:heading>
                </div>
                <flux:text class="mb-4 text-zinc-500">{{ __('Format attribué à chaque nouveau courrier — les numéros déjà générés ne changent jamais, même après une modification ici.') }}</flux:text>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model.live="numeroReferencePrefixe"
                        :label="__('Préfixe')"
                        maxlength="10"
                        placeholder="GEC"
                        :description="__('Lettres et chiffres uniquement, ex. GEC.')"
                    />
                    <flux:input
                        type="number"
                        wire:model.live="numeroReferenceChiffresSequence"
                        :label="__('Chiffres de la séquence')"
                        min="3"
                        max="10"
                        :description="__('Nombre de zéros de remplissage devant le numéro d\'ordre.')"
                    />
                </div>

                {{-- Aperçu RÉEL — le prochain numéro effectivement disponible
                     cette année (ParametreSysteme::apercuProchainNumero()),
                     recalculé à chaque frappe grâce à wire:model.live
                     ci-dessus, sans jamais toucher à la vraie séquence. --}}
                <div class="mt-4 flex items-center gap-2 rounded-lg bg-brand-surface-soft px-4 py-3 dark:bg-zinc-800">
                    <flux:icon.eye class="size-4 shrink-0 text-zinc-400" />
                    <flux:text class="text-sm">
                        {{ __('Prochain numéro') }} :
                        <span class="font-mono font-semibold text-brand-accent">{{ $this->apercuProchainNumero }}</span>
                    </flux:text>
                </div>
            </div>

            {{-- ----- SLA — délais ----- --}}
            <div x-show="section === 'sla'" x-cloak class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-1 flex items-center gap-2">
                    <flux:icon.clock class="size-4 text-brand-accent" />
                    <flux:heading level="2" class="text-base">{{ __('SLA — délais de traitement') }}</flux:heading>
                </div>
                <flux:text class="mb-4 text-zinc-500">{{ __('Jours calendaires. Une échéance saisie à la main sur un courrier, ou un délai propre à ce courrier, reste toujours prioritaire sur ces valeurs.') }}</flux:text>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <flux:input
                        type="number"
                        wire:model="slaJoursDefaut"
                        :label="__('Délai par défaut')"
                        min="1"
                        max="365"
                        :description="__('Utilisé pour tout type sans délai spécifique.')"
                    />
                    <flux:input
                        type="number"
                        wire:model="slaSeuilRisqueJours"
                        :label="__('Seuil « bientôt en retard »')"
                        min="0"
                        max="30"
                        :description="__('Jours restants avant l\'alerte préventive.')"
                    />
                    <flux:input
                        type="number"
                        wire:model="slaRelanceJours"
                        :label="__('Fréquence de relance')"
                        min="1"
                        max="30"
                        :description="__('Une fois en retard, relance au plus tous les N jours.')"
                    />
                    <flux:input
                        type="number"
                        wire:model="slaEscaladeJours"
                        :label="__('Escalade après (jours de retard)')"
                        min="1"
                        max="365"
                        :placeholder="__('Désactivée')"
                        :description="__('Laisser vide pour désactiver. Notifie en plus le niveau hiérarchique supérieur (Organisation).')"
                    />
                </div>
            </div>

            {{-- ----- Préférences de notification — valeurs par défaut ----- --}}
            <div x-show="section === 'notifications'" x-cloak class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-1 flex items-center gap-2">
                    <flux:icon.bell class="size-4 text-brand-accent" />
                    <flux:heading level="2" class="text-base">{{ __('Préférences de notification') }}</flux:heading>
                </div>
                <flux:text class="mb-4 text-zinc-500">{{ __('Valeurs appliquées par défaut aux utilisateurs. Chacun peut les remplacer dans ses paramètres personnels. La fréquence de relance SLA par défaut est réglée dans la section « SLA — Délais ».') }}</flux:text>

                {{-- 2026-11-10 — colonnes « E-mail » / « Application » avec un en-tête,
                     comme la page Paramètres › Notifications (le libellé à côté de
                     chaque interrupteur ne disait pas lequel il commandait). --}}
                <div class="overflow-hidden rounded-xl border border-brand-border dark:border-zinc-700">
                    <div class="hidden grid-cols-[minmax(0,1fr)_7rem_7rem] items-center gap-4 border-b border-brand-border bg-zinc-50 px-4 py-2.5 text-sm font-medium text-zinc-900 sm:grid dark:border-zinc-700 dark:bg-zinc-800/60 dark:text-white">
                        <span>{{ __('Activité') }}</span>
                        <span class="flex items-center justify-center gap-1.5"><flux:icon.envelope class="size-4" />{{ __('E-mail') }}</span>
                        <span class="flex items-center justify-center gap-1.5"><flux:icon.bell class="size-4" />{{ __('Application') }}</span>
                    </div>
                    <div class="divide-y divide-brand-border dark:divide-zinc-700">
                        @foreach (\App\Services\NotificationPreferenceService::CATEGORIES as $categorie => $details)
                            <div wire:key="notification-defaut-{{ $categorie }}" class="grid gap-3 px-4 py-3.5 sm:grid-cols-[minmax(0,1fr)_7rem_7rem] sm:items-center sm:gap-4">
                                <div class="min-w-0">
                                    <flux:text class="font-medium text-zinc-900 dark:text-white">{{ __($details['label']) }}</flux:text>
                                    <flux:text class="mt-0.5 text-sm">{{ __($details['description']) }}</flux:text>
                                </div>
                                @foreach (\App\Services\NotificationPreferenceService::CANAUX as $canal)
                                    <div class="flex items-center justify-between gap-3 rounded-lg bg-zinc-50 px-3 py-2 sm:justify-center sm:bg-transparent sm:p-0 dark:bg-zinc-800/60 sm:dark:bg-transparent">
                                        <span class="flex items-center gap-1.5 text-sm sm:hidden" aria-hidden="true">
                                            <flux:icon :icon="$canal === 'email' ? 'envelope' : 'bell'" class="size-4" />{{ $canal === 'email' ? __('E-mail') : __('Application') }}
                                        </span>
                                        <flux:switch wire:model="notificationsDefaut.{{ $categorie }}.{{ $canal }}" aria-label="{{ $canal === 'email' ? __('E-mail') : __('Application') }} — {{ __($details['label']) }}" />
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
                <flux:text class="mt-3 text-sm">{{ __('« Application » regroupe la cloche et les alertes qui s\'affichent en direct. Un utilisateur qui a personnalisé un réglage garde son choix.') }}</flux:text>
            </div>

            {{-- ----- SLA — délais par type de document ----- --}}
            <div x-show="section === 'sla_type'" x-cloak class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-1 flex items-center gap-2">
                    <flux:icon.queue-list class="size-4 text-brand-accent" />
                    <flux:heading level="2" class="text-base">{{ __('SLA — délais par type de document') }}</flux:heading>
                </div>
                <flux:text class="mb-4 text-zinc-500">{{ __('Laissez vide pour utiliser le délai par défaut.') }}</flux:text>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (App\Livewire\Backend\Forms\CourrierForm::typesDocument() as $type)
                        <flux:input
                            type="number"
                            wire:model="slaParType.{{ $type }}"
                            :label="$type"
                            min="1"
                            max="365"
                            :placeholder="(string) $slaJoursDefaut"
                        />
                    @endforeach
                </div>
            </div>

            {{-- ----- Confidentialité & qualité scan/OCR (Group A) ----- --}}
            <div x-show="section === 'qualite'" x-cloak class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-1 flex items-center gap-2">
                    <flux:icon.shield-check class="size-4 text-brand-accent" />
                    <flux:heading level="2" class="text-base">{{ __('Confidentialité & qualité scan/OCR') }}</flux:heading>
                </div>
                <flux:text class="mb-4 text-zinc-500">{{ __('Seuils techniques — modifier ces valeurs change le comportement du scan et de la reconnaissance de texte pour tous les prochains courriers.') }}</flux:text>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        type="number"
                        wire:model="niveauConfidentialiteMax"
                        :label="__('Échelle de confidentialité — niveau maximum')"
                        min="1"
                        max="20"
                        :description="__('Plafond de l\'échelle numérique (Module 9), partagé par les utilisateurs et les courriers.')"
                    />
                    <flux:input
                        type="number"
                        wire:model="scanResolutionMinimale"
                        :label="__('Résolution minimale du scan (px)')"
                        min="100"
                        max="5000"
                        :description="__('Plus petit côté accepté pour une image envoyée à l\'OCR.')"
                    />
                    <flux:input
                        type="number"
                        wire:model="ocrConfianceMinimale"
                        :label="__('Confiance OCR minimale (%)')"
                        min="0"
                        max="100"
                        :description="__('En dessous, le scan est marqué en échec qualité et un nouveau scan est demandé.')"
                    />
                    <flux:input
                        type="number"
                        wire:model="ocrLongueurMinimaleTexte"
                        :label="__('Longueur de texte minimale (caractères)')"
                        min="0"
                        max="1000"
                        :description="__('En dessous, le texte reconnu est considéré comme absent (page vide, manuscrite...).')"
                    />
                    <flux:input
                        type="number"
                        wire:model="dashboardDelaiMoyenPeriodeJours"
                        :label="__('Tableau de bord — période du délai moyen (jours)')"
                        min="1"
                        max="730"
                        :description="__('Fenêtre glissante utilisée pour calculer le délai moyen de traitement affiché sur le tableau de bord.')"
                    />
                </div>
            </div>

            {{-- ----- Group B — listes de référence gérables ----- --}}
            @foreach ([
                ['cle' => 'liste_type_document', 'type' => \App\Models\ListeReference::TYPE_DOCUMENT, 'items' => $this->listesTypeDocument, 'ajouter' => 'ajouterTypeDocument', 'propriete' => 'nouveauTypeDocument', 'titre' => __('Types de document'), 'aide' => __('Proposés à la saisie du formulaire d\'enregistrement (Module 1). Une valeur protégée ne peut pas être renommée — une règle du système dépend de sa valeur exacte.')],
                ['cle' => 'liste_mode_reception', 'type' => \App\Models\ListeReference::MODE_RECEPTION, 'items' => $this->listesModeReception, 'ajouter' => 'ajouterModeReception', 'propriete' => 'nouveauModeReception', 'titre' => __('Modes de réception'), 'aide' => __('Une valeur protégée est détectée automatiquement par l\'OCR ou utilisée comme valeur par défaut — elle ne peut pas être renommée.')],
                ['cle' => 'liste_priorite', 'type' => \App\Models\ListeReference::PRIORITE, 'items' => $this->listesPriorite, 'ajouter' => 'ajouterPriorite', 'propriete' => 'nouvellePriorite', 'titre' => __('Priorités'), 'aide' => __('Chaque priorité pilote une couleur d\'affichage dans l\'application — les 4 priorités de base sont protégées contre le renommage.')],
            ] as $liste)
                <div x-show="section === '{{ $liste['cle'] }}'" x-cloak class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="mb-1 flex items-center gap-2">
                        <flux:heading level="2" class="text-base">{{ $liste['titre'] }}</flux:heading>
                    </div>
                    <flux:text class="mb-4 text-zinc-500">{{ $liste['aide'] }}</flux:text>

                    <div class="divide-y divide-brand-border dark:divide-zinc-700">
                        @forelse ($liste['items'] as $valeur)
                            <div wire:key="liste-{{ $liste['type'] }}-{{ $valeur->id }}" class="flex items-center gap-2 py-2 {{ ! $valeur->actif ? 'opacity-50' : '' }}">
                                <div class="flex shrink-0 flex-col">
                                    <button type="button" wire:click="deplacerValeur({{ $valeur->id }}, 'haut')" class="text-zinc-400 hover:text-brand-accent disabled:opacity-30" {{ $loop->first ? 'disabled' : '' }}>
                                        <flux:icon.chevron-up class="size-3.5" />
                                    </button>
                                    <button type="button" wire:click="deplacerValeur({{ $valeur->id }}, 'bas')" class="text-zinc-400 hover:text-brand-accent disabled:opacity-30" {{ $loop->last ? 'disabled' : '' }}>
                                        <flux:icon.chevron-down class="size-3.5" />
                                    </button>
                                </div>

                                <div class="min-w-0 flex-1">
                                    @if ($renommageListeId === $valeur->id)
                                        <div class="flex items-center gap-2">
                                            <flux:input size="sm" wire:model="renommageListeValeur" wire:keydown.enter.prevent="enregistrerRenommage" />
                                            <flux:button size="sm" variant="primary" icon="check" wire:click="enregistrerRenommage" />
                                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="annulerRenommage" />
                                        </div>
                                    @else
                                        <div class="flex items-center gap-1.5">
                                            <flux:text class="truncate">{{ $valeur->valeur }}</flux:text>
                                            @if ($valeur->protege)
                                                <flux:tooltip :content="__('Valeur protégée — non renommable')">
                                                    <flux:icon.lock-closed class="size-3.5 shrink-0 text-zinc-400" />
                                                </flux:tooltip>
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    @unless ($valeur->protege)
                                        <flux:button size="sm" variant="ghost" icon="pencil" wire:click="ouvrirRenommage({{ $valeur->id }})" :title="__('Renommer')" />
                                    @endunless
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        :icon="$valeur->actif ? 'eye' : 'eye-slash'"
                                        wire:click="basculerActif({{ $valeur->id }})"
                                        :title="$valeur->actif ? __('Désactiver') : __('Activer')"
                                    />
                                </div>
                            </div>
                        @empty
                            <flux:text class="py-4 text-zinc-500">{{ __('Aucune valeur.') }}</flux:text>
                        @endforelse
                    </div>

                    <div class="mt-4 flex items-end gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                        <div class="flex-1">
                            <flux:input wire:model="{{ $liste['propriete'] }}" :label="__('Nouvelle valeur')" wire:keydown.enter.prevent="{{ $liste['ajouter'] }}" />
                        </div>
                        <flux:button variant="primary" icon="plus" wire:click="{{ $liste['ajouter'] }}">{{ __('Ajouter') }}</flux:button>
                    </div>
                </div>
            @endforeach

            {{-- ----- Modèle de décharge (2026-10-28, « je veux qu'elle soit
                 modifiable à partir de l'interface ») — textes de la décharge
                 de remise de l'original (pdf/decharge-remise), enregistrés
                 avec les autres réglages. ----- --}}
            <div x-show="section === 'decharge'" x-cloak class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-1 flex items-center gap-2">
                    <flux:icon.document-check class="size-4 text-brand-accent" />
                    <flux:heading level="2" class="text-base">{{ __('Modèle de décharge') }}</flux:heading>
                </div>
                <flux:text class="mb-4 text-zinc-500">{{ __('Textes imprimés sur la décharge de remise de l\'original au service. Les repères entre accolades sont remplacés à l\'impression ; un champ facultatif laissé vide retire sa partie du document.') }}</flux:text>

                {{-- Format du numéro (2026-10-28, « le numéro de référence de la
                     décharge doit être modifiable ») — même présentation que
                     la catégorie « Numéro de référence ». --}}
                <div class="mb-5 rounded-xl border border-brand-border p-4 dark:border-zinc-700">
                    <div class="mb-3 flex items-center gap-2">
                        <flux:icon.hashtag class="size-4 text-brand-accent" />
                        <flux:heading level="3" class="text-sm">{{ __('Numéro de la décharge') }}</flux:heading>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input
                            wire:model.live="dechargeNumeroPrefixe"
                            :label="__('Préfixe')"
                            maxlength="10"
                            placeholder="ACC"
                            :description="__('Lettres et chiffres uniquement, ex. ACC ou DCH.')"
                        />
                        <flux:input
                            type="number"
                            wire:model.live="dechargeNumeroChiffres"
                            :label="__('Chiffres de la séquence')"
                            min="3"
                            max="10"
                            :description="__('Nombre de zéros de remplissage devant le numéro d\'ordre.')"
                        />
                    </div>
                    <div class="mt-3 flex items-center gap-2 rounded-lg bg-brand-surface-soft px-4 py-3 dark:bg-zinc-800">
                        <flux:icon.eye class="size-4 shrink-0 text-zinc-400" />
                        <flux:text class="text-sm">
                            {{ __('Exemple de prochain numéro') }} :
                            <span class="font-mono font-semibold text-brand-accent">{{ $this->apercuNumeroDecharge }}</span>
                            <span class="text-zinc-500">— {{ __('les décharges déjà émises gardent leur numéro.') }}</span>
                        </flux:text>
                    </div>
                </div>

                <div class="mb-4 rounded-lg bg-brand-surface-soft p-3 text-sm dark:bg-zinc-800">
                    <div class="mb-2 font-medium">{{ __('Repères disponibles') }}</div>
                    <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-2">
                        @foreach (\App\Models\Parametre::REPERES_DECHARGE as $repere => $description)
                            <div class="flex items-baseline gap-2">
                                <dt><code class="rounded bg-white px-1 font-mono text-xs text-brand-accent dark:bg-zinc-900">{{ $repere }}</code></dt>
                                <dd class="text-zinc-600">{{ __($description) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:textarea wire:model="modeleDecharge.societe" :label="__('En-tête sous le logo')" rows="2" :description="__('Facultatif.')" />
                    <flux:input wire:model="modeleDecharge.ville" :label="__('Ville')" :description="__('Imprimée devant la date : « Douala, le … ».')" />
                    <flux:input wire:model="modeleDecharge.titre" :label="__('Titre')" />
                    <flux:input wire:model="modeleDecharge.sous_titre" :label="__('Sous-titre')" :description="__('Facultatif.')" />
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="modeleDecharge.formule" :label="__('Formule')" rows="4" :description="__('Texte principal — les repères y sont imprimés en gras.')" />
                    </div>
                    {{-- 2026-11-09 — l'accueil ne signe plus : « remis par » est
                         un cadre d'information, seul « reçu par » se signe. --}}
                    <flux:input wire:model="modeleDecharge.cadre_remis" :label="__('Cadre d\'information — remis par (sans signature)')" />
                    <flux:input wire:model="modeleDecharge.cadre_recu" :label="__('Cadre de signature — reçu par')" />
                    <flux:input wire:model="modeleDecharge.observations" :label="__('Zone d\'observations — titre')" :description="__('Laisser vide pour retirer la zone.')" />
                    <flux:input wire:model="modeleDecharge.pied" :label="__('Pied de page')" :description="__('Facultatif.')" />
                    <div class="sm:col-span-2">
                        <flux:textarea wire:model="modeleDecharge.mention" :label="__('Mention finale')" rows="2" :description="__('Facultatif.')" />
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-brand-border pt-4 dark:border-zinc-700">
                    <flux:button type="submit" form="apercu-modele-decharge" variant="outline" icon="eye">{{ __('Aperçu PDF') }}</flux:button>
                    <flux:button type="button" variant="ghost" icon="arrow-path" wire:click="retablirModeleDecharge">{{ __('Rétablir le modèle par défaut') }}</flux:button>
                    <flux:text class="text-sm text-zinc-500">{{ __('L\'aperçu reprend les textes saisis, même pas encore enregistrés.') }}</flux:text>
                </div>
            </div>
        </div>
    </form>

    {{-- Aperçu du modèle de décharge — formulaire SÉPARÉ (jamais imbriqué
         dans celui de Livewire, que le bouton vise par son attribut form),
         rempli au moment de l'envoi avec les valeurs saisies ($wire, même pas
         encore enregistrées) et ouvert dans un nouvel onglet. --}}
    <form
        id="apercu-modele-decharge"
        method="POST"
        action="{{ route('admin.parametres.decharge.apercu') }}"
        target="_blank"
        class="hidden"
        x-data
        x-on:submit="$el.querySelectorAll('input[data-champ]').forEach((champ) => { champ.value = $wire.modeleDecharge[champ.dataset.champ] ?? '' })"
    >
        @csrf
        @foreach (array_keys(\App\Models\Parametre::modeleDechargeParDefaut()) as $champ)
            <input type="hidden" name="modele[{{ $champ }}]" data-champ="{{ $champ }}">
        @endforeach
    </form>
</section>
