<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module "Organisation" v2 (2026-09-22, spec §5) — rattachement de TRAVAIL
// d'un utilisateur à une unité organisationnelle (many-to-many : la spec
// prévoit explicitement qu'un utilisateur puisse appartenir à plusieurs
// unités). `is_primary` distingue l'unité principale ; `role_in_unit` est un
// libellé libre (ex. "Chef de service", "Collaborateur", "Assistant" — voir
// la maquette) plutôt qu'un enum fermé, pour rester fidèle à la variété
// observée dans la capture d'écran fournie.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_unit_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_unit_id')->constrained('organization_units')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('role_in_unit')->nullable();
            $table->timestamps();

            $table->unique(['organization_unit_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_unit_user');
    }
};
