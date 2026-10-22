<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Modale "Gestion des permissions" de la maquette (2026-09-21) : chaque
// permission y affiche une étiquette Lecture/Écriture/Administratif — non
// déductible de façon fiable depuis la seule `cle` existante (convention de
// nommage informelle, pas un contrat). Colonne réelle + rétro-remplissage
// pour les 26 privilèges déjà seedés, PrivilegeSeeder mis à jour en parallèle
// pour que tout futur `migrate:fresh --seed` reste correct sans repasser ici.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('privileges', function (Blueprint $table) {
            $table->enum('type', ['lecture', 'ecriture', 'administratif'])->default('ecriture')->after('description');
        });

        DB::table('privileges')->where('cle', 'like', '%voir_%')->orWhere('cle', 'courriers.rechercher')->orWhere('cle', 'dashboard.taches_du_jour')
            ->update(['type' => 'lecture']);

        DB::table('privileges')->where('cle', 'like', '%gerer%')
            ->update(['type' => 'administratif']);
    }

    public function down(): void
    {
        Schema::table('privileges', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
