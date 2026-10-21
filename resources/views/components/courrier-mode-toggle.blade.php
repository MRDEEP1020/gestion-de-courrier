{{-- Bascule Normal / Confidentiel sur la MÊME page (2026-10-07, demande
     explicite de l'utilisateur : "it should on a same file not two
     different files" — fusion de RegistrationFormConfidentiel dans
     RegistrationForm, décision rouverte en connaissance de cause, voir
     DECISIONS.md "fusion explicitement demandée"). Plus aucune navigation
     ici : wire:click sur basculerModeConfidentiel() du composant déjà
     monté, instantané par construction. La garantie "jamais scanné" ne
     vient donc plus de la séparation en deux composants, mais du garde-fou
     explicite dans RegistrationForm::enregistrerConfidentiel()/
     numeriserAutomatique()/importerFichier() (voir leurs commentaires).
     N'apparaît que pour qui a le privilège courriers.creer_confidentiel —
     sinon le formulaire normal reste identique à aujourd'hui (Règle n°6). --}}
@props(['actuel'])

@can('creerConfidentiel', App\Models\Courrier::class)
    <div class="mb-6 inline-flex rounded-xl border border-brand-border bg-white p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <button type="button" wire:click="basculerModeConfidentiel(false)"
            class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $actuel === 'normal' ? 'bg-brand-navy text-white' : 'text-brand-text-secondary hover:bg-brand-surface-soft' }}">
            {{ __('Courrier normal') }}
        </button>
        <button type="button" wire:click="basculerModeConfidentiel(true)"
            class="flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium transition {{ $actuel === 'confidentiel' ? 'bg-brand-navy text-white' : 'text-brand-text-secondary hover:bg-brand-surface-soft' }}">
            <flux:icon.lock-closed class="size-3.5" />
            {{ __('Pli confidentiel') }}
        </button>
    </div>
@endcan
