<?php

use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\NotificationPreferences;
use App\Livewire\Settings\Profile;
use App\Http\Controllers\RenouvelerMotDePasseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');
    Route::livewire('settings/notifications', NotificationPreferences::class)->name('notifications.edit');

    // 2026-11-11 — renouvellement imposé (mot de passe expiré ou jamais
    // choisi), voir App\Http\Middleware\ExigerMotDePasseAJour.
    Route::get('mot-de-passe/renouveler', [RenouvelerMotDePasseController::class, 'afficher'])->name('mot-de-passe.renouveler');
    Route::post('mot-de-passe/renouveler', [RenouvelerMotDePasseController::class, 'enregistrer'])->name('mot-de-passe.renouveler.enregistrer');
});

// 2026-10-26 — page Sécurité (settings/security) et point d'entrée
// .well-known/passkey-endpoints retirés avec la double authentification et
// les clés d'accès (voir config/fortify.php, DECISIONS.md "Page Sécurité retirée").
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', Appearance::class)->name('appearance.edit');
});
