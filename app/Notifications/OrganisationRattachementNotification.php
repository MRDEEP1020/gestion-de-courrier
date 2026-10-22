<?php

namespace App\Notifications;

use App\Models\OrganizationUnit;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Module 7 étendu (2026-10-09, audit "list me all") — OrganisationIndex::
// ajouterUtilisateur()/definirCommeResponsable() : un utilisateur rattaché
// à une entité de l'organigramme, ou désigné responsable, ne l'apprenait
// jusqu'ici que s'il consultait lui-même la page Organisation.
class OrganisationRattachementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public OrganizationUnit $unite,
        // 'rattache' (membre de travail) ou 'responsable' (désigné responsable).
        public string $action,
        // 2026-10-12 — voir CourrierNotification pour le contexte complet
        // (demande utilisateur "who send it"). Auth::user() au moment de
        // l'appel, nullable pour compat avec un job déjà en file.
        public ?User $envoyePar = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function libelle(): string
    {
        return $this->action === 'responsable'
            ? __('Vous avez été désigné responsable de :nom', ['nom' => $this->unite->name])
            : __('Vous avez été rattaché à :nom', ['nom' => $this->unite->name]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'libelle' => $this->libelle(),
            'sous_libelle' => null,
            'lien' => route('admin.organisation', ['noeudId' => $this->unite->id]),
            'icone' => $this->action === 'responsable' ? 'star' : 'building-office-2',
            'couleur' => 'blue',
            'envoye_par' => $this->envoyePar?->name,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('[GEC] :libelle', ['libelle' => $this->libelle()]))
            ->greeting(__('Bonjour :nom,', ['nom' => $notifiable->name]))
            ->line($this->libelle())
            ->action(__('Voir l\'organisation'), route('admin.organisation', ['noeudId' => $this->unite->id]));
    }
}
