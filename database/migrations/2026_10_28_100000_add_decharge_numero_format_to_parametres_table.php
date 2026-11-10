<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-10-28 (« le numéro de référence de la décharge doit être modifiable »,
// précisé : le format) — préfixe et nombre de chiffres du numéro de décharge
// (ACC-2026-000006), modifiables dans Paramètres système comme le numéro de
// référence des courriers. Valeurs par défaut = format actuel : aucun
// changement au déploiement, les numéros déjà émis ne changent jamais.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->string('decharge_numero_prefixe', 10)->default('ACC');
            $table->unsignedTinyInteger('decharge_numero_chiffres')->default(6);
        });
    }

    public function down(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->dropColumn(['decharge_numero_prefixe', 'decharge_numero_chiffres']);
        });
    }
};
