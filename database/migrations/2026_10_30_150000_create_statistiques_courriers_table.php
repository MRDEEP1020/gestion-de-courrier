<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 10 étendu — « Dashboard statistiques » façon Power BI (2026-10-30,
// voir DECISIONS.md). Table de FAITS dénormalisée, une ligne par courrier,
// entièrement reconstruite par RefreshStatistiquesJob (Règle n°1 : jointures
// historique/affectations, calcul des délais et de l'étape faits dans le
// job, jamais à l'affichage). La page n'y fait que des totaux indexés, bornés
// au périmètre de l'utilisateur (Règle n°6). Aucune donnée source : la vider
// ne perd rien, le job la reconstruit.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistiques_courriers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('courrier_id')->unique();
            // 0 = sans service (pli confidentiel remis directement).
            $table->unsignedBigInteger('service_id')->default(0)->index();
            $table->unsignedTinyInteger('confidentialite')->default(1);
            $table->boolean('confidentiel_direct')->default(false);
            $table->string('sens', 10);
            $table->string('type_document')->default('');
            $table->string('priorite', 20)->default('');
            $table->string('mode_reception', 40)->default('');
            $table->string('expediteur', 160)->default('');
            $table->date('date_reception')->index();
            $table->char('mois_reception', 7)->index();
            // Clôture = passage au statut « traité » (4 actions, voir le job).
            $table->date('date_cloture')->nullable();
            $table->char('mois_cloture', 7)->nullable()->index();
            $table->unsignedInteger('jours_traitement')->nullable();
            $table->boolean('evaluable')->default(false);
            $table->boolean('dans_les_delais')->default(false);
            // Sortie du stock en cours : clôture OU rejet.
            $table->date('date_sortie')->nullable();
            // Instantané au moment du calcul.
            $table->boolean('en_cours')->default(false)->index();
            $table->string('etape', 40)->default('');
            $table->boolean('en_retard')->default(false);
            $table->unsignedInteger('anciennete_jours')->default(0);
            $table->unsignedBigInteger('collaborateur_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistiques_courriers');
    }
};
