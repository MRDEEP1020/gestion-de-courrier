<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Module 1/4 — demande explicite de l'utilisateur (2026-09-08) : un
    // courrier entrant non-sinistre passe par une validation DGA/ADJ (voir
    // WorkflowService::validerService()) avant de rejoindre la file du
    // responsable de service — nouveau statut intermédiaire. Voir
    // DECISIONS.md "Circuit courrier entrant : validation DGA/ADJ".
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('statut', [
                'enregistre',
                'en_attente_validation_dga',
                'affecte',
                'en_traitement',
                'en_validation',
                'traite',
                'archive',
                'rejete',
                'en_attente_information',
            ])->default('enregistre')->change();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('statut', [
                'enregistre',
                'affecte',
                'en_traitement',
                'en_validation',
                'traite',
                'archive',
                'rejete',
                'en_attente_information',
            ])->default('enregistre')->change();
        });
    }
};
