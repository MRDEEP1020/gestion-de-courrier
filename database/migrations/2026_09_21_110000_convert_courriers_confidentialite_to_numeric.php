<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Demande explicite de l'utilisateur (2026-09-21) : "the niveau should be
// numbers not confidential or what ever but numbers 1,2,3,4,5 etc" —
// échelle élargie de 3 à 5 niveaux, ET affichée comme un NOMBRE partout
// (plus de libellés "Normale"/"Confidentiel"/"Très confidentiel"). Cette
// colonne était un enum string comparé via Courrier::NIVEAUX_CONFIDENTIALITE
// (table de correspondance) — désormais directement un entier, comparé
// numériquement sans table de correspondance.
//
// Colonne temporaire + bascule plutôt que `->change()` (nécessite
// doctrine/dbal, non installé) — fonctionne identiquement sur MySQL et
// SQLite (tests). Mapping des 3 valeurs existantes vers le bas de la
// nouvelle échelle à 5 niveaux : normale=1, confidentiel=2,
// tres_confidentiel=3 — les niveaux 4/5 sont de nouveaux paliers plus
// stricts, jamais utilisés par les données existantes tant que personne
// ne les choisit explicitement.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->unsignedTinyInteger('confidentialite_niveau')->default(1)->after('confidentialite');
        });

        DB::table('courriers')->where('confidentialite', 'normale')->update(['confidentialite_niveau' => 1]);
        DB::table('courriers')->where('confidentialite', 'confidentiel')->update(['confidentialite_niveau' => 2]);
        DB::table('courriers')->where('confidentialite', 'tres_confidentiel')->update(['confidentialite_niveau' => 3]);

        // L'ancienne colonne `confidentialite` avait un index dédié
        // (2026_09_08_110000_add_confidentialite_index_to_courriers_table) —
        // DOIT être retiré avant dropColumn() (SQLite refuse de supprimer
        // une colonne encore indexée, contrairement à MySQL qui l'aurait
        // toléré ; trouvé en lançant les tests). Recréé sur la nouvelle
        // colonne renommée juste après (Règle n°3, toujours indexer une
        // colonne de filtre).
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropIndex(['confidentialite']);
        });

        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn('confidentialite');
        });

        Schema::table('courriers', function (Blueprint $table) {
            $table->renameColumn('confidentialite_niveau', 'confidentialite');
        });

        Schema::table('courriers', function (Blueprint $table) {
            $table->index('confidentialite');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('confidentialite_str', ['normale', 'confidentiel', 'tres_confidentiel'])->default('normale')->after('confidentialite');
        });

        DB::table('courriers')->where('confidentialite', 1)->update(['confidentialite_str' => 'normale']);
        DB::table('courriers')->where('confidentialite', '>=', 2)->update(['confidentialite_str' => 'confidentiel']);
        DB::table('courriers')->where('confidentialite', '>=', 3)->update(['confidentialite_str' => 'tres_confidentiel']);

        Schema::table('courriers', function (Blueprint $table) {
            $table->dropIndex(['confidentialite']);
        });

        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn('confidentialite');
        });

        Schema::table('courriers', function (Blueprint $table) {
            $table->renameColumn('confidentialite_str', 'confidentialite');
        });

        Schema::table('courriers', function (Blueprint $table) {
            $table->index('confidentialite');
        });
    }
};
