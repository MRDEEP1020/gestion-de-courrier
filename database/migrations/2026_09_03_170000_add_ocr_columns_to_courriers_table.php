<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 2 — texte extrait par l'OCR (alimentera l'index de recherche
        // plein-texte du Module 8) et statut du traitement, pour informer
        // l'utilisateur ("en traitement" — Règle n°1 CONVENTIONS.md) sans le
        // confondre avec `statut` (l'étape du circuit de validation, Module 4).
        Schema::table('courriers', function (Blueprint $table) {
            $table->longText('texte_ocr')->nullable()->after('fichier_path');
            $table->enum('ocr_statut', ['non_traite', 'en_cours', 'reussi', 'echec_qualite', 'echec'])
                ->default('non_traite')
                ->after('texte_ocr');
            $table->timestamp('ocr_traite_le')->nullable()->after('ocr_statut');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn(['texte_ocr', 'ocr_statut', 'ocr_traite_le']);
        });
    }
};
