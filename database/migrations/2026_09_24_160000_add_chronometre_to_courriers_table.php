<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            // Module 5 (2026-09-24, voir DECISIONS.md "Chronomètre de
            // traitement") — délai fixé par le responsable de service à la
            // minute près (date_limite reste une DATE, granularité du SLA
            // quotidien et des alertes) : départ, fin prévue, et arrêt à la
            // clôture (traité/rejeté) pour figer le temps réellement passé.
            $table->timestamp('chrono_debut_le')->nullable()->after('date_limite');
            $table->timestamp('chrono_fin_le')->nullable()->after('chrono_debut_le')->index();
            $table->timestamp('chrono_arrete_le')->nullable()->after('chrono_fin_le');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            // SQLite : l'index doit disparaître avant la colonne indexée.
            $table->dropIndex(['chrono_fin_le']);
            $table->dropColumn(['chrono_debut_le', 'chrono_fin_le', 'chrono_arrete_le']);
        });
    }
};
