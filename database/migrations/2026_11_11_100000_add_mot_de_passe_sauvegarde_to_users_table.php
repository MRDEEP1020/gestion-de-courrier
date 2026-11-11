<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-11-11 — copie de secours sur le PC : chaque personne choisit un « mot
// de passe de sauvegarde » (distinct de celui de connexion) qui ouvre SA
// copie, limitée à ce qu'elle voit dans GEC (App\Services\SauvegardeLocale).
// Gardé chiffré (cast « encrypted », clé de l'application) : GEC doit
// pouvoir le relire pour chiffrer les ZIP sans que la personne soit connectée.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mot_de_passe_sauvegarde')->nullable();
            $table->timestamp('mot_de_passe_sauvegarde_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mot_de_passe_sauvegarde', 'mot_de_passe_sauvegarde_le']);
        });
    }
};
