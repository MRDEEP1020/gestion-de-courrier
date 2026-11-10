<?php

namespace App\Services;

use App\Models\Parametre;
use App\Models\User;
use InvalidArgumentException;

class NotificationPreferenceService
{
    public const CATEGORIES = [
        'courriers' => [
            'label' => 'Événements de courrier',
            'description' => 'Affectations, transferts, validations et autres étapes du circuit.',
        ],
        'alertes_sla' => [
            'label' => 'Alertes de délai (SLA)',
            'description' => 'Alertes d’échéance, relances et escalades.',
        ],
        'comptes' => [
            'label' => 'Compte utilisateur',
            'description' => 'Modifications, activation et désactivation de votre compte.',
        ],
        'dossiers' => [
            'label' => 'Dossiers partagés',
            'description' => 'Partage ou retrait d’accès aux dossiers de classement.',
        ],
        'organisation' => [
            'label' => 'Organisation',
            'description' => 'Rattachements et désignations comme responsable.',
        ],
    ];

    public const CANAUX = ['email', 'application'];

    public function preferencesParDefaut(): array
    {
        $preferences = array_fill_keys(
            array_keys(self::CATEGORIES),
            ['email' => true, 'application' => true],
        );

        foreach (Parametre::actuel()->notifications_defaut ?? [] as $categorie => $canaux) {
            if (! isset($preferences[$categorie]) || ! is_array($canaux)) {
                continue;
            }

            foreach (self::CANAUX as $canal) {
                if (array_key_exists($canal, $canaux) && is_bool($canaux[$canal])) {
                    $preferences[$categorie][$canal] = $canaux[$canal];
                }
            }
        }

        return $preferences;
    }

    /**
     * Réglage appliqué à l'utilisateur, par catégorie et canal : son propre
     * choix quand il en a fait un, sinon la valeur par défaut administrateur.
     */
    public function preferencesDe(User $user): array
    {
        $preferences = $this->preferencesParDefaut();
        $enregistrees = is_array($user->preferences_notifications) ? $user->preferences_notifications : [];

        foreach ($preferences as $categorie => $canaux) {
            foreach (self::CANAUX as $canal) {
                $personnelle = $enregistrees[$categorie][$canal] ?? null;
                if (is_bool($personnelle)) {
                    $preferences[$categorie][$canal] = $personnelle;
                }
            }
        }

        return $preferences;
    }

    public function canaux(object $notifiable, string $categorie): array
    {
        if (! $notifiable instanceof User) {
            throw new InvalidArgumentException('Les préférences de notification GEC nécessitent un utilisateur.');
        }

        if (! array_key_exists($categorie, self::CATEGORIES)) {
            throw new InvalidArgumentException("Catégorie de notification inconnue : {$categorie}.");
        }

        ['email' => $email, 'application' => $application] = $this->preferencesDe($notifiable)[$categorie];
        $canaux = [];

        if ($email) {
            $canaux[] = 'mail';
        }

        if ($application) {
            $canaux[] = 'database';
            $canaux[] = 'broadcast';
        }

        return $canaux;
    }

    public function peutNotifier(object $notifiable, string $categorie): bool
    {
        return $this->canaux($notifiable, $categorie) !== [];
    }
}
