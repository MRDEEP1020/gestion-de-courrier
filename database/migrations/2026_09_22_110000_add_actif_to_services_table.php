<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Configuration administrateur — listes de référence
// (specifications-modules-GEC.md, "transversal") : "une suppression d'une
// valeur de liste déjà utilisée par des courriers existants ne doit jamais
// casser les enregistrements existants (désactivation/masquage de la valeur
// plutôt que suppression physique si elle est référencée)". Ajoutée le
// 2026-09-22 pour la nouvelle page "Gestion des services".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('actif')->default(true)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('actif');
        });
    }
};
