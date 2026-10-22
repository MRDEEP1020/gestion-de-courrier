<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 2 — confiance moyenne (0-100) des mots reconnus par Tesseract,
        // pour le contrôle qualité : du texte reconnu mais faux (photo floue)
        // a une confiance basse même s'il est long.
        Schema::table('courriers', function (Blueprint $table) {
            $table->unsignedTinyInteger('ocr_confiance')->nullable()->after('ocr_statut');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn('ocr_confiance');
        });
    }
};
