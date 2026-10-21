<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 5/7 — délai fixe par type de courrier (phase 1, voir PRD.md).
        // seuil_alerte_heures : nombre d'heures avant l'échéance à partir duquel le statut passe à "à risque" (Module 7).
        Schema::create('sla_regles', function (Blueprint $table) {
            $table->id();
            $table->string('type_courrier')->unique();
            $table->unsignedInteger('delai_max_heures');
            $table->unsignedInteger('seuil_alerte_heures')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_regles');
    }
};
