<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Module 1 — demande explicite de l'utilisateur (2026-09-17) : le champ
    // libre unique `expediteur_coordonnees` (téléphone/email/adresse mélangés
    // dans un seul texte) est remplacé par trois colonnes dédiées, même
    // raisonnement que la séparation RC/NIU du 2026-09-07 — chaque donnée
    // affichée séparément sur la fiche courrier plutôt que dans un bloc de
    // texte à relire entièrement pour retrouver un numéro de téléphone.
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('expediteur_telephone')->nullable()->after('expediteur_organisation');
            $table->string('expediteur_email')->nullable()->after('expediteur_telephone');
            $table->string('expediteur_adresse')->nullable()->after('expediteur_email');
            $table->dropColumn('expediteur_coordonnees');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->string('expediteur_coordonnees')->nullable()->after('expediteur_organisation');
            $table->dropColumn(['expediteur_telephone', 'expediteur_email', 'expediteur_adresse']);
        });
    }
};
