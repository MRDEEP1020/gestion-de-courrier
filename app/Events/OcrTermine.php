<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Module 2 — Règle n°1 : "informer l'utilisateur… + notification quand le job
// est terminé". Diffusé immédiatement depuis le job (ShouldBroadcastNow, pas
// de second passage par la queue) sur un canal privé par courrier, dont
// l'accès est contrôlé par CourrierPolicy::view (routes/channels.php).
class OcrTermine implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $courrierId,
        public string $statut,
        public ?int $confiance = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("courrier.{$this->courrierId}");
    }

    public function broadcastAs(): string
    {
        return 'ocr.termine';
    }
}
