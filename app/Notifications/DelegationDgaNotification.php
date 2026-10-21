<?php

namespace App\Notifications;

use App\Models\DelegationDga;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Module 7 étendu (2026-10-09, audit "list me all") — DelegationDgaIndex::
// activerDelegation()/desactiverDelegation() : le délégataire (ex. RH) ne
// savait jusqu'ici jamais qu'il avait (ou n'a plus) le pouvoir de valider
// le service d'un courrier entrant à la place du DGA/ADJ absent.
class DelegationDgaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public DelegationDga $delegation,
        // 'activee' ou 'desactivee'.
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
        return $this->action === 'activee'
            ? __('Une délégation DGA vous a été confiée (:nom)', ['nom' => $this->delegation->delegant->name])
            : __('Votre délégation DGA (:nom) a été désactivée', ['nom' => $this->delegation->delegant->name]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'libelle' => $this->libelle(),
            'sous_libelle' => $this->action === 'activee' ? $this->delegation->motif : null,
            'lien' => route('admin.delegation-dga'),
            'icone' => 'arrow-right-circle',
            'couleur' => $this->action === 'activee' ? 'navy' : 'neutre',
            'envoye_par' => $this->envoyePar?->name,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('[GEC] :libelle', ['libelle' => $this->libelle()]))
            ->greeting(__('Bonjour :nom,', ['nom' => $notifiable->name]))
            ->line($this->libelle());

        if ($this->action === 'activee') {
            $message->line(__('Vous pouvez désormais valider le service des courriers entrants adressés au DGA/ADJ absent, jusqu\'à la désactivation de cette délégation.'));
        }

        return $message->action(__('Voir les délégations'), route('admin.delegation-dga'));
    }
}
