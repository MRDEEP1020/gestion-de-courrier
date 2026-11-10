<?php

namespace App\Concerns;

use Illuminate\Notifications\Messages\BroadcastMessage;

// Module 7 (2026-10-27, tâche « Real time alert without the need of
// refreshing the page ») — toute notification GEC est aussi diffusée en
// direct sur le canal privé de son destinataire (App.Models.User.{id},
// autorisé dans routes/channels.php pour lui seul), via Reverb. La cloche
// (NotificationBell) l'écoute : compteur, liste et toast se mettent à jour
// sans recharger la page. Le contenu diffusé est l'enveloppe commune de
// toDatabase() (libelle, sous_libelle, lien, icone, couleur).
//
// La diffusion part en file d'attente (BroadcastNotificationCreated est
// ShouldBroadcast, Règle n°1) : si Reverb est indisponible, seul ce job de
// diffusion échoue — l'email et la notification en base ne sont jamais
// renvoyés en double. Utilisation : ajouter 'broadcast' à via().
trait NotificationTempsReel
{
    // Nom d'événement fixe, écouté par NotificationBell (au lieu du nom de
    // classe Laravel complet, pénible à écrire dans un attribut #[On]).
    public function broadcastAs(): string
    {
        return 'notification.gec';
    }

    // Même contenu que la notification en base (toDatabase(), défini par
    // chaque classe) : la cloche affiche en direct exactement ce qu'elle
    // affichera après un rechargement.
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
