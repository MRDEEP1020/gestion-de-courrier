<?php

namespace App\Notifications;

use App\Models\DossierClassement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Module 7 étendu (2026-10-09, audit "list me all") — partage/révocation
// d'un dossier de classement (DossierClassementList) : l'utilisateur
// concerné ne le découvrait jusqu'ici qu'en tombant dessus par hasard dans
// "Dossiers & Archives".
class DossierPartageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public DossierClassement $dossier,
        // 'partage' ou 'revoque'.
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
        return $this->action === 'partage'
            ? __('Le dossier « :nom » a été partagé avec vous', ['nom' => $this->dossier->nom])
            : __('L\'accès au dossier « :nom » vous a été retiré', ['nom' => $this->dossier->nom]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'libelle' => $this->libelle(),
            'sous_libelle' => null,
            'lien' => route('dossiers-classement.index', ['noeud' => $this->dossier->id]),
            'icone' => $this->action === 'partage' ? 'folder-plus' : 'folder-minus',
            'couleur' => $this->action === 'partage' ? 'blue' : 'neutre',
            'envoye_par' => $this->envoyePar?->name,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('[GEC] :libelle', ['libelle' => $this->libelle()]))
            ->greeting(__('Bonjour :nom,', ['nom' => $notifiable->name]))
            ->line($this->libelle())
            ->action(__('Voir le dossier'), route('dossiers-classement.index', ['noeud' => $this->dossier->id]));
    }
}
