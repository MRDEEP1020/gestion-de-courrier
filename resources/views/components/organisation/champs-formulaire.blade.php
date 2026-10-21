{{-- Champs communs aux 4 modales "Ajouter/Modifier" de l'Organisation
     (2026-10-06, "seperate the site modal, department,service and sou
     service modal") — simple composant Blade (pas Livewire, donc aucune
     limite d'imbrication, Règle n°2) : chaque modale appelante passe
     seulement si le pont "Service réel lié" doit s'afficher. --}}
@props(['avecServicePont' => false, 'creationAuto' => false, 'responsables', 'servicesReels'])

<flux:input wire:model="nomNoeud" :label="__('Nom')" required />
<flux:input wire:model="codeNoeud" :label="__('Code')" placeholder="{{ __('ex. SIN-SANTE') }}" />

<flux:select wire:model="responsableIdNoeud" :label="__('Responsable')" placeholder="{{ __('— Aucun pour l\'instant —') }}">
    <flux:select.option value="">{{ __('— Aucun pour l\'instant —') }}</flux:select.option>
    @foreach ($responsables as $u)
        <flux:select.option value="{{ $u->id }}">{{ $u->name }}</flux:select.option>
    @endforeach
</flux:select>

@if ($avecServicePont)
    <div>
        <flux:select wire:model="servicePontNoeud" :label="__('Service réel lié')">
            <flux:select.option value="">{{ $creationAuto ? __('— Créer automatiquement —') : __('— Aucun —') }}</flux:select.option>
            @foreach ($servicesReels as $s)
                <flux:select.option value="{{ $s->id }}">{{ $s->nom }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:text class="mt-1 text-xs text-zinc-500">
            @if ($creationAuto)
                {{ __('Laissez "Créer automatiquement" pour que ce service reçoive des courriers (routage DGA, règles, recherche). Nom et responsable seront repris ; choisissez un service existant seulement pour le regrouper avec lui.') }}
            @else
                {{ __('Relie cette entité à un service réel — nécessaire pour qu\'elle soit sélectionnable lors d\'un transfert de courrier.') }}
            @endif
        </flux:text>
    </div>
@endif

<flux:textarea wire:model="descriptionNoeud" :label="__('Description')" rows="2" />
