<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 1 — compteur par (année, service) pour générer le numéro de référence
        // (GEC-{annee}-{code}-{sequence}). Incrémenté sous verrou de ligne (lockForUpdate)
        // par NumeroReferenceGenerator ; la contrainte unique reste le filet de sécurité
        // final (Règle n°3 CONVENTIONS.md) en cas de concurrence.
        Schema::create('numero_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('annee');
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->unsignedInteger('dernier_numero')->default(0);
            $table->timestamps();

            $table->unique(['annee', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numero_sequences');
    }
};
