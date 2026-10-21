<?php

namespace App\Livewire\Backend;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

// Module 7 — centre de notifications in-app, page complète (2026-10-05).
// Règle n°3 — toujours paginé, jamais ->get() sur une table qui va grossir.
#[Title('Notifications')]
class NotificationsIndex extends Component
{
    use WithPagination;

    // Même garde-fou que Dashboard::mount() : sans le privilège, redirection
    // plutôt qu'un 403 brut (voir CONVENTIONS.md Règle n°6 — jamais un simple
    // `if` dans la vue, mais ici il n'y a rien à filtrer PAR courrier,
    // chaque utilisateur ne voit QUE ses propres notifications).
    public function mount(): void
    {
        if (! Auth::user()->hasPrivilege('general.notifications')) {
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    public function marquerCommeLue(string $notificationId): void
    {
        Auth::user()->notifications()->whereKey($notificationId)->whereNull('read_at')->first()?->markAsRead();

        // Voir NotificationBell::marquerCommeLue() — même événement
        // navigateur, pour que le badge de la sidebar persistante se
        // resynchronise depuis cette page aussi.
        $this->dispatch('notifications-mises-a-jour');
    }

    public function marquerToutesCommeLues(): void
    {
        Auth::user()->unreadNotifications->markAsRead();

        $this->dispatch('notifications-mises-a-jour');
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.frontend.notifications-index', [
            'notifications' => $this->paginer(),
        ]);
    }

    private function paginer(): LengthAwarePaginator
    {
        return Auth::user()->notifications()->latest()->paginate(20);
    }
}
