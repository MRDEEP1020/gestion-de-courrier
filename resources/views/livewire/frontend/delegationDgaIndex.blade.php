{{-- Administration › Délégation DGA (2026-10-06, entretien terrain
     réceptionniste, voir DECISIONS.md "Délégation DGA/ADJ absents") —
     toggle manuel : un Administrateur ou un DGA actif désigne un
     délégataire (RH, typiquement) qui pourra valider le service à sa
     place pendant une absence simultanée du DGA et de l'Adjoint DGA. --}}
<section class="w-full">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item href="{{ route('dashboard') }}" icon="home" wire:navigate></flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Administration') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Délégation DGA') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex items-center gap-3">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-accent text-white">
            <flux:icon.arrow-right-circle class="size-5" />
        </div>
        <div>
            <flux:heading level="1">{{ __('Délégation DGA') }}</flux:heading>
            <flux:subheading>{{ __('En l\'absence simultanée du DGA et de l\'Adjoint DGA, désignez qui valide le service à leur place.') }}</flux:subheading>
        </div>
    </div>

    <flux:callout class="mt-6" icon="information-circle">
        <flux:callout.heading>{{ __('Activation manuelle uniquement') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Rien n\'est détecté automatiquement : activez une délégation avant une absence connue, et désactivez-la au retour. Le délégataire ne peut valider que les courriers adressés au DGA/ADJ qu\'il couvre — jamais au-delà.') }}
        </flux:callout.text>
    </flux:callout>

    <div class="mt-6 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading level="2">{{ __('Activer une délégation') }}</flux:heading>

        <form wire:submit="activerDelegation" class="mt-4 grid gap-4 sm:grid-cols-2">
            {{-- 2026-10-09 — retour utilisateur direct (capture d'écran) :
                 les deux champs refusaient "obligatoire" alors que l'UI
                 affichait "Admin Test" (premier utilisateur par ordre
                 alphabétique dans les deux listes) — la valeur affichée
                 n'avait jamais atteint le composant côté serveur (prouvé
                 via Livewire::test() standalone : le backend valide/crée
                 correctement dès que les propriétés sont réellement
                 définies). Contrairement à ce formulaire, TOUS les autres
                 sélecteurs d'ID importants de l'app (Site/Département/
                 Service de registrationForm.blade.php, sens, confidentialité)
                 utilisent `wire:model.live`, jamais le `wire:model` différé
                 utilisé ici — aligné sur cette convention établie,
                 + `wire:key` explicite pour exclure toute réutilisation de
                 nœud DOM d'une autre page via wire:navigate. --}}
            {{-- Pas de :placeholder (2026-10-12) — son <option disabled
                 selected> ne survit pas de façon fiable à un morph Livewire
                 quand la propriété liée est null, le <select> natif retombe
                 alors sur la première option RÉELLE du menu (visuellement
                 "choisie" alors que la propriété reste bien null côté
                 serveur) — voir le commentaire détaillé dans
                 showCourrier.blade.php "Classer dans un dossier". Première
                 option à nous, vide mais ACTIVÉE, à la place. --}}
            <flux:select wire:model.live="delegantId" wire:key="delegation-select-delegant" :label="__('DGA / Adjoint DGA absent(e)')">
                <flux:select.option value="">{{ __('— Choisir —') }}</flux:select.option>
                @foreach ($this->delegantsPotentiels as $u)
                    <flux:select.option value="{{ $u->id }}">{{ $u->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="delegataireId" wire:key="delegation-select-delegataire" :label="__('Délégataire (ex. RH)')">
                <flux:select.option value="">{{ __('— Choisir —') }}</flux:select.option>
                @foreach ($this->delegatairesPotentiels as $u)
                    <flux:select.option value="{{ $u->id }}">{{ $u->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="motif" :label="__('Motif (optionnel)')" placeholder="{{ __('ex. Congés DGA et ADJ du 10 au 20') }}" />

            <flux:input type="datetime-local" wire:model="finLe" :label="__('Fin prévue (optionnel)')" />

            <div class="sm:col-span-2">
                <flux:button type="submit" variant="primary" icon="check">{{ __('Activer') }}</flux:button>
            </div>
        </form>
    </div>

    <div class="mt-6 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading level="2">{{ __('Délégations actives') }}</flux:heading>

        @if ($this->delegationsActives->isEmpty())
            <flux:text class="mt-3 text-sm text-zinc-500">{{ __('Aucune délégation active pour l\'instant.') }}</flux:text>
        @else
            <ul class="mt-3 divide-y divide-brand-border text-sm dark:divide-zinc-700">
                @foreach ($this->delegationsActives as $delegation)
                    <li wire:key="delegation-active-{{ $delegation->id }}" class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div>
                            <span class="font-medium">{{ $delegation->delegant->name }}</span>
                            <flux:icon.arrow-right class="inline size-3 text-zinc-400" />
                            <span class="font-medium">{{ $delegation->delegataire->name }}</span>
                            <span class="text-zinc-500">
                                — {{ __('depuis le :date', ['date' => $delegation->debut_le->format('d/m/Y H:i')]) }}
                                @if ($delegation->fin_le)
                                    · {{ __('jusqu\'au :date', ['date' => $delegation->fin_le->format('d/m/Y H:i')]) }}
                                @endif
                                @if ($delegation->motif)
                                    · {{ $delegation->motif }}
                                @endif
                            </span>
                        </div>
                        <flux:button size="sm" variant="danger" wire:click="desactiverDelegation({{ $delegation->id }})" wire:confirm="{{ __('Désactiver cette délégation ?') }}">
                            {{ __('Désactiver') }}
                        </flux:button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="mt-6 rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading level="2">{{ __('Historique') }}</flux:heading>

        @if ($this->historique->isEmpty())
            <flux:text class="mt-3 text-sm text-zinc-500">{{ __('Aucune délégation désactivée pour l\'instant.') }}</flux:text>
        @else
            <ul class="mt-3 divide-y divide-brand-border text-sm dark:divide-zinc-700">
                @foreach ($this->historique as $delegation)
                    <li wire:key="delegation-historique-{{ $delegation->id }}" class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div>
                            <span class="font-medium">{{ $delegation->delegant->name }}</span>
                            <flux:icon.arrow-right class="inline size-3 text-zinc-400" />
                            <span class="font-medium">{{ $delegation->delegataire->name }}</span>
                            <span class="text-zinc-500">
                                — {{ __('du :debut au :fin', ['debut' => $delegation->debut_le->format('d/m/Y H:i'), 'fin' => $delegation->desactive_le->format('d/m/Y H:i')]) }}
                                @if ($delegation->motif)
                                    · {{ $delegation->motif }}
                                @endif
                            </span>
                        </div>
                        <flux:text class="text-xs text-zinc-500">{{ __('désactivée par :nom', ['nom' => $delegation->desactivePar?->name ?? '—']) }}</flux:text>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4">{{ $this->historique->links() }}</div>
        @endif
    </div>
</section>
