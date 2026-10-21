<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ARCHITECTURE.md — relation un-à-plusieurs avec `courriers`, distincte du
        // document principal scanné (`courriers.fichier_path`, réservé au Module 2).
        // Écriture unique (Règle n°4 CONVENTIONS.md) : pas d'updated_at, jamais de update en place.
        Schema::create('pieces_jointes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->string('fichier_path');
            $table->string('nom_original');
            $table->string('type_mime');
            $table->unsignedBigInteger('taille');
            $table->timestamp('created_at')->useCurrent();

            $table->index('courrier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_jointes');
    }
};
