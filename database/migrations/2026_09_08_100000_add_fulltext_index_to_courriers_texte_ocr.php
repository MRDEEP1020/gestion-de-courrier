<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Module 8 — "recherche ... plein-texte si le temps le permet" (PRD.md) :
    // demande explicite de l'utilisateur (2026-09-08) — retrouver un
    // courrier par un fragment de texte OCR (montant, capital social, nom
    // d'organisation...) même quand l'expéditeur/objet exact est oublié.
    // Règle n°3 CONVENTIONS.md : index obligatoire sur toute colonne utilisée
    // dans un filtre de recherche — un simple `LIKE '%terme%'` (joker en
    // tête) ne peut utiliser AUCUN index B-tree classique, d'où un index
    // FULLTEXT dédié (voir CourrierList::resultats() pour la requête
    // MATCH()/AGAINST() associée). SQLite (utilisé par la suite de tests,
    // voir phpunit.xml) n'a pas d'équivalent FULLTEXT — ignoré ici, la
    // requête bascule sur un LIKE simple dans cet environnement (dataset de
    // test toujours minuscule, aucun souci de performance).
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('courriers', function (Blueprint $table) {
            $table->fullText('texte_ocr');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('courriers', function (Blueprint $table) {
            $table->dropFullText(['texte_ocr']);
        });
    }
};
