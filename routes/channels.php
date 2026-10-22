<?php

use App\Models\Courrier;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Canal privé par utilisateur (convention Laravel par défaut) — réutilisé par
// BrouillonOcrTermine (voir DECISIONS.md "Watcher automatique sur le
// formulaire d'enregistrement") pour notifier UN agent de la fin d'OCR de
// n'importe lequel de SES brouillons, pas seulement le "sien" via un canal
// séparé par brouillon.
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Module 2 (et à venir : Modules 4/6/7) — canal privé d'un courrier : mêmes
// droits que sa consultation (Règle n°6, jamais un simple contrôle côté client).
Broadcast::channel('courrier.{courrierId}', function (User $user, int $courrierId) {
    $courrier = Courrier::with('service')->find($courrierId);

    return $courrier !== null && $user->can('view', $courrier);
});
