<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module "Organisation" v2 (2026-09-22) — annule l'extension de `services`
// du tour précédent (parent_id/type/soft deletes), remplacée par une vraie
// table dédiée `organization_units` (voir cette migration). Aucune perte de
// donnée : les 14 services réels restent des lignes `services` intactes,
// seules ces 2 colonnes disparaissent.
return new class extends Migration
{
    public function up(): void
    {
        // Gardes d'existence (2026-10-08) — récupération après un incident
        // réel (migrate:fresh accidentel sur la vraie base `gec`, voir
        // le journal de développement) qui avait laissé cette migration appliquée à
        // moitié (deleted_at/type déjà supprimés, parent_id/son index/sa FK
        // pas encore) : sans ces gardes, up() replanterait immédiatement
        // sur une colonne déjà absente avant même d'atteindre la partie
        // réellement encore à faire. N'affecte aucun environnement sain
        // (chaque condition est vraie avant le drop dans le cas normal).
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('services', 'type')) {
                $table->dropColumn('type');
            }
        });

        if (Schema::hasColumn('services', 'parent_id')) {
            // 2026-10-08 — ORDRE CORRIGÉ après l'incident ci-dessus : l'ancien
            // dropIndex() avant dropConstrainedForeignId() plantait sur MySQL
            // réel ("Cannot drop index ... needed in a foreign key
            // constraint") — MySQL refuse de supprimer un index tant que la
            // contrainte FK qui s'appuie dessus existe encore. Jamais
            // déclenché avant cet incident car cette migration n'avait
            // jusqu'ici tourné que sur SQLite (tests, phpunit.xml) puis une
            // seule fois sur MySQL réel avec une version antérieure du
            // fichier. Ordre correct : dropForeign (la contrainte seule)
            // d'abord, PUIS dropIndex (plus rien ne s'appuie dessus) — SQLite
            // reste couvert, dropIndex s'exécute toujours avant dropColumn
            // (voir notes internes, même
            // piège : SQLite ne supprime pas l'index automatiquement avec la
            // colonne, contrairement à MySQL).
            Schema::table('services', function (Blueprint $table) {
                $table->dropForeign(['parent_id']);
            });

            Schema::table('services', function (Blueprint $table) {
                $table->dropIndex(['parent_id']);
            });

            Schema::table('services', function (Blueprint $table) {
                $table->dropColumn('parent_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')
                ->constrained('services')->nullOnDelete();
            $table->string('type')->default('service')->after('parent_id');
            $table->softDeletes();
            $table->index('parent_id');
        });
    }
};
