<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Module 7 étendu (2026-10-09, audit "list me all") — UserList::
// enregistrerEdition() (profil/privilège/confidentialité/service changés)
// et basculerActif() : l'utilisateur concerné ne l'apprenait jusqu'ici
// jamais — risque réel pour un compte désactivé, qui ne comprendrait pas
// pourquoi il ne peut plus se connecter.
class CompteUtilisateurNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        // 'modifie' (profil/privilège/confidentialité/service) ou
        // 'active'/'desactive' (statut du compte).
        public string $action,
        // 2026-10-12 — voir CourrierNotification pour le contexte complet.
        public ?User $envoyePar = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function libelle(): string
    {
        return match ($this->action) {
            'active' => __('Votre compte a été réactivé'),
            'desactive' => __('Votre compte a été désactivé'),
            default => __('Votre compte a été modifié (profil, droits ou service)'),
        };
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'libelle' => $this->libelle(),
            'sous_libelle' => null,
            'lien' => route('profile.edit'),
            'icone' => match ($this->action) {
                'active' => 'check-circle',
                'desactive' => 'no-symbol',
                default => 'user-circle',
            },
            'couleur' => match ($this->action) {
                'active' => 'success',
                'desactive' => 'danger',
                default => 'blue',
            },
            'envoye_par' => $this->envoyePar?->name,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('[GEC] :libelle', ['libelle' => $this->libelle()]))
            ->greeting(__('Bonjour :nom,', ['nom' => $notifiable->name]))
            ->line($this->libelle())
            ->action(__('Voir mon compte'), route('profile.edit'));
    }
}
