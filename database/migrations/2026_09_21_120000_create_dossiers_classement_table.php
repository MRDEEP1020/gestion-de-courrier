<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 3 — "Dossiers de classement" (specifications-modules-GEC.md) :
// PREMIER modèle auto-référencé du projet (parent_id). Voir DECISIONS.md
// "Module 3 — dossiers de classement" (2026-09-16) : étape 1 (privilèges)
// déjà faite, ceci est l'étape 2 (modèle + migration).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers_classement', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->text('description')->nullable();

            $table->foreignId('parent_id')->nullable()
                ->constrained('dossiers_classement')->nullOnDelete();

            // Nullable : un dossier transverse (créé par un administrateur)
            // n'est pas forcément rattaché à un seul service.
            $table->foreignId('service_id')->nullable()
                ->constrained('services')->nullOnDelete();

            $table->foreignId('responsable_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // nullOnDelete (jamais cascade) : un dossier ne doit
            // structurellement jamais disparaître avec son créateur (Règle n°5).
            $table->foreignId('cree_par_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Statut DU DOSSIER lui-même — distinct de Courrier::statut
            // ('archive' y est un statut de COURRIER, Module 9).
            $table->string('statut')->default('actif');

            // Module 9 — référence de rangement physique (ex. "Armoire A,
            // Niveau 2"), affichée par le Module 8 (recherche) pour guider
            // la recherche physique de l'original.
            $table->string('reference_localisation_physique')->nullable();

            $table->timestamps();
            $table->softDeletes(); // Règle n°5 — jamais de suppression physique

            $table->index('parent_id');
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossiers_classement');
    }
};
