<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-10-28 (« je veux qu'elle soit modifiable à partir de l'interface ») —
// textes du modèle de décharge de remise de l'original, modifiables dans
// Paramètres système. NULL = modèle par défaut (Parametre::modeleDechargeParDefaut()) :
// aucun changement d'affichage au déploiement.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->json('decharge_modele')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->dropColumn('decharge_modele');
        });
    }
};
