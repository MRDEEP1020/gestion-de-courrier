<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 9 — occupation du stockage (2026-11-09, demande « on doit aussi
// pouvoir voir l'espace de stockage », voir DECISIONS.md) : une ligne par
// calcul (CalculerOccupationStockageJob), ce qui donne aussi l'évolution.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occupations_stockage', function (Blueprint $table) {
            $table->id();
            $table->timestamp('calcule_le')->index();
            $table->unsignedInteger('fichiers');
            $table->unsignedBigInteger('octets');
            // {type: {fichiers, octets}} — documents, pièces jointes, PDF/A…
            $table->json('par_type');
            $table->unsignedInteger('sauvegarde_fichiers')->nullable();
            $table->unsignedBigInteger('sauvegarde_octets')->nullable();
            $table->unsignedInteger('sauvegarde_manquants')->nullable();
            $table->unsignedBigInteger('disque_libre')->nullable();
            $table->unsignedBigInteger('disque_total')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occupations_stockage');
    }
};
