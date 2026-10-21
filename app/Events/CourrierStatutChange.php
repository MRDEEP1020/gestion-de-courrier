<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Module 4 — demande explicite de l'utilisateur : "make the parcours du
// courier work live in real time at each step" — même principe que
// OcrTermine/ClassementPropose (Règle n°1, canal privé par courrier
// contrôlé par CourrierPolicy::view, voir routes/channels.php), diffusé
// depuis WorkflowService à chaque transition de statut réelle (affecter/
// transferer/validerService/transitionnerSimple), pour que la fiche
// ouverte dans un AUTRE onglet/session se mette à jour sans rechargement
// manuel.
class CourrierStatutChange implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $courrierId,
        public string $statut,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("courrier.{$this->courrierId}");
    }

    public function broadcastAs(): string
    {
        return 'statut.change';
    }
}
