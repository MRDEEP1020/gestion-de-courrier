<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Module 1/2 — équivalent d'OcrTermine pour un brouillon (flux scan-first,
// voir DECISIONS.md). Diffusé sur le canal privé PAR AGENT
// (App.Models.User.{creeParId}, déjà défini/autorisé dans routes/channels.php
// pour les notifications) plutôt que par brouillon (brouillon.{id}, décision
// initiale) : RegistrationForm doit rafraîchir en direct le statut OCR de
// N'IMPORTE LEQUEL des brouillons en attente de l'agent (liste déroulante,
// voir DECISIONS.md "Watcher automatique sur le formulaire d'enregistrement"),
// pas seulement celui actuellement sélectionné — un seul canal/listener par
// agent couvre les deux cas plutôt qu'un abonnement par brouillon.
class BrouillonOcrTermine implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $brouillonId,
        public int $creeParId,
        public string $statut,
        public ?int $confiance = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("App.Models.User.{$this->creeParId}");
    }

    public function broadcastAs(): string
    {
        return 'brouillon.ocr.termine';
    }
}
