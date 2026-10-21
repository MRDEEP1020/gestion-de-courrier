<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 3 — "un document appartient à une classification principale mais
        // peut avoir plusieurs mots-clés/tags secondaires", extraits du texte OCR,
        // posés par une règle, ou saisis manuellement.
        Schema::create('mots_cles', function (Blueprint $table) {
            $table->id();
            $table->string('libelle')->unique();
            $table->timestamps();
        });

        Schema::create('courrier_mot_cle', function (Blueprint $table) {
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('mot_cle_id')->constrained('mots_cles')->cascadeOnDelete();
            $table->enum('source', ['regle', 'ocr', 'manuel']);
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['courrier_id', 'mot_cle_id']);
            $table->index('mot_cle_id'); // Module 8 : recherche par mot-clé
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courrier_mot_cle');
        Schema::dropIfExists('mots_cles');
    }
};
