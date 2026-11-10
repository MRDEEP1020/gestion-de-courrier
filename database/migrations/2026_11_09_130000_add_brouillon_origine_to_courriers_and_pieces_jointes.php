<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 1/2 — rangement sur le poste des scans du dossier surveillé
// (2026-11-09, voir DECISIONS.md « Scans conservés et rangés sur le poste de
// numérisation ») : le brouillon est supprimé une fois le courrier
// enregistré, le lien « ce document vient du scan n° X » est donc gardé sur
// le courrier (document principal) et sur la pièce jointe. Pas de clé
// étrangère : la ligne de brouillon n'existe plus.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->unsignedBigInteger('brouillon_origine_id')->nullable()->index();
        });

        Schema::table('pieces_jointes', function (Blueprint $table) {
            $table->unsignedBigInteger('brouillon_origine_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('pieces_jointes', function (Blueprint $table) {
            $table->dropIndex(['brouillon_origine_id']);
            $table->dropColumn('brouillon_origine_id');
        });

        Schema::table('courriers', function (Blueprint $table) {
            $table->dropIndex(['brouillon_origine_id']);
            $table->dropColumn('brouillon_origine_id');
        });
    }
};
