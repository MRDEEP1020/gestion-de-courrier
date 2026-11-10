<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Module 9 — pages de l'aperçu sécurisé prêtes (2026-11-09) : la
// visionneuse en attente se recharge d'elle-même (pas d'interrogation
// répétée, CONVENTIONS.md Règle n°2). Canal du courrier (droits = consultation
// du courrier, routes/channels.php), ou celui de l'auteur d'un brouillon.
class ApercuPret implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $type,
        public int $id,
        public string $canal,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel($this->canal);
    }

    public function broadcastAs(): string
    {
        return 'apercu.pret';
    }

    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'id' => $this->id];
    }
}
