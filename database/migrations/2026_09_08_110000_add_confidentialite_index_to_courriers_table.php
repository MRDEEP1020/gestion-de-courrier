<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Module 8 (recherche multi-critères) — Règle n°3 CONVENTIONS.md : index
    // obligatoire sur toute colonne utilisée dans un filtre de recherche.
    // confidentialite, nouveau filtre de CourrierList (demande explicite de
    // l'utilisateur, 2026-09-08 : "type document (catégorie de document
    // (confidentiel etc))"), n'était pas indexée — comparaison d'égalité
    // simple (pas un LIKE à joker), donc un index B-tree classique suffit
    // pleinement ici (contrairement à texte_ocr, qui a nécessité un
    // FULLTEXT dédié).
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->index('confidentialite');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropIndex(['confidentialite']);
        });
    }
};
