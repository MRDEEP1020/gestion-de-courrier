<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 1/4 — délégation DGA/ADJ → RH quand les deux sont absents en même
// temps (2026-10-06, entretien terrain réceptionniste, voir DECISIONS.md
// "Entretien terrain réceptionniste" et "Délégation DGA/ADJ absents").
// Toggle manuel uniquement (choix explicite de l'utilisateur, pas de
// détection automatique d'absence) : une délégation est nominative
// (delegant → delegataire), jamais supprimée physiquement (Règle n°5,
// même esprit que `decharges` : désactivée en place via `actif`/
// `desactive_le`, jamais retirée de la table).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delegations_dga', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegant_id')->constrained('users');
            $table->foreignId('delegataire_id')->constrained('users');
            $table->string('motif')->nullable();
            $table->dateTime('debut_le');
            $table->dateTime('fin_le')->nullable();
            $table->boolean('actif')->default(true);
            $table->foreignId('active_par_id')->constrained('users');
            $table->foreignId('desactive_par_id')->nullable()->constrained('users');
            $table->dateTime('desactive_le')->nullable();
            $table->timestamps();

            $table->index(['delegataire_id', 'actif']);
            $table->index(['delegant_id', 'actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delegations_dga');
    }
};
