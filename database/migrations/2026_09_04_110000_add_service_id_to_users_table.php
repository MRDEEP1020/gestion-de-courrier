<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 4/6 — un Collaborateur (et, en pratique, un Responsable de
        // service) appartient à un service : nécessaire pour affecter un
        // courrier à "un collaborateur de ce service" et pour la file
        // d'attente du responsable. Nullable : un Administrateur n'a pas
        // besoin d'appartenir à un service.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('profil_id')
                ->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });
    }
};
