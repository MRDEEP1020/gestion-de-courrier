<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-10-28 (« la possibilité de mettre le nom comme signature au cas où on
// ne veut pas imprimer ») — signature électronique de la réception de
// l'original par le service. La signature de la remise par l'accueil reste
// original_remis_par_id / original_remis_le (déjà existants).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transferts_service', function (Blueprint $table) {
            $table->foreignId('reception_signee_par_id')->nullable()->after('original_remis_le')->constrained('users')->nullOnDelete();
            $table->timestamp('reception_signee_le')->nullable()->after('reception_signee_par_id');
        });
    }

    public function down(): void
    {
        Schema::table('transferts_service', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reception_signee_par_id');
            $table->dropColumn('reception_signee_le');
        });
    }
};
