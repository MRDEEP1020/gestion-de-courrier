{{-- Module 9 — Administration › Sécurité & Audit (2026-10-30, conformité de
     l'archivage, voir DECISIONS.md) : journal des consultations, intégrité
     des fichiers, conservation des archives, état du système. Actions de
     conservation réservées à archives.gerer_conservation. --}}
@php
    $carte = 'rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900';
    $enTeteTableau = 'border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400';
    $peutGerer = auth()->user()->can('gererConservation', App\Models\Courrier::class);
@endphp

<section class="w-full space-y-6">
    <div class="flex items-center gap-3">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
            <flux:icon.shield-check class="size-5" />
        </div>
        <div>
            <flux:heading level="1">{{ __('Sécurité & Audit') }}</flux:heading>
            <flux:subheading>{{ __('Qui consulte quoi, intégrité des fichiers, conservation des archives et état du système.') }}</flux:subheading>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach (['consultations' => __('Journal des consultations'), 'integrite' => __('Intégrité des fichiers'), 'conservation' => __('Conservation des archives'), 'stockage' => __('Stockage'), 'systeme' => __('Système')] as $cle => $libelle)
            <flux:button size="sm" :variant="$onglet === $cle ? 'primary' : 'ghost'" wire:click="changerOnglet('{{ $cle }}')">{{ $libelle }}</flux:button>
        @endforeach
    </div>

    {{-- ===== Journal des consultations ===== --}}
    @if ($onglet === 'consultations')
        <div class="{{ $carte }} space-y-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <flux:input wire:model.live.debounce.400ms="recherche" icon="magnifying-glass" :label="__('Référence ou personne')" clearable />
                <flux:select wire:model.live="action" :label="__('Action')">
                    <flux:select.option value="">{{ __('Toutes les actions') }}</flux:select.option>
                    @foreach (App\Models\CourrierConsultation::ACTIONS as $cle => $libelle)
                        <flux:select.option value="{{ $cle }}">{{ __($libelle) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="date" wire:model.live="du" :label="__('Du')" />
                <flux:input type="date" wire:model.live="au" :label="__('Au')" />
            </div>

            @if ($this->consultations->isEmpty())
                <flux:text class="text-sm text-zinc-500">{{ __('Aucune consultation enregistrée pour ces critères.') }}</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="{{ $enTeteTableau }}">
                            <tr>
                                <th class="whitespace-nowrap py-3 pl-4 pr-3">{{ __('Date') }}</th>
                                <th class="py-3 pr-3">{{ __('Personne') }}</th>
                                <th class="py-3 pr-3">{{ __('Action') }}</th>
                                <th class="py-3 pr-3">{{ __('Courrier') }}</th>
                                <th class="py-3 pr-4">{{ __('Adresse IP') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->consultations as $ligne)
                                <tr wire:key="consultation-{{ $ligne->id }}">
                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 tabular-nums">{{ $ligne->created_at->format('d/m/Y H:i:s') }}</td>
                                    <td class="py-4 pr-3">{{ $ligne->utilisateur?->name ?? __('Utilisateur supprimé') }}</td>
                                    <td class="py-4 pr-3">{{ $ligne->libelle() }}</td>
                                    <td class="py-4 pr-3">
                                        @if ($ligne->courrier && ! $ligne->courrier->trashed())
                                            <flux:link :href="route('courriers.show', $ligne->courrier_id)" wire:navigate>{{ $ligne->courrier->numero_reference }}</flux:link>
                                        @else
                                            {{ $ligne->courrier?->numero_reference ?? '—' }}
                                        @endif
                                    </td>
                                    <td class="py-4 pr-4 tabular-nums text-zinc-500">{{ $ligne->ip ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $this->consultations->links() }}
            @endif
        </div>
    @endif

    {{-- ===== Intégrité des fichiers ===== --}}
    @if ($onglet === 'integrite')
        @php $integrite = $this->integrite; @endphp
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([[__('Fichiers intègres'), $integrite['ok']], [__('Fichiers altérés'), $integrite['alteree']], [__('Fichiers introuvables'), $integrite['manquant']], [__('Fichiers détruits (échéance)'), $integrite['detruit']]] as [$libelle, $valeur])
                <div class="rounded-2xl border border-brand-border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:text class="text-sm text-zinc-500">{{ $libelle }}</flux:text>
                    <div class="mt-1 text-2xl font-semibold text-brand-text-primary">{{ $valeur }}</div>
                </div>
            @endforeach
        </div>

        <div class="{{ $carte }} space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading level="2">{{ __('Contrôle des empreintes SHA-256') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">
                        {{ __('Chaque fichier reçoit une empreinte à son enregistrement ; elle est recalculée chaque nuit. Une différence prouve que le fichier a été modifié sur le stockage.') }}
                    </flux:text>
                    <flux:text class="mt-1 text-sm">
                        {{ $integrite['derniereVerification'] ? __('Dernière vérification complète : :quand', ['quand' => $integrite['derniereVerification']->format('d/m/Y H:i')]) : __('Aucune vérification complète pour l\'instant.') }}
                    </flux:text>
                </div>
                <flux:button variant="outline" icon="shield-check" wire:click="lancerVerification">{{ __('Vérifier maintenant') }}</flux:button>
            </div>

            @if ($integrite['alertes'] === [])
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.text>{{ __('Aucune alerte : tous les fichiers vérifiés correspondent à leur empreinte.') }}</flux:callout.text>
                </flux:callout>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="{{ $enTeteTableau }}">
                            <tr>
                                <th class="py-3 pl-4 pr-3">{{ __('Courrier') }}</th>
                                <th class="py-3 pr-3">{{ __('Fichier') }}</th>
                                <th class="py-3 pr-3">{{ __('État') }}</th>
                                <th class="whitespace-nowrap py-3 pr-4">{{ __('Constaté le') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($integrite['alertes'] as $alerte)
                                <tr wire:key="alerte-{{ $alerte['empreinte']->id }}">
                                    <td class="py-4 pl-4 pr-3">
                                        @if ($alerte['courrier'] && ! $alerte['courrier']->trashed())
                                            <flux:link :href="route('courriers.show', $alerte['courrier']->id)" wire:navigate>{{ $alerte['courrier']->numero_reference }}</flux:link>
                                        @else
                                            {{ $alerte['courrier']?->numero_reference ?? '—' }}
                                        @endif
                                    </td>
                                    <td class="max-w-80 truncate py-4 pr-3 text-zinc-500" title="{{ $alerte['empreinte']->chemin }}">{{ $alerte['empreinte']->chemin }}</td>
                                    <td class="py-4 pr-3">
                                        <span class="inline-flex items-center gap-1 rounded-md bg-brand-danger-light px-2 py-0.5 text-xs font-semibold text-brand-danger-dark">
                                            <flux:icon.exclamation-triangle variant="micro" class="size-3.5" />
                                            {{ $alerte['empreinte']->statut === App\Models\EmpreinteFichier::STATUT_MANQUANT ? __('Introuvable') : __('Altéré') }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap py-4 pr-4 tabular-nums">{{ $alerte['empreinte']->verifie_le?->format('d/m/Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- ===== Conservation des archives ===== --}}
    @if ($onglet === 'conservation')
        <div class="{{ $carte }} space-y-4">
            <div>
                <flux:heading level="2">{{ __('Durées de conservation') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">{{ __('À partir de l\'archivage. Une archive arrivée à échéance n\'est jamais détruite automatiquement : elle apparaît ci-dessous pour décision.') }}</flux:text>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <div class="w-40">
                    <flux:input type="number" min="1" max="100" wire:model="dureeDefaut" :label="__('Par défaut (années)')" :disabled="! $peutGerer" />
                </div>
                @if ($peutGerer)
                    <flux:button variant="outline" wire:click="enregistrerDureeDefaut">{{ __('Enregistrer') }}</flux:button>
                @endif
            </div>

            @if ($this->durees->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="{{ $enTeteTableau }}">
                            <tr>
                                <th class="py-3 pl-4 pr-3">{{ __('Type de document') }}</th>
                                <th class="py-3 pr-3 text-right">{{ __('Durée') }}</th>
                                <th class="relative w-px py-3 pr-4"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->durees as $duree)
                                <tr wire:key="duree-{{ $duree->id }}">
                                    <td class="py-4 pl-4 pr-3">{{ $duree->type_document }}</td>
                                    <td class="py-4 pr-3 text-right tabular-nums">{{ trans_choice(':n an|:n ans', $duree->annees, ['n' => $duree->annees]) }}</td>
                                    <td class="py-4 pr-4 text-center">
                                        @if ($peutGerer)
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="supprimerDuree({{ $duree->id }})" :aria-label="__('Retirer')" />
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($peutGerer)
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-full sm:w-72">
                        <flux:select wire:model="nouveauType" :label="__('Durée particulière pour un type')">
                            <flux:select.option value="">{{ __('Choisir un type…') }}</flux:select.option>
                            @foreach ($this->typesDocument as $type)
                                <flux:select.option value="{{ $type }}">{{ $type }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div class="w-32">
                        <flux:input type="number" min="1" max="100" wire:model="nouvellesAnnees" :label="__('Années')" />
                    </div>
                    <flux:button variant="outline" icon="plus" wire:click="ajouterDuree">{{ __('Ajouter') }}</flux:button>
                </div>
            @endif
        </div>

        @if ($this->archivesSansEcheance > 0)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>
                    {{ trans_choice(':n archive n\'a pas encore d\'échéance de conservation (archivée avant cette fonctionnalité, ou recalcul de la nuit manqué).|:n archives n\'ont pas encore d\'échéance de conservation (archivées avant cette fonctionnalité, ou recalcul de la nuit manqué).', $this->archivesSansEcheance, ['n' => $this->archivesSansEcheance]) }}
                </flux:callout.text>
                @if ($peutGerer)
                    <x-slot name="actions">
                        <flux:button size="sm" variant="outline" icon="arrow-path" wire:click="recalculerEcheances">{{ __('Recalculer maintenant') }}</flux:button>
                    </x-slot>
                @endif
            </flux:callout>
        @endif

        <div class="{{ $carte }} space-y-3">
            <flux:heading level="2">{{ __('Archives arrivées à échéance') }}</flux:heading>
            @if ($this->archivesEchues->isEmpty())
                <flux:text class="text-sm text-zinc-500">{{ __('Aucune archive n\'a atteint son échéance de conservation.') }}</flux:text>
                @if ($this->prochainesEcheances->isNotEmpty())
                    <flux:text class="text-sm">{{ __('Prochaine échéance : :ref le :date.', ['ref' => $this->prochainesEcheances->first()->numero_reference, 'date' => $this->prochainesEcheances->first()->conserver_jusqua->format('d/m/Y')]) }}</flux:text>
                @endif
            @else
                @include('partials.securite-audit-archives', ['archives' => $this->archivesEchues, 'gelees' => false])
                {{ $this->archivesEchues->links() }}
            @endif
        </div>

        <div class="{{ $carte }} space-y-3">
            <flux:heading level="2">{{ __('Sous gel juridique') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500">{{ __('Une archive sous gel juridique (litige, contrôle) ne peut pas être détruite, même après son échéance.') }}</flux:text>
            @if ($this->archivesGelees->isEmpty())
                <flux:text class="text-sm text-zinc-500">{{ __('Aucune archive sous gel juridique.') }}</flux:text>
            @else
                @include('partials.securite-audit-archives', ['archives' => $this->archivesGelees, 'gelees' => true])
            @endif
        </div>

        <div class="{{ $carte }} space-y-3">
            <flux:heading level="2">{{ __('Registre des destructions') }}</flux:heading>
            @if ($this->registreDestructions->isEmpty())
                <flux:text class="text-sm text-zinc-500">{{ __('Aucune archive détruite.') }}</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="{{ $enTeteTableau }}">
                            <tr>
                                <th class="whitespace-nowrap py-3 pl-4 pr-3">{{ __('Date') }}</th>
                                <th class="py-3 pr-3">{{ __('Courrier') }}</th>
                                <th class="py-3 pr-3">{{ __('Décidé par') }}</th>
                                <th class="py-3 pr-4">{{ __('Motif') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->registreDestructions as $ligne)
                                <tr wire:key="destruction-{{ $ligne->id }}">
                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 tabular-nums">{{ $ligne->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="py-4 pr-3">{{ $ligne->courrier?->numero_reference }} — {{ $ligne->courrier?->type_document }}</td>
                                    <td class="py-4 pr-3">{{ $ligne->auteur?->name ?? '—' }}</td>
                                    <td class="py-4 pr-4 text-zinc-500">{{ $ligne->commentaire }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($peutGerer)
            <flux:modal name="action-conservation" class="w-full max-w-lg">
                @if ($courrier = $this->courrierAction)
                    <form wire:submit="executerAction" class="space-y-4">
                        <div>
                            <flux:heading size="lg">
                                {{ match ($actionConservation) {
                                    'prolonger' => __('Prolonger la conservation'),
                                    'geler' => __('Placer sous gel juridique'),
                                    'degeler' => __('Lever le gel juridique'),
                                    'detruire' => __('Détruire l\'archive'),
                                    default => '',
                                } }}
                            </flux:heading>
                            <flux:text class="mt-1">{{ $courrier->numero_reference }} — {{ $courrier->objet }}</flux:text>
                        </div>

                        @if ($actionConservation === 'detruire')
                            <flux:callout variant="danger" icon="exclamation-triangle">
                                <flux:callout.text>{{ __('Le document principal, sa copie d\'archive et ses pièces jointes seront supprimés définitivement du stockage et de la sauvegarde. La fiche et son historique sont conservés comme preuve de la destruction.') }}</flux:callout.text>
                            </flux:callout>
                        @endif

                        @if ($actionConservation === 'prolonger')
                            <flux:input type="number" min="1" max="50" wire:model="anneesProlongation" :label="__('Prolonger de (années)')" />
                        @endif

                        <flux:textarea wire:model="motif" rows="3" :label="__('Motif')" :placeholder="__('Obligatoire, inscrit dans l\'historique du courrier')" />

                        @if ($actionConservation === 'detruire')
                            <flux:input wire:model="confirmation" :label="__('Pour confirmer, saisissez la référence :ref', ['ref' => $courrier->numero_reference])" autocomplete="off" />
                        @endif

                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Annuler') }}</flux:button>
                            </flux:modal.close>
                            <flux:button type="submit" :variant="$actionConservation === 'detruire' ? 'danger' : 'primary'">{{ __('Confirmer') }}</flux:button>
                        </div>
                    </form>
                @endif
            </flux:modal>
        @endif
    @endif

    {{-- ===== Stockage (2026-11-09, demande « on doit aussi pouvoir voir
         l'espace de stockage ») : calculé en file d'attente
         (CalculerOccupationStockageJob), jamais pendant l'affichage. ===== --}}
    @if ($onglet === 'stockage')
        @php
            $stockage = $this->stockage;
            $dernier = $stockage['dernier'];
            $copiePc = $this->sauvegardeLocale;
        @endphp

        {{-- 2026-11-11 (« je les veux aussi dans le pc … si un jour l'appli
             crash on pourra récupérer les doc ») — copie de secours rangée
             par dossier, ZIP chiffrés (App\Services\SauvegardeLocale). --}}
        <div class="{{ $carte }} mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-accent/10 text-brand-accent">
                    <flux:icon.computer-desktop class="size-5" />
                </div>
                <div class="min-w-0 space-y-1">
                    <flux:heading level="2">{{ __('Copie de secours sur le PC, rangée par dossier') }}</flux:heading>
                    @if (! $copiePc['active'])
                        <flux:text class="text-sm">{{ __('Désactivée : renseigner GEC_SAUVEGARDE_DOSSIER (dossier du PC) et GEC_SAUVEGARDE_MOT_DE_PASSE dans le fichier .env.') }}</flux:text>
                    @else
                        <flux:text class="break-all text-sm">{{ $copiePc['dossier'] }}</flux:text>
                        @if ($copiePc['le'])
                            <flux:text class="text-sm">
                                {{ __('Mise à jour le :quand — :n courrier(s) copiés ou à jour.', ['quand' => $copiePc['le']->format('d/m/Y à H:i'), 'n' => $copiePc['etat']['ecrits'] + $copiePc['etat']['deplaces'] + $copiePc['etat']['inchanges']]) }}
                            </flux:text>
                            <flux:text class="text-sm">
                                {{ trans_choice(':n copie personnelle (mot de passe de sauvegarde de chaque personne)|:n copies personnelles (mot de passe de sauvegarde de chaque personne)', $copiePc['etat']['personnes'] ?? 0, ['n' => $copiePc['etat']['personnes'] ?? 0]) }}
                            </flux:text>
                            @if ($copiePc['etat']['erreurs'] > 0)
                                <flux:text class="flex items-center gap-1 text-sm font-medium text-brand-warning-dark">
                                    <flux:icon.exclamation-triangle variant="micro" class="size-4" />
                                    {{ trans_choice(':n courrier non copié (voir le journal)|:n courriers non copiés (voir le journal)', $copiePc['etat']['erreurs'], ['n' => $copiePc['etat']['erreurs']]) }}
                                </flux:text>
                            @endif
                        @else
                            <flux:text class="text-sm">{{ __('Première copie au prochain passage (chaque heure).') }}</flux:text>
                        @endif
                        <flux:text class="text-xs text-zinc-500">{{ __('Un ZIP protégé par mot de passe par courrier ; s\'ouvre sans GEC avec 7-Zip ou WinRAR.') }}</flux:text>
                    @endif
                </div>
            </div>
            @if ($copiePc['active'])
                <flux:button variant="outline" icon="arrow-path" wire:click="lancerSauvegardeLocale" class="shrink-0">{{ __('Mettre à jour maintenant') }}</flux:button>
            @endif
        </div>

        @if (! $dernier)
            <div class="{{ $carte }}">
                <flux:text>{{ __('Premier calcul de l\'occupation du stockage en cours : rouvrez cet onglet dans un instant.') }}</flux:text>
            </div>
        @else
            @php
                $taille = fn ($octets) => App\Models\OccupationStockage::taille($octets);
                $disqueUtilise = $dernier->disque_total ? $dernier->disque_total - $dernier->disque_libre : null;
                $partDisque = $dernier->disque_total ? $disqueUtilise / $dernier->disque_total : null;
                $partLibre = $dernier->disque_total ? $dernier->disque_libre / $dernier->disque_total : null;
            @endphp

            <div class="grid gap-4 lg:grid-cols-3">
                {{-- Espace utilisé par GEC --}}
                <div class="{{ $carte }} space-y-1">
                    <flux:text class="text-sm text-zinc-500">{{ __('Espace utilisé par les documents') }}</flux:text>
                    <div class="text-3xl font-semibold tabular-nums text-brand-text-primary">{{ $taille($dernier->octets) }}</div>
                    <flux:text class="text-sm">{{ trans_choice(':n fichier|:n fichiers', $dernier->fichiers, ['n' => $dernier->fichiers]) }}</flux:text>
                </div>

                {{-- Disque du stockage --}}
                <div class="{{ $carte }} space-y-2">
                    <flux:text class="text-sm text-zinc-500">{{ __('Disque du stockage') }}</flux:text>
                    @if ($partDisque === null)
                        <flux:text class="text-sm">{{ __('Capacité non mesurée : stockage cloud (capacité gérée par le fournisseur) ou dossier du disque non configuré (STOCKAGE_CHEMIN_DISQUE).') }}</flux:text>
                    @else
                        <div class="text-3xl font-semibold tabular-nums text-brand-text-primary">{{ $taille($dernier->disque_libre) }} <span class="text-base font-normal text-brand-text-secondary">{{ __('libres') }}</span></div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($partDisque * 100) }}" aria-label="{{ __('Disque utilisé') }}">
                            <div @class([
                                'h-2 rounded-r-[4px]',
                                'bg-brand-danger' => $partLibre < 0.1,
                                'bg-brand-warning' => $partLibre >= 0.1 && $partLibre < 0.2,
                                'bg-brand-accent' => $partLibre >= 0.2,
                            ]) style="width: {{ max(1, round($partDisque * 100, 1)) }}%"></div>
                        </div>
                        <flux:text class="text-sm">{{ __(':utilise utilisés sur :total (:pct %)', ['utilise' => $taille($disqueUtilise), 'total' => $taille($dernier->disque_total), 'pct' => number_format($partDisque * 100, 0, ',', ' ')]) }}</flux:text>
                        @if ($partLibre < 0.2)
                            <flux:text @class(['flex items-center gap-1 text-sm font-medium', 'text-brand-danger-dark' => $partLibre < 0.1, 'text-brand-warning-dark' => $partLibre >= 0.1])>
                                <flux:icon.exclamation-triangle variant="micro" class="size-4" />
                                {{ $partLibre < 0.1 ? __('Disque presque plein : prévoir de l\'espace sans attendre.') : __('Moins de 20 % d\'espace libre.') }}
                            </flux:text>
                        @endif
                    @endif
                </div>

                {{-- Copie de secours --}}
                <div class="{{ $carte }} space-y-1">
                    <flux:text class="text-sm text-zinc-500">{{ __('Copie de secours') }}</flux:text>
                    @if ($dernier->sauvegarde_fichiers === null)
                        <flux:text class="text-sm">{{ __('Aucun stockage de secours configuré.') }}</flux:text>
                    @else
                        <div class="text-3xl font-semibold tabular-nums text-brand-text-primary">{{ $taille($dernier->sauvegarde_octets) }}</div>
                        <flux:text class="text-sm">{{ trans_choice(':n fichier|:n fichiers', $dernier->sauvegarde_fichiers, ['n' => $dernier->sauvegarde_fichiers]) }}</flux:text>
                        @if ($dernier->sauvegarde_manquants > 0)
                            <flux:text class="flex items-center gap-1 text-sm font-medium text-brand-warning-dark">
                                <flux:icon.exclamation-triangle variant="micro" class="size-4" />
                                {{ trans_choice(':n fichier absent de la sauvegarde|:n fichiers absents de la sauvegarde', $dernier->sauvegarde_manquants, ['n' => $dernier->sauvegarde_manquants]) }}
                            </flux:text>
                        @else
                            <flux:text class="flex items-center gap-1 text-sm font-medium text-brand-success-dark">
                                <flux:icon.check-circle variant="micro" class="size-4" />
                                {{ __('Tous les fichiers sont sauvegardés') }}
                            </flux:text>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Répartition par type : une seule série, valeur écrite au bout. --}}
            <div class="{{ $carte }} space-y-3">
                <flux:heading level="2">{{ __('Répartition par type de fichier') }}</flux:heading>
                <ul class="@container space-y-1">
                    @foreach (App\Models\OccupationStockage::TYPES as $cle => $libelle)
                        @php $ligne = $dernier->par_type[$cle] ?? ['fichiers' => 0, 'octets' => 0]; @endphp
                        @continue($ligne['fichiers'] === 0)
                        <li wire:key="type-{{ $cle }}" class="grid grid-cols-1 items-center gap-1 py-1 text-sm @min-[30rem]:grid-cols-[minmax(0,16rem)_1fr] @min-[30rem]:gap-3">
                            <span class="truncate text-brand-text-secondary" title="{{ __($libelle) }}">{{ __($libelle) }}</span>
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="block h-3 rounded-r-[4px] bg-brand-accent" style="width: calc((100% - 9rem) * {{ $dernier->octets > 0 ? round($ligne['octets'] / $dernier->octets, 4) : 0 }}); min-width: 2px"></span>
                                <span class="whitespace-nowrap text-xs tabular-nums text-brand-text-primary">
                                    <span class="font-medium">{{ $taille($ligne['octets']) }}</span>
                                    <span class="text-brand-text-secondary">· {{ $ligne['fichiers'] }}</span>
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Évolution : un calcul par nuit. --}}
            <div class="{{ $carte }} space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:heading level="2">{{ __('Évolution') }}</flux:heading>
                        <flux:text class="text-sm text-zinc-500">{{ __('Un calcul chaque nuit ; dernier le :quand.', ['quand' => $dernier->calcule_le->format('d/m/Y à H:i')]) }}</flux:text>
                    </div>
                    <flux:button variant="outline" icon="arrow-path" wire:click="calculerStockage">{{ __('Recalculer maintenant') }}</flux:button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b-2 border-brand-navy text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-400 dark:text-zinc-400">
                            <tr>
                                <th class="whitespace-nowrap py-3 pl-4 pr-3">{{ __('Date') }}</th>
                                <th class="py-3 pr-3 text-right">{{ __('Espace utilisé') }}</th>
                                <th class="py-3 pr-3 text-right">{{ __('Variation') }}</th>
                                <th class="py-3 pr-4 text-right">{{ __('Fichiers') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($stockage['historique'] as $index => $calcul)
                                @php $precedent = $stockage['historique'][$index + 1] ?? null; @endphp
                                <tr wire:key="calcul-{{ $calcul->id }}">
                                    <td class="whitespace-nowrap py-3 pl-4 pr-3 tabular-nums">{{ $calcul->calcule_le->format('d/m/Y H:i') }}</td>
                                    <td class="whitespace-nowrap py-3 pr-3 text-right tabular-nums">{{ $taille($calcul->octets) }}</td>
                                    <td class="whitespace-nowrap py-3 pr-3 text-right tabular-nums text-brand-text-secondary">
                                        @if ($precedent)
                                            {{ ($calcul->octets >= $precedent->octets ? '+ ' : '− ').$taille(abs($calcul->octets - $precedent->octets)) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums">{{ $calcul->fichiers }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif

    {{-- ===== Système ===== --}}
    @if ($onglet === 'systeme')
        @php $systeme = $this->systeme; @endphp
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="{{ $carte }} space-y-2">
                <flux:heading level="2">{{ __('Planificateur') }}</flux:heading>
                @if ($systeme['planificateurActif'])
                    <flux:callout variant="success" icon="check-circle">
                        <flux:callout.text>{{ __('Actif — dernier passage :quand.', ['quand' => $systeme['battement']->diffForHumans()]) }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Le planificateur ne tourne pas') }}</flux:callout.heading>
                        <flux:callout.text>{{ $systeme['battement'] ? __('Dernier passage :quand.', ['quand' => $systeme['battement']->diffForHumans()]) : __('Aucun passage enregistré.') }} {{ __('Alertes SLA, relances, archivage, statistiques, contrôles d\'intégrité et copies PDF/A sont arrêtés.') }}</flux:callout.text>
                    </flux:callout>
                @endif
                <flux:text class="text-sm text-zinc-500">{{ __('En production : une tâche planifiée qui lance « php artisan schedule:run » chaque minute (cron sous Linux, Planificateur de tâches sous Windows). En local : « php artisan schedule:work ».') }}</flux:text>
            </div>

            <div class="{{ $carte }} space-y-2">
                <flux:heading level="2">{{ __('Copie de secours des fichiers') }}</flux:heading>
                @if ($systeme['sauvegarde'] === 'separee')
                    <flux:callout variant="success" icon="check-circle">
                        <flux:callout.text>{{ __('Chaque fichier est copié vers un stockage de secours distinct du stockage principal.') }}</flux:callout.text>
                    </flux:callout>
                @elseif ($systeme['sauvegarde'] === 'meme_serveur')
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Copie de secours sur le même serveur') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Le stockage de secours pointe vers le même serveur que les originaux : une panne de ce serveur emporterait les deux. En production, configurez un stockage de secours distinct (variables AWS_BACKUP_* du fichier .env).') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Aucune copie de secours') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Aucun stockage de secours n\'est configuré (variables AWS_BACKUP_* du fichier .env).') }}</flux:callout.text>
                    </flux:callout>
                @endif
            </div>

            <div class="{{ $carte }} space-y-2">
                <flux:heading level="2">{{ __('Copies d\'archive PDF/A') }}</flux:heading>
                @if (! $systeme['ghostscript'])
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.text>{{ __('Ghostscript n\'est pas configuré (GHOSTSCRIPT_PATH) : aucune copie PDF/A ne peut être produite.') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:text class="text-sm">{{ trans_choice(':n archive PDF en attente de sa copie PDF/A (traitées chaque heure).|:n archives PDF en attente de leur copie PDF/A (traitées chaque heure).', $systeme['archivesSansPdfa'], ['n' => $systeme['archivesSansPdfa']]) }}</flux:text>
                @endif
                <flux:text class="text-sm text-zinc-500">{{ __('Les originaux ne sont jamais modifiés : la copie PDF/A-2b est enregistrée à côté, avec sa propre empreinte.') }}</flux:text>
            </div>

            {{-- 2026-11-09 — chiffrement des fichiers au repos (AES-256). --}}
            <div class="{{ $carte }} space-y-2">
                <flux:heading level="2">{{ __('Chiffrement des fichiers') }}</flux:heading>
                @if (! $systeme['cleChiffrement'])
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Aucune clé de chiffrement') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Les nouveaux fichiers sont refusés tant que la clé n\'est pas créée (php artisan gec:cle-fichiers).') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout variant="success" icon="lock-closed">
                        <flux:callout.text>{{ __('Chaque nouveau fichier est chiffré (AES-256) sur le stockage et sur la copie de secours.') }}</flux:callout.text>
                    </flux:callout>
                    @php $bilan = $systeme['chiffrementExistants']; @endphp
                    <flux:text class="text-sm">
                        @if ($bilan)
                            {{ __('Dernière conversion le :le : :chiffres fichier(s) chiffré(s), :deja déjà chiffré(s), :echecs échec(s).', ['le' => \Illuminate\Support\Carbon::parse($bilan['le'])->format('d/m/Y H:i'), 'chiffres' => $bilan['chiffres'], 'deja' => $bilan['deja_chiffres'], 'echecs' => $bilan['echecs']]) }}
                        @else
                            {{ __('Les fichiers déposés avant le chiffrement n\'ont pas encore été convertis.') }}
                        @endif
                    </flux:text>
                    @can('gererConservation', App\Models\Courrier::class)
                        <flux:button size="sm" variant="outline" icon="lock-closed" wire:click="chiffrerExistants">{{ __('Chiffrer les fichiers existants') }}</flux:button>
                    @endcan
                @endif
                <flux:text class="text-sm text-zinc-500">{{ __('La clé (GEC_FICHIERS_CLE du fichier .env) doit être conservée en lieu sûr, hors du serveur : sans elle, documents et sauvegardes sont définitivement illisibles.') }}</flux:text>
            </div>
        </div>
    @endif
</section>
