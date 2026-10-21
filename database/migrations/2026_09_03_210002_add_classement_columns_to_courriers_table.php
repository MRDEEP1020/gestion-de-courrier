<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 3 — la proposition du système est stockée à part des valeurs
        // saisies (type_document, service_id) : "l'agent peut valider ou corriger
        // la classification proposée". La règle à l'origine est conservée (traçabilité).
        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('classement_statut', ['non_classe', 'propose', 'valide', 'ignore'])
                ->default('non_classe')->after('ocr_traite_le');
            $table->string('type_document_propose')->nullable()->after('classement_statut');
            $table->foreignId('service_propose_id')->nullable()->after('type_document_propose')
                ->constrained('services')->nullOnDelete();
            $table->foreignId('classement_regle_id')->nullable()->after('service_propose_id')
                ->constrained('regles_classement')->nullOnDelete();
            $table->timestamp('classement_propose_le')->nullable()->after('classement_regle_id');

            $table->index('classement_statut');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_propose_id');
            $table->dropConstrainedForeignId('classement_regle_id');
            $table->dropIndex(['classement_statut']);
            $table->dropColumn(['classement_statut', 'type_document_propose', 'classement_propose_le']);
        });
    }
};
