<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Module 1 — synchronisation SRS-GEC.pdf (2026-09-15, voir DECISIONS.md) :
    // le service n'est plus saisi par l'agent pour un courrier ENTRANT — il
    // reste inconnu (null) jusqu'à ce que le DGA/ADJ DGA le choisisse au
    // moment de valider le transfert (WorkflowService::validerService()).
    // Le sortant garde un service requis (inchangé, CourrierForm::rules()).
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable(false)->change();
        });
    }
};
