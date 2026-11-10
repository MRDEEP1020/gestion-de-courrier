<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

// 2026-10-27 — l'apparence est enregistrée sur le compte (users.apparence),
// plus seulement dans le navigateur : elle suit l'utilisateur et ne déborde
// jamais sur le compte suivant ouvert sur le même poste. Appliquée à chaque
// page par partials/head.blade.php, avant le script d'apparence de Flux.
#[Title('Appearance settings')]
class Appearance extends Component
{
    public const APPARENCES = ['light', 'dark', 'system'];

    public string $apparence = 'light';

    public function mount(): void
    {
        $this->apparence = Auth::user()->apparence ?? 'light';
    }

    public function updatedApparence(string $valeur): void
    {
        $this->validate(['apparence' => ['required', Rule::in(self::APPARENCES)]]);

        Auth::user()->forceFill(['apparence' => $valeur])->save();

        // Appliquée tout de suite dans la page (sans rechargement).
        $this->dispatch('apparence-changee', apparence: $valeur);
    }
}
