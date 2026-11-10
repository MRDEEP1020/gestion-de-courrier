<?php

namespace App\Livewire\Settings;

use App\Models\Parametre;
use App\Services\NotificationPreferenceService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// 2026-11-10 — page refaite : un interrupteur par catégorie et canal montre
// le réglage réellement appliqué (au lieu de trois choix « Par défaut /
// Activé / Désactivé ») et s'enregistre aussitôt, comme la page Apparence.
// Seuls les écarts avec l'administrateur sont stockés, pour qu'un réglage
// laissé tel quel suive ses changements futurs (DECISIONS.md, 2026-11-10).
#[Title('Notification preferences')]
class NotificationPreferences extends Component
{
    /** Réglage appliqué : [categorie => ['email' => bool, 'application' => bool]]. */
    public array $preferences = [];

    /** 'default' (réglage administrateur) ou un nombre de jours de 1 à 10. */
    public string $frequenceRelanceSla = 'default';

    public function mount(): void
    {
        $this->charger();
    }

    public function updatedPreferences(): void
    {
        $this->enregistrer();
    }

    public function updatedFrequenceRelanceSla(): void
    {
        $this->enregistrer();
    }

    public function enregistrer(): void
    {
        $regles = [
            'frequenceRelanceSla' => ['required', Rule::in(['default', ...range(1, 10)])],
        ];

        foreach (NotificationPreferenceService::CATEGORIES as $categorie => $details) {
            foreach (NotificationPreferenceService::CANAUX as $canal) {
                $regles["preferences.{$categorie}.{$canal}"] = ['required', 'boolean'];
            }
        }

        $this->validate($regles);

        $defauts = $this->defauts();
        $preferences = [];

        // Construit depuis les catégories connues : une clé ajoutée côté
        // navigateur n'est jamais enregistrée.
        foreach (NotificationPreferenceService::CATEGORIES as $categorie => $details) {
            foreach (NotificationPreferenceService::CANAUX as $canal) {
                $valeur = (bool) $this->preferences[$categorie][$canal];
                $preferences[$categorie][$canal] = $valeur === $defauts[$categorie][$canal] ? null : $valeur;
            }
        }

        $preferences['sla_relance_jours'] = $this->frequenceRelanceSla === 'default'
            ? null
            : (int) $this->frequenceRelanceSla;

        $personnalise = collect($preferences)->flatten()->contains(fn ($valeur) => $valeur !== null);

        Auth::user()->forceFill(['preferences_notifications' => $personnalise ? $preferences : null])->save();

        Flux::toast(variant: 'success', text: __('Préférences de notification enregistrées.'));
    }

    public function reinitialiser(): void
    {
        Auth::user()->forceFill(['preferences_notifications' => null])->save();

        $this->charger();

        Flux::toast(variant: 'success', text: __('Réglages de l\'administrateur rétablis.'));
    }

    /** Catégories dont le réglage affiché diffère de celui de l'administrateur. */
    #[Computed]
    public function personnalisees(): array
    {
        $defauts = $this->defauts();
        $personnalisees = [];

        foreach (NotificationPreferenceService::CATEGORIES as $categorie => $details) {
            foreach (NotificationPreferenceService::CANAUX as $canal) {
                if ((bool) ($this->preferences[$categorie][$canal] ?? false) !== $defauts[$categorie][$canal]) {
                    $personnalisees[] = $categorie;
                    break;
                }
            }
        }

        return $personnalisees;
    }

    public function frequenceRelanceSlaParDefaut(): int
    {
        return Parametre::actuel()->sla_relance_jours;
    }

    private function defauts(): array
    {
        return app(NotificationPreferenceService::class)->preferencesParDefaut();
    }

    private function charger(): void
    {
        $user = Auth::user();

        $this->preferences = app(NotificationPreferenceService::class)->preferencesDe($user);

        $frequence = $user->preferences_notifications['sla_relance_jours'] ?? null;
        $this->frequenceRelanceSla = $frequence === null ? 'default' : (string) $frequence;
    }
}
