<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 5 — "sla_regles" (migration 2026_09_03_100006) était la conception
// Phase 1 d'origine du délai SLA par type (heures, table dédiée) ;
// supplantée le 2026-09-23 par Parametre::sla_par_type (jours, ligne
// singleton de "parametres"). Table jamais lue/écrite nulle part dans
// app/ depuis — confirmé par audit de code (2026-10-05) avant suppression.
// Nouvelle migration plutôt qu'édition de l'ancienne : ne jamais modifier
// une migration déjà appliquée sur la vraie base (voir notes internes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sla_regles');
    }

    public function down(): void
    {
        Schema::create('sla_regles', function (Blueprint $table) {
            $table->id();
            $table->string('type_courrier')->unique();
            $table->unsignedInteger('delai_max_heures');
            $table->unsignedInteger('seuil_alerte_heures')->nullable();
            $table->timestamps();
        });
    }
};
