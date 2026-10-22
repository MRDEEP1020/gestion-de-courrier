<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 6 — journal append-only des (ré)affectations.
        // L'affectation en cours d'un courrier = dernière ligne (par created_at) pour ce courrier_id.
        Schema::create('affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // collaborateur affecté
            $table->foreignId('affecte_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif_reaffectation')->nullable(); // obligatoire en pratique pour toute ré-affectation (validation côté application)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['courrier_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affectations');
    }
};
