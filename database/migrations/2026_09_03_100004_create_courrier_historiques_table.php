<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 5 — table append-only : jamais d'update ni de delete applicatif.
        // Pas de colonne updated_at, pour rendre la mutation structurellement impossible côté modèle.
        Schema::create('courrier_historiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // ex. creation, changement_statut, affectation, reponse, validation
            $table->text('commentaire')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['courrier_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courrier_historiques');
    }
};
