<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Page "Utilisateurs & Accès" reconstruite depuis la maquette fournie par
// l'utilisateur (2026-09-21) : ces 6 colonnes sont les champs affichés par la
// maquette qui n'avaient encore aucune donnée réelle derrière (téléphone,
// poste, photo, statut actif/désactivé, dernière connexion, niveau de
// confidentialité) — toutes nullable/à valeur par défaut sûre pour ne rien
// casser sur les utilisateurs existants.
//
// `niveau_confidentialite` réutilise la MÊME échelle à 3 niveaux que
// `courriers.confidentialite` (voir Courrier::NIVEAUX_CONFIDENTIALITE) — pas
// de nouvelle colonne numérique sur `courriers`, seulement ce niveau MAXIMUM
// côté utilisateur, comparé au niveau du courrier dans CourrierPolicy::view()
// (voir DECISIONS.md "Confidentialité numérique hiérarchique").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telephone')->nullable()->after('email');
            $table->string('poste')->nullable()->after('telephone');
            $table->string('photo_path')->nullable()->after('poste');
            $table->boolean('actif')->default(true)->after('photo_path');
            $table->timestamp('derniere_connexion_le')->nullable()->after('actif');
            $table->unsignedTinyInteger('niveau_confidentialite')->default(1)->after('derniere_connexion_le');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telephone', 'poste', 'photo_path', 'actif', 'derniere_connexion_le', 'niveau_confidentialite']);
        });
    }
};
