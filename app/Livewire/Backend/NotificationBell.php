<?php

namespace App\Livewire\Backend;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

// Module 7 — centre de notifications in-app (2026-10-05). Composant SANS
// wire:poll (Règle n°2) : il vit dans l'en-tête, HORS du bloc @persist de
// la sidebar (voir sidebar.blade.php), donc il est remonté à chaque
// wire:navigate — le compteur/la liste se rafraîchissent naturellement à
// chaque changement de page, sans scrutation côté client.
// Toujours scopé à auth()->user()->notifications() : aucune notification
// d'un autre utilisateur n'est jamais accessible, pas besoin de Policy
// dédiée (même principe qu'un panier/profil personnel).
class NotificationBell extends Component
{
    #[Computed]
    public function notifications()
    {
        return Auth::user()->notifications()->latest()->take(8)->get();
    }

    #[Computed]
    public function nombreNonLues(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    public function marquerCommeLue(string $notificationId): void
    {
        Auth::user()->notifications()->whereKey($notificationId)->whereNull('read_at')->first()?->markAsRead();

        unset($this->notifications, $this->nombreNonLues);

        // Événement NAVIGATEUR (pas un listener Livewire — la sidebar n'est
        // pas un composant Livewire) : voir sidebar.blade.php,
        // synchroniserBadgeNotificationsSidebar() écoute cet événement en
        // plus de livewire:navigated, pour que le badge "Notifications" de
        // la sidebar persistante reste à jour sans attendre une navigation.
        $this->dispatch('notifications-mises-a-jour');
    }

    public function marquerToutesCommeLues(): void
    {
        Auth::user()->unreadNotifications->markAsRead();

        unset($this->notifications, $this->nombreNonLues);

        $this->dispatch('notifications-mises-a-jour');
    }

    // La page complète (NotificationsIndex) peut aussi marquer des
    // notifications comme lues sans quitter la page — cette cloche doit
    // alors se rafraîchir elle aussi (sinon son data-notifications-non-lues,
    // lu par le script de sidebar.blade.php, resterait périmé).
    #[On('notifications-mises-a-jour')]
    public function rafraichir(): void
    {
        unset($this->notifications, $this->nombreNonLues);
    }

    public function render()
    {
        return view('livewire.frontend.notification-bell');
    }
}
