<?php

namespace App\Notifications;

use App\Models\Courrier;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Module 7 — alerte SLA (avant et après dépassement de la date limite).
// Envoyée via SendMailAlertJob — jamais directement depuis un contrôleur.
// Pas ShouldQueue : le job qui l'envoie tourne déjà en file d'attente
// (Règle n°1) et doit savoir si l'envoi a échoué pour ne pas marquer
// l'alerte comme envoyée.
class CourrierEnRetardNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Courrier $courrier,
        public bool $enRetard,
        // 2e palier d'alerte (2026-10-05, Module 7, "escalade... configurable
        // selon le niveau de retard") — même notification "en retard", juste
        // un libellé distinct + élargie à un destinataire supplémentaire côté
        // SendMailAlertJob::destinataires(). Toujours false pour le palier
        // "à risque"/"en retard" initial, par défaut pour ne rien changer au
        // comportement déjà couvert par les tests existants.
        public bool $escalade = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    // Centre de notifications in-app (2026-10-05, Module 7) — mêmes règles
    // que toMail() ci-dessous : jamais l'objet d'un courrier confidentiel
    // dans les données stockées (le lien reste protégé par CourrierPolicy::view()
    // derrière la fiche, mais la donnée stockée ici est, elle, affichée en
    // clair dans la cloche sans revérification de droits ligne par ligne).
    // 2026-10-09 — même enveloppe générique que CourrierNotification (libelle/
    // sous_libelle/lien/icone/couleur), pour que notification-bell.blade.php/
    // notifications-index.blade.php rendent TOUS les types de notification
    // sans branche spécifique par classe.
    public function toDatabase(object $notifiable): array
    {
        $courrier = $this->courrier;

        return [
            'libelle' => $this->escalade
                ? __('Escalade — toujours en retard : :ref', ['ref' => $courrier->numero_reference])
                : ($this->enRetard
                    ? __(':ref est en retard', ['ref' => $courrier->numero_reference])
                    : __(':ref arrive à échéance', ['ref' => $courrier->numero_reference])),
            'sous_libelle' => $courrier->confidentialite <= 1 ? $courrier->objet : null,
            'lien' => route('courriers.show', $courrier->id),
            'icone' => $this->enRetard || $this->escalade ? 'exclamation-triangle' : 'clock',
            'couleur' => $this->enRetard || $this->escalade ? 'danger' : 'warning',
            'courrier_id' => $courrier->id,
            'numero_reference' => $courrier->numero_reference,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $courrier = $this->courrier;
        $dateLimite = $courrier->date_limite?->format('d/m/Y');

        $message = (new MailMessage)
            ->subject($this->escalade
                ? __('[GEC] Escalade — courrier toujours en retard : :ref', ['ref' => $courrier->numero_reference])
                : ($this->enRetard
                    ? __('[GEC] Courrier en retard : :ref', ['ref' => $courrier->numero_reference])
                    : __('[GEC] Courrier bientôt en retard : :ref', ['ref' => $courrier->numero_reference])))
            ->greeting(__('Bonjour :nom,', ['nom' => $notifiable->name]))
            ->line($this->escalade
                ? __('Le courrier :ref est toujours en retard malgré les relances précédentes — ce message vous est adressé en tant que niveau hiérarchique supérieur.', ['ref' => $courrier->numero_reference])
                : ($this->enRetard
                    ? __('Le courrier :ref a dépassé sa date limite de traitement (:date).', ['ref' => $courrier->numero_reference, 'date' => $dateLimite])
                    : __('Le courrier :ref arrive à sa date limite de traitement (:date).', ['ref' => $courrier->numero_reference, 'date' => $dateLimite])));

        // Règle n°6 — un email sort du système : jamais l'objet d'un courrier
        // confidentiel, seulement sa référence (la fiche reste protégée par
        // CourrierPolicy::view() derrière le lien).
        if ($courrier->confidentialite <= 1) {
            $message->line(__('Objet : :objet', ['objet' => $courrier->objet]));
        }

        return $message->action(__('Ouvrir le courrier'), route('courriers.show', $courrier->id));
    }
}
