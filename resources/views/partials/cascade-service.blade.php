{{-- Module 1/4 (2026-10-23) — choix du service destinataire d'un transfert,
     cascade Site → Direction → Département → Service/Unité. Utilisé par la
     fiche, "Mes courriers" et "Tous les courriers" ; propriétés et listes
     fournies par App\Concerns\ChoixServiceCascade. Site et Direction sont
     optionnels (des Départements réels existent à la racine) : option vide
     RÉELLE sur chaque niveau et wire:key par parent, sinon un sélecteur
     masqué puis ré-affiché pouvait montrer un choix différent de la valeur
     réellement envoyée (bug du 2026-09-23). --}}
<div class="space-y-3">
    {{-- 2026-10-30 — Site déduit de la place de la personne dans
         l'organisation (ChoixServiceCascade::siteAutomatique()), appliqué
         côté serveur et plus affiché du tout (« instead of displaying it to
         them, remove it ») ; le « Destinataire » ci-dessous dit toujours où
         part le courrier. Menu conservé seulement sans Site déductible. --}}
    @unless ($this->siteAutomatique)
        <flux:select wire:model.live="siteSelectionneId" :label="__('Site')" placeholder="{{ __('— Aucun (racine) —') }}">
            <flux:select.option value="">{{ __('— Aucun (racine) —') }}</flux:select.option>
            @foreach ($this->sitesDisponibles as $site)
                <flux:select.option value="{{ $site->id }}">{{ $site->name }}</flux:select.option>
            @endforeach
        </flux:select>
    @endunless

    @if ($this->directionsDisponibles->isNotEmpty())
        <div wire:key="cascade-direction-{{ $siteSelectionneId ?? 'racine' }}">
            <flux:select wire:model.live="directionSelectionneeId" :label="__('Direction')" placeholder="{{ __('— Aucune (direct) —') }}">
                <flux:select.option value="">{{ __('— Aucune (direct) —') }}</flux:select.option>
                @foreach ($this->directionsDisponibles as $direction)
                    <flux:select.option value="{{ $direction->id }}">{{ $direction->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    @if ($this->departementsDisponibles->isNotEmpty())
        <div wire:key="cascade-departement-{{ $directionSelectionneeId ?? $siteSelectionneId ?? 'racine' }}">
            {{-- 2026-10-23/26 — une Direction, ou le Site lui-même (Direction
                 Générale), peut recevoir le courrier (son chef le
                 transmettra) : ce niveau devient alors facultatif. Les
                 services rattachés directement y figurent aussi. --}}
            <flux:select wire:model.live="departementSelectionneId" :label="__('Département / Service')">
                <flux:select.option value="">{{ $this->nomParentCascade ? __('— Aucun (:entite reçoit directement) —', ['entite' => $this->nomParentCascade]) : __('Choisir un département ou un service') }}</flux:select.option>
                @foreach ($this->departementsDisponibles as $departement)
                    <flux:select.option value="{{ $departement->id }}">{{ $departement->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    @if ($departementSelectionneId && $this->unitesDisponibles->isNotEmpty())
        <div wire:key="cascade-unite-{{ $departementSelectionneId }}">
            <flux:select wire:model.live="uniteSelectionneeId" :label="__('Service / Unité')">
                <flux:select.option value="">{{ __('— Aucun (:entite reçoit directement) —', ['entite' => $this->departementsDisponibles->firstWhere('id', $departementSelectionneId)?->name]) }}</flux:select.option>
                @foreach ($this->unitesDisponibles as $unite)
                    <flux:select.option value="{{ $unite->id }}">{{ $unite->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    {{-- Rappel de l'entité qui recevra le courrier et de son chef (celui
         qui devra l'accepter), selon la sélection affichée. --}}
    @if ($destinataire = $this->destinataireCascade)
        <div class="rounded-lg bg-brand-accent-soft px-3 py-2 text-sm dark:bg-zinc-800">
            {{ __('Destinataire') }} : <strong>{{ $destinataire->name }}</strong>
            — {{ $destinataire->titreDuChef() }} : {{ $destinataire->service?->responsable?->name ?? __('aucun') }}
        </div>
    @endif

    {{-- Flux affiche déjà l'erreur sous le sélecteur Service / Unité quand il
         est présent (elle apparaissait sinon deux fois) : ce message ne sert
         que lorsque ce sélecteur n'est pas affiché (aucun département choisi,
         ou département sans service en dessous). --}}
    @unless ($departementSelectionneId && $this->unitesDisponibles->isNotEmpty())
        @error('uniteSelectionneeId') <flux:text class="text-sm text-brand-danger">{{ $message }}</flux:text> @enderror
    @endunless
</div>
