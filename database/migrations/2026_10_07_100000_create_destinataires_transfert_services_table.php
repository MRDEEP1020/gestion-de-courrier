<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 1 — pli confidentiel parfois adressé à tout un SERVICE (ex. "Direction
// Générale", "Service RH") plutôt qu'à une personne nommée (demande explicite
// de l'utilisateur, 2026-10-07 : "sometimes it been send to the Directions
// general or to the RH SERVICE... sometimes to a specific person"). Table
// PARALLÈLE à `destinataires_transfert` (jamais touchée), même convention —
// curatée par l'administrateur PAR AGENT, agent_id = celui qui envoie,
// service_id = le service qu'il a le droit de choisir. Au moment d'envoyer,
// le destinataire réel posé sur courriers.destinataire_transfert_id reste
// TOUJOURS un utilisateur (le responsable du service choisi) — cette table
// ne fait que piloter CE QUI EST PROPOSÉ dans la liste, pas le modèle de
// données du courrier lui-même.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinataires_transfert_services', function (Blueprint $table) {
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->primary(['agent_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinataires_transfert_services');
    }
};
