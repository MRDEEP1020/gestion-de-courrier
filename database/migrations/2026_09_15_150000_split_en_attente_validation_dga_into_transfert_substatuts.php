<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Module 1/4 — synchronisation SRS-GEC.pdf (2026-09-15, voir DECISIONS.md) :
    // le transfert réceptionniste → DGA/ADJ DGA suit désormais 3 sous-statuts
    // explicites (au lieu du seul 'en_attente_validation_dga' du 2026-09-08) :
    //   - en_attente_de_transfert : courrier enregistré, réceptionniste n'a
    //     pas encore cliqué "Transférer" (WorkflowService::transferer()).
    //   - en_cours_de_transfert : réceptionniste a transféré, en attente que
    //     le DGA valide le service (WorkflowService::validerService(), déjà
    //     existant, inchangé dans son fonctionnement).
    // "Transféré" (l'étape 3 du document) correspond au statut 'enregistre'
    // déjà existant — pas de nouvelle valeur pour lui, cohérent avec la
    // décision du 2026-09-08 de ne jamais renommer une valeur d'ENUM déjà en
    // place.
    //
    // Technique en 3 étapes (zéro perte de donnée sur les lignes déjà en
    // 'en_attente_validation_dga') : élargir l'ENUM (ancien + nouveaux),
    // migrer les données, puis rétrécir au jeu final — une bascule directe
    // ferait planter/tronquer silencieusement les lignes existantes dont la
    // valeur n'est plus dans le nouvel ENUM.
    private const ANCIENS = [
        'enregistre', 'en_attente_validation_dga', 'affecte', 'en_traitement',
        'en_validation', 'traite', 'archive', 'rejete', 'en_attente_information',
    ];

    private const NOUVEAUX = [
        'enregistre', 'en_attente_de_transfert', 'en_cours_de_transfert', 'affecte',
        'en_traitement', 'en_validation', 'traite', 'archive', 'rejete', 'en_attente_information',
    ];

    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('statut', array_unique([...self::ANCIENS, ...self::NOUVEAUX]))->default('enregistre')->change();
        });

        // Déjà "envoyées" côté réceptionniste dans l'ancien système (aucune
        // étape "Transférer" n'existait avant ce jour) — la valeur la plus
        // proche est "en cours de transfert" (visible dans la file du DGA),
        // pas "en attente de transfert" (invisible pour le DGA tant que la
        // réceptionniste n'a pas cliqué "Transférer").
        DB::table('courriers')
            ->where('statut', 'en_attente_validation_dga')
            ->update(['statut' => 'en_cours_de_transfert']);

        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('statut', self::NOUVEAUX)->default('enregistre')->change();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('statut', array_unique([...self::ANCIENS, ...self::NOUVEAUX]))->default('enregistre')->change();
        });

        DB::table('courriers')
            ->whereIn('statut', ['en_attente_de_transfert', 'en_cours_de_transfert'])
            ->update(['statut' => 'en_attente_validation_dga']);

        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('statut', self::ANCIENS)->default('enregistre')->change();
        });
    }
};
