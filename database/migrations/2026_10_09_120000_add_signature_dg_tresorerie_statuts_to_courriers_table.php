<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 2026-10-09 — extension du circuit générique (Signature DG / Trésorerie,
    // voir DECISIONS.md, entretien terrain Sinistres) : deux nouvelles
    // valeurs ajoutées à l'ENUM existant, aucune renommée/retirée — pas
    // besoin du garde-fou élargir/migrer/rétrécir des migrations ENUM
    // précédentes (ex. 2026_09_15_150000), puisqu'aucune ligne existante ne
    // porte déjà ces valeurs.
    private const ANCIENS = [
        'enregistre', 'en_attente_de_transfert', 'en_cours_de_transfert', 'affecte',
        'en_traitement', 'en_validation', 'traite', 'archive', 'rejete', 'en_attente_information',
    ];

    private const NOUVEAUX = [
        ...self::ANCIENS, 'en_attente_signature_dg', 'en_attente_tresorerie',
    ];

    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('statut', self::NOUVEAUX)->default('enregistre')->change();
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->enum('statut', self::ANCIENS)->default('enregistre')->change();
        });
    }
};
