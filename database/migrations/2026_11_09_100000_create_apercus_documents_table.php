<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 9 — aperçu sécurisé (2026-11-09, voir DECISIONS.md « Fichiers :
// chiffrement, aperçu sécurisé, aucun téléchargement ») : un document se
// consulte en pages images filigranées, jamais en fichier original. Une
// ligne par document préparé (courrier, pièce jointe, brouillon) ; les
// images elles-mêmes sont sur le stockage chiffré (apercus/{type}/{id}/).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apercus_documents', function (Blueprint $table) {
            $table->id();
            $table->string('fichier_type', 20);
            $table->unsignedBigInteger('fichier_id');
            // 'pret' ou 'non_supporte' (format sans rendu possible, ex. TIFF).
            $table->string('statut', 20);
            // [[largeur, hauteur], …] en pixels, une entrée par page.
            $table->json('pages');
            $table->timestamp('genere_le');

            $table->unique(['fichier_type', 'fichier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apercus_documents');
    }
};
