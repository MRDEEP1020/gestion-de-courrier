<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 1/4 — liste des destinataires qu'un agent/réceptionniste a le
// droit de choisir au moment de transférer un courrier (DGA, ADJ DGA, ou
// tout autre "supérieur" — demande explicite de l'utilisateur, 2026-09-15 :
// "she should have the possibility to chose the user"). Curatée par
// l'administrateur PAR AGENT (pas dérivée automatiquement d'un profil ou
// d'un privilège — voir DECISIONS.md). agent_id = celui qui envoie,
// destinataire_id = celui qu'il a le droit de choisir dans la modale.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinataires_transfert', function (Blueprint $table) {
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('destinataire_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['agent_id', 'destinataire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinataires_transfert');
    }
};
