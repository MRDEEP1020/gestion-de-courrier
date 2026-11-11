<?php

namespace App\Livewire\Settings;

use App\Models\Parametre;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
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

    // 2026-11-11 (« set it them self like in the clock phone ») — intervalle
    // personnel des rappels « en retard », en heures ; null = celui de
    // l'administrateur. Verrouillé : ne change que par definirRelance(),
    // qui vérifie les bornes.
    #[Locked]
    public ?int $relanceHeures = null;

    public function mount(): void
    {
        $this->charger();
    }

    // 2026-11-10 (« only the admin can see and modify it ») — les
    // interrupteurs E-mail / Application ne sont ni affichés ni acceptés sans
    // le privilège ; « Rappels de courrier en retard » reste à tous.
    #[Computed]
    public function peutChoisirCanaux(): bool
    {
        return Auth::user()->hasPrivilege(NotificationPreferenceService::PRIVILEGE_CANAUX);
    }

    public function updatedPreferences(): void
    {
        abort_unless($this->peutChoisirCanaux, 403);

        $this->enregistrer();
    }

    // Minuteur à deux roues (jours 0-10, heures 0-23), de 1 h à 10 jours.
    // Égal au réglage de l'administrateur = rien de personnel n'est gardé,
    // pour suivre ses changements futurs (comme les interrupteurs).
    public function definirRelance(int $jours, int $heures): void
    {
        $total = $jours * 24 + $heures;

        if ($jours < 0 || $jours > 10 || $heures < 0 || $heures > 23 || $total < 1 || $total > User::RELANCE_SLA_HEURES_MAX) {
            throw ValidationException::withMessages(['relance' => __('Choisissez un intervalle entre 1 heure et 10 jours.')]);
        }

        $this->relanceHeures = $total === $this->relanceHeuresParDefaut() ? null : $total;

        $this->enregistrer();
        $this->signalerRelance();

        // 2026-11-11 (« inside a modal ») — le minuteur vit dans une modale.
        Flux::modal('minuteur-relance')->close();
    }

    public function relanceParDefaut(): void
    {
        $this->relanceHeures = null;

        $this->enregistrer();
        $this->signalerRelance();
    }

    public function enregistrer(): void
    {
        $preferences = [];

        // Construit depuis les catégories connues : une clé ajoutée côté
        // navigateur n'est jamais enregistrée. Sans le privilège, aucun canal
        // n'est enregistré (ceux d'avant, déjà ignorés, partent avec).
        if ($this->peutChoisirCanaux) {
            $regles = [];

            foreach (NotificationPreferenceService::CATEGORIES as $categorie => $details) {
                foreach (NotificationPreferenceService::CANAUX as $canal) {
                    $regles["preferences.{$categorie}.{$canal}"] = ['required', 'boolean'];
                }
            }

            $this->validate($regles);

            $defauts = $this->defauts();

            foreach (NotificationPreferenceService::CATEGORIES as $categorie => $details) {
                foreach (NotificationPreferenceService::CANAUX as $canal) {
                    $valeur = (bool) $this->preferences[$categorie][$canal];
                    $preferences[$categorie][$canal] = $valeur === $defauts[$categorie][$canal] ? null : $valeur;
                }
            }
        }

        // L'ancienne clé en jours (avant le 2026-11-11) n'est plus écrite.
        $preferences['sla_relance_heures'] = $this->relanceHeures;

        $personnalise = collect($preferences)->flatten()->contains(fn ($valeur) => $valeur !== null);

        Auth::user()->forceFill(['preferences_notifications' => $personnalise ? $preferences : null])->save();

        Flux::toast(variant: 'success', text: __('Préférences de notification enregistrées.'));
    }

    public function reinitialiser(): void
    {
        Auth::user()->forceFill(['preferences_notifications' => null])->save();

        $this->charger();
        $this->signalerRelance();

        Flux::toast(variant: 'success', text: __('Réglages de l\'administrateur rétablis.'));
    }

    /** Catégories dont le réglage affiché diffère de celui de l'administrateur. */
    #[Computed]
    public function personnalisees(): array
    {
        if (! $this->peutChoisirCanaux) {
            return [];
        }

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

    public function relanceHeuresParDefaut(): int
    {
        return Parametre::actuel()->sla_relance_jours * 24;
    }

    /** Intervalle réellement appliqué, en heures. */
    public function relanceAppliquee(): int
    {
        return $this->relanceHeures ?? $this->relanceHeuresParDefaut();
    }

    // « 2 jours 6 heures », « 1 jour », « 6 heures ».
    public static function libelleIntervalle(int $heures): string
    {
        $jours = intdiv($heures, 24);
        $reste = $heures % 24;

        return collect([
            $jours > 0 ? trans_choice(':count jour|:count jours', $jours, ['count' => $jours]) : null,
            $reste > 0 ? trans_choice(':count heure|:count heures', $reste, ['count' => $reste]) : null,
        ])->filter()->implode(' ');
    }

    private function defauts(): array
    {
        return app(NotificationPreferenceService::class)->preferencesParDefaut();
    }

    private function charger(): void
    {
        $user = Auth::user();

        $this->preferences = app(NotificationPreferenceService::class)->preferencesDe($user);
        $this->relanceHeures = $user->relanceSlaPersonnelleHeures();
    }

    // Les roues (wire:ignore) ne sont pas re-rendues : l'intervalle appliqué
    // leur est envoyé, elles en repartent à la prochaine ouverture.
    private function signalerRelance(): void
    {
        $heures = $this->relanceAppliquee();

        $this->dispatch('relance-replacee', jours: intdiv($heures, 24), heures: $heures % 24);
    }
}
