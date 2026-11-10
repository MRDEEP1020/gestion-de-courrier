<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-10-27 (liste « Phase 7 » de l'utilisateur : « Dark theme: it should
// only appear on the user account, not on all accounts even if logout and
// connect with another ») — l'apparence (clair / sombre / système) est
// enregistrée sur le COMPTE, plus seulement dans le navigateur : le choix d'un
// utilisateur ne s'applique plus à la personne suivante sur le même poste.
// Clair par défaut (thème de la charte GEC, voir DECISIONS.md "Thème clair
// par défaut").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apparence', 10)->default('light')->after('niveau_confidentialite');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('apparence');
        });
    }
};
