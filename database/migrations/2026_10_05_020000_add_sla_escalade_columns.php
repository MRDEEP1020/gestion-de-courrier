<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 7 — 2e palier d'alerte SLA, "l'escalade vers le responsable
// hiérarchique configurable selon le niveau de retard" (specifications-
// modules-GEC.md, Module 7, règles métier). Même patron que
// 2026_09_23_140000_add_sla_columns_to_courriers_table.php (alerte_risque_le/
// alerte_retard_le) : un marqueur anti-doublon par courrier + un seuil
// configurable sur la ligne singleton "parametres".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->timestamp('alerte_escalade_le')->nullable()->after('alerte_retard_le');
        });

        Schema::table('parametres', function (Blueprint $table) {
            // Nombre de jours de retard après lesquels l'alerte "en retard"
            // escalade au niveau hiérarchique supérieur (en plus du
            // collaborateur assigné + responsable de service déjà notifiés
            // dès le dépassement). Nullable = escalade désactivée.
            $table->unsignedInteger('sla_escalade_jours')->nullable()->after('sla_relance_jours');
        });
    }

    public function down(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->dropColumn('sla_escalade_jours');
        });

        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn('alerte_escalade_le');
        });
    }
};
