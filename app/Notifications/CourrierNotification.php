<?php

namespace App\Notifications;

use App\Concerns\NotificationTempsReel;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Module 7 étendu (2026-10-09, voir le journal de développement "notification systémique" /
// audit "list me all") — UNE seule classe pour les 16 événements du circuit
// générique qui concernent quelqu'un d'autre que l'acteur (affectation,
// transfert, validation DGA, réaffectation, soumission/validation/renvoi,
// Signature DG/Trésorerie, mise en attente/reprise, rejet, décharge) plutôt
// que 16 classes quasi identiques — même `$action` que
// CourrierHistorique::create(['action' => ...]), icône/couleur réutilisées
// depuis CourrierHistorique::iconePourAction()/couleurPourAction() (une
// seule source de vérité). ShouldQueue (Règle n°1) : dispatchée depuis des
// méthodes SYNCHRONES de WorkflowService (appelées par des composants
// Livewire), jamais d'envoi de mail dans le cycle requête/réponse.
class CourrierNotification extends Notification implements ShouldQueue
{
    use NotificationTempsReel, Queueable;

    // Libellés dirigés vers le DESTINATAIRE ("vous"), distincts du style
    // journal (3e personne) de CourrierHistorique::AFFICHAGE — un seul
    // endroit par action, partagé entre toDatabase()/toMail().
    private const LIBELLES = [
        'affectation' => 'Un courrier vous a été affecté : :ref',
        // 2026-10-23 (voir DECISIONS.md "Transfert direct au service et
        // acceptation") — plus de DGA : le service reçoit le transfert, l'agent
        // est prévenu de l'acceptation ou du refus.
        'transfert' => 'Un courrier a été transféré à votre service — à accepter : :ref',
        'transfert_accepte' => 'Votre courrier a été accepté par le service — remettez l\'original papier : :ref',
        'transfert_refuse' => 'Votre courrier a été refusé par le service — à réorienter : :ref',
        'transmission' => 'Un courrier a été transmis à votre service — à affecter : :ref',
        'reaffectation_nouveau' => 'Un courrier vous a été réaffecté : :ref',
        'reaffectation_ancien' => 'Le courrier :ref ne vous est plus affecté',
        'soumis_validation' => 'Une réponse attend votre validation : :ref',
        'validation_acceptee' => 'Votre réponse a été validée : :ref',
        'validation_refusee' => 'Votre réponse a été renvoyée pour correction : :ref',
        'envoye_signature_dg' => 'Un courrier attend votre signature : :ref',
        'signe_dg_cloture' => 'La DG a signé et clôturé le courrier : :ref',
        'signe_dg_tresorerie' => 'Un courrier de décaissement attend la trésorerie : :ref',
        'traite_tresorerie' => 'La trésorerie a traité le courrier : :ref',
        'mise_en_attente' => 'Le courrier :ref est mis en attente d\'information',
        'reprise' => 'Le traitement du courrier :ref a repris',
        // 2026-10-30 — relance manuelle (WorkflowService::relancer()).
        'relance' => 'Relance : le courrier :ref attend votre traitement',
        'rejet' => 'Le courrier :ref a été rejeté',
        'decharge_emise' => 'Un original vous a été confié (décharge) : :ref',
        'decharge_rendue' => 'L\'original du courrier :ref a été rendu',
    ];

    public function __construct(
        public Courrier $courrier,
        // Jamais directement une des 17 actions WorkflowService telles
        // quelles pour la réaffectation : 'reaffectation_nouveau'/
        // '_ancien' distinguent le libellé (voir LIBELLES ci-dessus) tout
        // en partageant l'icône/couleur de l'action réelle 'reaffectation'
        // (voir action() ci-dessous).
        public string $action,
        public ?string $commentaire = null,
        // 2026-10-12, demande explicite de l'utilisateur ("make each
        // notification show who send it") — l'auteur réel de l'action
        // (Auth::user() au moment de l'appel dans WorkflowService), pas le
        // destinataire. Nullable + en dernier paramètre : un job déjà en
        // file d'attente au moment de ce déploiement (ancienne signature
        // sans ce paramètre) continue de se désérialiser sans erreur.
        public ?User $envoyePar = null,
    ) {}

    public function via(object $notifiable): array
    {
        return app(NotificationPreferenceService::class)->canaux($notifiable, 'courriers');
    }

    // Action réelle pour l'icône/couleur (CourrierHistorique::AFFICHAGE) —
    // les 2 variantes de réaffectation partagent celle de 'reaffectation'.
    private function actionPourAffichage(): string
    {
        return str_starts_with($this->action, 'reaffectation_') ? 'reaffectation' : $this->action;
    }

    private function libelle(): string
    {
        $gabarit = self::LIBELLES[$this->action] ?? ':ref a été mis à jour';

        return strtr($gabarit, [':ref' => $this->courrier->numero_reference]);
    }

    public function toDatabase(object $notifiable): array
    {
        $courrier = $this->courrier;

        return [
            'libelle' => $this->libelle(),
            // Règle n°6 — jamais l'objet d'un courrier confidentiel dans
            // une donnée affichée sans revérification de droits ligne par
            // ligne (même garde que CourrierEnRetardNotification::toDatabase()).
            'sous_libelle' => $courrier->confidentialite <= 1 ? $courrier->objet : null,
            'lien' => route('courriers.show', $courrier->id),
            'icone' => CourrierHistorique::iconePourAction($this->actionPourAffichage()),
            'couleur' => CourrierHistorique::couleurPourAction($this->actionPourAffichage()),
            'courrier_id' => $courrier->id,
            'numero_reference' => $courrier->numero_reference,
            'envoye_par' => $this->envoyePar?->name,
            // 2026-10-30 (« where does the message go », capture d'une relance)
            // — message de relance, motif de rejet/renvoi/attente… : jusque-là
            // seulement dans l'e-mail et l'historique, absent de l'application.
            // Même portée que l'e-mail (envoyé à la personne visée par l'auteur).
            'commentaire' => $this->commentaire,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $courrier = $this->courrier;

        $message = (new MailMessage)
            ->subject(__('[GEC] :libelle', ['libelle' => $this->libelle()]))
            ->greeting(__('Bonjour :nom,', ['nom' => $notifiable->name]))
            ->line($this->libelle());

        if ($this->commentaire) {
            $message->line(__('Commentaire : :commentaire', ['commentaire' => $this->commentaire]));
        }

        // Règle n°6 — même garde que toDatabase() ci-dessus.
        if ($courrier->confidentialite <= 1) {
            $message->line(__('Objet : :objet', ['objet' => $courrier->objet]));
        }

        return $message->action(__('Ouvrir le courrier'), route('courriers.show', $courrier->id));
    }
}
