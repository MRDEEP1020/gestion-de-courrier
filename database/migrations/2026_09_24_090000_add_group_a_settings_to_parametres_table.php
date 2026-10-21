<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Group A" (2026-09-24, voir DECISIONS.md "Paramètres système
// configurables — Groupe A/B") : 5 réglages numériques supplémentaires,
// tous jusqu'ici des `public const` codées en dur, tous déjà lus
// dynamiquement partout où ils comptent (jamais de "5"/"600"/"90" recopié
// ailleurs) — mêmes valeurs par défaut que les constantes remplacées,
// aucun changement de comportement au déploiement.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            // Module 9 — User::NIVEAU_CONFIDENTIALITE_MAX (échelle de confidentialité).
            $table->unsignedTinyInteger('niveau_confidentialite_max')->default(5);
            // Module 2 — ScanForm::RESOLUTION_MINIMALE (px, côté le plus court).
            $table->unsignedSmallInteger('scan_resolution_minimale')->default(600);
            // Module 2 — ProcessDocumentOcr::CONFIANCE_MINIMALE (%).
            $table->unsignedTinyInteger('ocr_confiance_minimale')->default(55);
            // Module 2 — ProcessDocumentOcr::LONGUEUR_MINIMALE_TEXTE (caractères).
            $table->unsignedSmallInteger('ocr_longueur_minimale_texte')->default(20);
            // Module 10 — RefreshDashboardStatsJob::PERIODE_JOURS ("délai moyen").
            $table->unsignedSmallInteger('dashboard_delai_moyen_periode_jours')->default(90);
        });

        DB::table('parametres')->where('id', 1)->update([
            'niveau_confidentialite_max' => 5,
            'scan_resolution_minimale' => 600,
            'ocr_confiance_minimale' => 55,
            'ocr_longueur_minimale_texte' => 20,
            'dashboard_delai_moyen_periode_jours' => 90,
        ]);
    }

    public function down(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->dropColumn([
                'niveau_confidentialite_max',
                'scan_resolution_minimale',
                'ocr_confiance_minimale',
                'ocr_longueur_minimale_texte',
                'dashboard_delai_moyen_periode_jours',
            ]);
        });
    }
};
