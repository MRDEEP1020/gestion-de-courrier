<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Module 1 — synchronisation SRS-GEC.pdf (2026-09-15, voir DECISIONS.md) :
    // le numéro de référence ne dépend plus du service (GEC-{annee}-{service}-{seq}
    // devient GEC-{annee}-{seq}), puisque le service peut être inconnu au
    // moment de la génération pour un courrier entrant — voir
    // NumeroReferenceGenerator. Le compteur devient global par année.
    public function up(): void
    {
        // Vide la table AVANT de restructurer : plusieurs lignes par année
        // (une par service) existent déjà sous l'ancien schéma et
        // collisionneraient avec la nouvelle contrainte unique sur `annee`
        // seule. Pure table de compteur interne (pas de donnée métier, les
        // numéros de référence déjà générés restent valides et uniques quel
        // que soit l'état de ce compteur) — repartir de zéro est sans risque.
        DB::table('numero_sequences')->delete();

        // Idempotent par étape (pas juste un bloc Schema::table unique) :
        // MySQL exécute chaque opération de schéma comme une instruction DDL
        // séparée, jamais annulée en cas d'échec d'une instruction suivante
        // (pas de rollback transactionnel DDL) — une tentative précédente qui
        // aurait échoué APRÈS avoir déjà supprimé service_id (ex. contrainte
        // unique en collision avec des données non vidées) laisserait sinon
        // cette migration irrécupérable au prochain `artisan migrate`.
        if (Schema::hasColumn('numero_sequences', 'service_id')) {
            Schema::table('numero_sequences', function (Blueprint $table) {
                $table->dropUnique(['annee', 'service_id']);
                $table->dropForeign(['service_id']);
                $table->dropColumn('service_id');
            });
        }

        if (! collect(Schema::getIndexes('numero_sequences'))->contains('name', 'numero_sequences_annee_unique')) {
            Schema::table('numero_sequences', function (Blueprint $table) {
                $table->unique('annee');
            });
        }
    }

    public function down(): void
    {
        Schema::table('numero_sequences', function (Blueprint $table) {
            $table->dropUnique(['annee']);
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->unique(['annee', 'service_id']);
        });
    }
};
