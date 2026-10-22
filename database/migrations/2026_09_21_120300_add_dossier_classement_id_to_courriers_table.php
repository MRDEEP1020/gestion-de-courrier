<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 3 — un courrier est rangé dans au plus un dossier de classement
// ("le numérique guide le physique", specifications-modules-GEC.md).
// nullOnDelete (jamais cascade) : si un dossier disparaît, le courrier
// redevient simplement non classé, il n'est jamais entraîné avec lui.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->foreignId('dossier_classement_id')->nullable()->after('service_id')
                ->constrained('dossiers_classement')->nullOnDelete();
            $table->index('dossier_classement_id');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dossier_classement_id');
        });
    }
};
