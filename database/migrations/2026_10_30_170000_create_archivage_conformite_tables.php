<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 9 — conformité de l'archivage électronique (2026-10-30, demande
// utilisateur « fix all of them » après l'audit « est-ce que l'archivage
// fonctionne comme ça » ; voir DECISIONS.md « Archivage : conformité »).
// Uniquement des ajouts : aucune colonne existante modifiée.
return new class extends Migration
{
    public function up(): void
    {
        // 1. Journal des consultations : qui a ouvert ou téléchargé quoi, et
        // quand. Écriture seule (aucune mise à jour, aucune suppression, voir
        // CourrierConsultation).
        Schema::create('courrier_consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained('courriers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->unsignedBigInteger('piece_jointe_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('navigateur', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['courrier_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // 2. Empreintes SHA-256 des fichiers : scellées au premier passage,
        // revérifiées chaque jour (preuve qu'un fichier n'a pas été altéré).
        Schema::create('empreintes_fichiers', function (Blueprint $table) {
            $table->id();
            // 'courrier' (document principal), 'courrier_pdfa' (copie
            // d'archive), 'piece_jointe'.
            $table->string('fichier_type', 20);
            $table->unsignedBigInteger('fichier_id');
            $table->string('chemin');
            $table->char('sha256', 64);
            $table->timestamp('scelle_le');
            $table->timestamp('verifie_le')->nullable();
            // 'ok' | 'alteree' | 'manquant' | 'detruit'
            $table->string('statut', 12)->default('ok')->index();
            $table->unique(['fichier_type', 'fichier_id']);
        });

        // 3. Durées de conservation par type de document (sinon la durée
        // par défaut de Paramètres système).
        Schema::create('durees_conservation', function (Blueprint $table) {
            $table->id();
            $table->string('type_document')->unique();
            $table->unsignedSmallInteger('annees');
            $table->timestamps();
        });

        Schema::table('courriers', function (Blueprint $table) {
            // 4. Copie d'archive au format PDF/A-2b (l'original reste intact).
            $table->string('fichier_pdfa_path')->nullable();
            // 5. Conservation : date d'archivage, échéance, prolongations,
            // gel juridique (bloque toute destruction).
            $table->timestamp('archive_le')->nullable();
            $table->date('conserver_jusqua')->nullable()->index();
            $table->unsignedSmallInteger('prolongation_annees')->default(0);
            $table->boolean('gel_juridique')->default(false);
        });

        Schema::table('parametres', function (Blueprint $table) {
            // 10 ans : durée usuelle de conservation des pièces commerciales
            // et comptables (OHADA), modifiable depuis Paramètres système.
            $table->unsignedSmallInteger('conservation_annees_defaut')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('parametres', fn (Blueprint $table) => $table->dropColumn('conservation_annees_defaut'));
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropIndex(['conserver_jusqua']);
            $table->dropColumn(['fichier_pdfa_path', 'archive_le', 'conserver_jusqua', 'prolongation_annees', 'gel_juridique']);
        });
        Schema::dropIfExists('durees_conservation');
        Schema::dropIfExists('empreintes_fichiers');
        Schema::dropIfExists('courrier_consultations');
    }
};
