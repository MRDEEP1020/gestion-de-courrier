<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Module 8 (recherche multi-critères) — Règle n°3 CONVENTIONS.md : index
    // obligatoire sur toute colonne utilisée dans un filtre de recherche.
    // date_mouvement, filtrable par plage dans CourrierList, n'avait jamais
    // été indexée (seuls statut/type_document/created_at/expediteur_nom
    // l'étaient à la création de la table).
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->index('date_mouvement');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropIndex(['date_mouvement']);
        });
    }
};
