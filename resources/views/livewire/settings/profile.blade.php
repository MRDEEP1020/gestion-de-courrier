{{-- 2026-10-26 — « Mon profil » sur le modèle de la modale « Modifier
     l'utilisateur » (Utilisateurs & Accès) : fiche à gauche, « Aperçu du
     compte » à droite. Seule la photo est modifiable par l'utilisateur ; nom,
     prénom, email, téléphone et tout le reste sont gérés par l'administrateur
     et affichés en lecture seule (voir App\Livewire\Settings\Profile). --}}
@php
    $utilisateur = auth()->user();
@endphp

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Mon profil') }}</flux:heading>

    <x-settings.layout :heading="__('Mon profil')" :subheading="__('Vous pouvez changer votre photo. Le reste de votre compte est géré par l\'administrateur.')" :large="true">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_260px]">
            <div class="space-y-6">
                {{-- Seule partie modifiable par l'utilisateur, enregistrée
                     dès le choix du fichier (Profile::updatedPhoto()). --}}
                <div class="rounded-xl border border-brand-border p-5 dark:border-zinc-700">
                    <div class="mb-4 flex items-center gap-2">
                        <flux:icon.camera class="size-4 text-brand-accent" />
                        <flux:heading level="3">{{ __('Photo de profil') }}</flux:heading>
                    </div>
                    <div class="flex items-center gap-4">
                        <flux:avatar size="xl" circle :initials="$utilisateur->initials()" :src="$utilisateur->photoUrl()" />
                        <div class="space-y-1">
                            <input type="file" wire:model="photo" accept=".jpg,.jpeg,.png" class="hidden" id="profil-photo" />
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:button size="sm" icon="arrow-up-tray" x-on:click="document.getElementById('profil-photo').click()">{{ __('Changer la photo') }}</flux:button>
                                @if ($utilisateur->photo_path)
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="retirerPhoto" class="text-brand-danger!">{{ __('Retirer') }}</flux:button>
                                @endif
                            </div>
                            <div wire:loading wire:target="photo" class="text-xs text-zinc-500">{{ __('Envoi en cours…') }}</div>
                            <div class="text-xs text-zinc-500">{{ __('Format recommandé : JPG, PNG') }} · {{ __('Taille max : 2 Mo') }}</div>
                            <flux:error name="photo" />
                        </div>
                    </div>
                </div>

                {{-- Lecture seule : coordonnées (modifiables par l'administrateur seulement) --}}
                <div class="space-y-4 rounded-xl border border-brand-border bg-brand-surface-soft p-5 dark:border-zinc-700 dark:bg-zinc-800/40">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <flux:icon.identification class="size-4 text-brand-accent" />
                            <flux:heading level="3">{{ __('Informations générales') }}</flux:heading>
                        </div>
                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-200/70 px-2 py-0.5 text-xs font-medium text-brand-text-secondary dark:bg-zinc-700 dark:text-zinc-300">
                            <flux:icon.lock-closed variant="micro" class="size-3.5" />
                            {{ __('Géré par l\'administrateur') }}
                        </span>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input :label="__('Nom')" :value="$this->nomPrenom['nom']" disabled class:input="bg-brand-disabled-bg! text-brand-text-secondary! dark:bg-zinc-800!" />
                        <flux:input :label="__('Prénom')" :value="$this->nomPrenom['prenom'] ?: '—'" disabled class:input="bg-brand-disabled-bg! text-brand-text-secondary! dark:bg-zinc-800!" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <flux:input :label="__('Email')" :value="$utilisateur->email" disabled class:input="bg-brand-disabled-bg! text-brand-text-secondary! dark:bg-zinc-800!" />
                            @if ($this->hasUnverifiedEmail)
                                <flux:text class="mt-2 text-sm">
                                    {{ __('Your email address is unverified.') }}
                                    <flux:link class="cursor-pointer text-sm" wire:click.prevent="resendVerificationNotification">
                                        {{ __('Click here to re-send the verification email.') }}
                                    </flux:link>
                                </flux:text>
                            @endif
                        </div>
                        <flux:input :label="__('Téléphone')" :value="$utilisateur->telephone ?: '—'" disabled class:input="bg-brand-disabled-bg! text-brand-text-secondary! dark:bg-zinc-800!" />
                    </div>
                </div>

                {{-- Lecture seule : géré par l'administrateur --}}
                <div class="space-y-4 rounded-xl border border-brand-border bg-brand-surface-soft p-5 dark:border-zinc-700 dark:bg-zinc-800/40">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <flux:icon.building-office-2 class="size-4 text-brand-accent" />
                            <flux:heading level="3">{{ __('Service & accès') }}</flux:heading>
                        </div>
                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-200/70 px-2 py-0.5 text-xs font-medium text-brand-text-secondary dark:bg-zinc-700 dark:text-zinc-300">
                            <flux:icon.lock-closed variant="micro" class="size-3.5" />
                            {{ __('Géré par l\'administrateur') }}
                        </span>
                    </div>

                    <flux:input :label="__('Entité')" :value="$this->cheminEntite ?? __('Aucune entité')" disabled class:input="bg-brand-disabled-bg! text-brand-text-secondary! dark:bg-zinc-800!" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input :label="__('Profil')" :value="$utilisateur->profil?->nom ?? '—'" disabled class:input="bg-brand-disabled-bg! text-brand-text-secondary! dark:bg-zinc-800!" />
                        {{-- « Poste occupé » : la clé « Poste » désigne déjà le mode de réception (« Postal mail »). --}}
                        <flux:input :label="__('Poste occupé')" :value="$utilisateur->poste ?: '—'" disabled class:input="bg-brand-disabled-bg! text-brand-text-secondary! dark:bg-zinc-800!" />
                    </div>
                    <flux:input :label="__('Niveau de confidentialité')" :value="__('Niveau :n', ['n' => $utilisateur->niveau_confidentialite])" :description="__('Définit le niveau d\'accès aux informations confidentielles.')" disabled class:input="bg-brand-disabled-bg! text-brand-text-secondary! dark:bg-zinc-800!" />

                    <div>
                        <flux:text class="mb-2 text-sm font-medium">{{ __('Destinataires de transfert') }}</flux:text>
                        @if ($this->destinatairesTransfert->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($this->destinatairesTransfert as $nomDestinataire)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-xs font-medium text-brand-text-secondary ring-1 ring-brand-border dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-700">
                                        <flux:icon.paper-airplane variant="micro" class="size-3.5" />{{ $nomDestinataire }}
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <flux:text class="text-sm text-zinc-500">{{ __('Aucun destinataire de transfert attribué.') }}</flux:text>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Aperçu du compte — même carte que dans Utilisateurs & Accès,
                 reflète l'état ENREGISTRÉ du compte. --}}
            <div class="space-y-4 lg:sticky lg:top-20 lg:self-start">
                <div class="rounded-xl border border-brand-border p-4 dark:border-zinc-700">
                    <div class="flex items-center gap-3">
                        <flux:avatar circle :initials="$utilisateur->initials()" :src="$utilisateur->photoUrl()" />
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $utilisateur->name }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ $utilisateur->profil?->nom }}</div>
                        </div>
                    </div>
                    <span class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium {{ $utilisateur->actif ? 'text-brand-success' : 'text-zinc-500' }}">
                        <span class="size-2 rounded-full {{ $utilisateur->actif ? 'bg-brand-success' : 'bg-zinc-400' }}"></span>
                        {{ $utilisateur->actif ? __('Actif') : __('Désactivé') }}
                    </span>

                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex items-start gap-2">
                            <flux:icon.envelope class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ __('Email') }}</dt><dd class="truncate">{{ $utilisateur->email }}</dd></div>
                        </div>
                        @if ($utilisateur->telephone)
                            <div class="flex items-start gap-2">
                                <flux:icon.phone class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ __('Téléphone') }}</dt><dd>{{ $utilisateur->telephone }}</dd></div>
                            </div>
                        @endif
                        <div class="flex items-start gap-2">
                            <flux:icon.building-office-2 class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ __('Entité') }}</dt><dd class="truncate" title="{{ $this->cheminEntite }}">{{ $utilisateur->entitePrincipale()?->name ?? '—' }}</dd></div>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.lock-closed class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0">
                                <dt class="text-xs text-zinc-500">{{ __('Niveau de confidentialité') }}</dt>
                                <dd><span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match (true) {
                                    $utilisateur->niveau_confidentialite >= 4 => 'bg-brand-danger/10 text-brand-danger',
                                    $utilisateur->niveau_confidentialite >= 2 => 'bg-brand-warning/10 text-brand-warning',
                                    default => 'bg-zinc-100 text-zinc-600',
                                } }}">{{ __('Niveau :n', ['n' => $utilisateur->niveau_confidentialite]) }}</span></dd>
                            </div>
                        </div>
                        <div class="flex items-start gap-2">
                            <flux:icon.clock class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                            <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ __('Dernière connexion') }}</dt><dd>{{ $utilisateur->derniere_connexion_le?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                        </div>
                    </dl>
                </div>

                <flux:callout variant="secondary" icon="information-circle" class="text-sm">
                    {{ __('Pour modifier vos coordonnées, votre entité, votre profil ou votre niveau de confidentialité, contactez l\'administrateur.') }}
                </flux:callout>
            </div>
        </div>

        {{-- « Supprimer mon compte » (kit de démarrage) retiré le 2026-10-26 :
             dans GEC, c'est l'administrateur qui désactive un compte
             (Utilisateurs & Accès) ; un utilisateur présent dans
             l'historique des courriers ne doit jamais disparaître (Règle n°5). --}}
    </x-settings.layout>
</section>
