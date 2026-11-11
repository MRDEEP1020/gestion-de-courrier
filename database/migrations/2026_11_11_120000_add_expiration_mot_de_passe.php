<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-11-11 (« je veux que les mots de passe d'utilisateur expirent et ils
// devront le renouveler ») — date du dernier mot de passe choisi par la
// personne (null = jamais choisi par elle : à définir à la prochaine
// connexion) et durée de validité réglée par l'administrateur.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('mot_de_passe_change_le')->nullable();
        });

        Schema::table('parametres', function (Blueprint $table) {
            // 0 = les mots de passe n'expirent jamais.
            $table->unsignedSmallInteger('mot_de_passe_expiration_jours')->default(90);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mot_de_passe_change_le');
        });

        Schema::table('parametres', function (Blueprint $table) {
            $table->dropColumn('mot_de_passe_expiration_jours');
        });
    }
};
