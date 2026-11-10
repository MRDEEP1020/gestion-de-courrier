<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service et
// acceptation") — le routage par la DGA disparaît : l'accueil transfère
// directement au service concerné, qui ACCEPTE (décharge numérotée, preuve
// exigée avant la remise de l'original papier) ou REFUSE avec un motif.
// Une ligne par tentative de transfert : un courrier refusé puis réorienté
// en a plusieurs. Complète courrier_historiques (Règle n°5 — le journal
// immuable reste la trace officielle) : cette table porte l'état de chaque
// transfert, le numéro de décharge et la remise de l'original.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferts_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services');
            $table->foreignId('transfere_par_id')->constrained('users');
            $table->enum('statut', ['en_attente', 'accepte', 'refuse'])->default('en_attente');
            $table->foreignId('decide_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('motif_refus')->nullable();
            $table->string('numero_decharge')->nullable()->unique();
            $table->foreignId('original_remis_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('original_remis_le')->nullable();
            $table->timestamps();

            // Règle n°3 — file "à accepter" d'un service, dernier transfert d'un courrier.
            $table->index(['service_id', 'statut']);
            $table->index(['courrier_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferts_service');
    }
};
