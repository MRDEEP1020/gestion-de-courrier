<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 3 — règles de classement "simples" (PRD phase 1, pas de ML),
        // paramétrables par un Administrateur sans intervention développeur.
        // "Si l'un des mots-clés apparaît dans les champs surveillés, proposer
        // ce type / ce service et ajouter ces tags."
        Schema::create('regles_classement', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->json('mots_cles');                       // ex. ["sinistre", "déclaration de sinistre"]
            $table->json('champs')->nullable();              // sous-ensemble de objet|expediteur|texte_ocr ; null = tous
            $table->string('type_document_propose')->nullable();
            $table->foreignId('service_propose_id')->nullable()->constrained('services')->nullOnDelete();
            $table->json('tags')->nullable();                // mots-clés secondaires à attacher
            $table->unsignedSmallInteger('priorite')->default(100); // plus petit = évalué en premier
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->index(['actif', 'priorite']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regles_classement');
    }
};
