<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Module 1 — demande explicite de l'utilisateur (2026-09-07) : le RC
    // (Registre du Commerce) et le NIU (Numéro d'Identifiant Unique
    // fiscal), identifiants légaux quasi systématiques en pied/en-tête d'un
    // courrier commercial camerounais, distincts des simples coordonnées de
    // contact (expediteur_coordonnees) — colonnes dédiées plutôt que
    // mélangées dans un champ texte libre.
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('expediteur_rc')->nullable()->after('expediteur_coordonnees');
            $table->string('expediteur_niu')->nullable()->after('expediteur_rc');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn(['expediteur_rc', 'expediteur_niu']);
        });
    }
};
