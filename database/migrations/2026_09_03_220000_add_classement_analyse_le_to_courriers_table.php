<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 3 — horodatage de la dernière analyse automatique, posé par
        // IndexCourrierJob même quand aucune règle ne correspond. Permet à la
        // fiche de distinguer « pas encore analysé (job en file d'attente) » de
        // « analysé, aucune règle ne correspond ».
        Schema::table('courriers', function (Blueprint $table) {
            $table->timestamp('classement_analyse_le')->nullable()->after('classement_propose_le');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn('classement_analyse_le');
        });
    }
};
