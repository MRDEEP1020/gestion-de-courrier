<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 1 — segment "service" du numéro de référence (ex. GEC-2026-DIR-000123,
        // voir specifications-modules-GEC.md). Code court, unique, saisi par un administrateur.
        Schema::table('services', function (Blueprint $table) {
            $table->string('code', 10)->unique()->after('nom');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
