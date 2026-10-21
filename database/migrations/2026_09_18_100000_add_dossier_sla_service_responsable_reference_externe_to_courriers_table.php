<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            // Maquette "Détail du courrier" (2026-09-18, demande explicite
            // de l'utilisateur) — champs réellement nouveaux, distincts de
            // ceux déjà ajoutés pour la maquette "Modifier le courrier"
            // (direction_origine_id/echeance/type_traitement/
            // expediteur_fonction/note_interne, voir migration
            // 2026_09_18_070000). "Date de traitement prévue" de cette
            // maquette RÉUTILISE `echeance` (même concept d'échéance) —
            // pas de doublon. "Service émetteur" de cette maquette
            // RÉUTILISE expediteur_organisation (l'organisation
            // expéditrice externe) — pas un nouveau champ non plus.
            // Tous les champs ci-dessous nullable : net nouveaux sur des
            // courriers déjà existants, jamais rendus required.
            $table->string('dossier_reference')->nullable()->after('note_interne');
            $table->unsignedSmallInteger('sla_jours')->nullable()->after('type_traitement');
            $table->foreignId('service_responsable_id')->nullable()
                ->after('service_id')->constrained('services')->nullOnDelete();
            $table->string('reference_externe')->nullable()->after('numero_tampon_detecte');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_responsable_id');
            $table->dropColumn(['dossier_reference', 'sla_jours', 'reference_externe']);
        });
    }
};
