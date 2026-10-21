<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module "Organisation" v2 (2026-09-22, spec §19 "Périmètre d'accès") — DIFFÉRENT
// de organization_unit_user (rattachement de travail, spec §5) : un utilisateur
// peut TRAVAILLER dans "Sinistre Santé" mais avoir un périmètre de VISIBILITÉ
// (pour les courriers) élargi à toute "Agence Douala" — deux relations
// distinctes. Copie exacte du patron dossier_classement_user (composite PK,
// pas de colonnes ni timestamps — un simple octroi, pas un rôle).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_unit_perimetre_user', function (Blueprint $table) {
            $table->foreignId('organization_unit_id')->constrained('organization_units')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['organization_unit_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_unit_perimetre_user');
    }
};
