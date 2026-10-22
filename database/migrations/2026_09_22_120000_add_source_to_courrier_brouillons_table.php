<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courrier_brouillons', function (Blueprint $table) {
            $table->string('source')->default('manuel')->after('cree_par_id');
        });
    }

    public function down(): void
    {
        Schema::table('courrier_brouillons', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
