<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Module 3 — le classement automatique a produit une proposition : la fiche
// ouverte se met à jour (même canal privé que l'OCR, Règle n°1 / Règle n°2).
class ClassementPropose implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $courrierId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("courrier.{$this->courrierId}");
    }

    public function broadcastAs(): string
    {
        return 'classement.propose';
    }
}
