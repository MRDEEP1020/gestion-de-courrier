<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-11-10 — la délégation DGA/Secrétaire/RH a disparu avec le routage DGA
// le 2026-10-23 (transfert direct au service) ; seule sa table était restée.
// Retirée à la demande de l'utilisateur (« why don't remove it ») : aucune
// clé étrangère, aucun historique ni notification ne s'y rapporte (DECISIONS.md,
// « Délégation DG retirée entièrement »). down() recrée la structure vide.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('delegations_dga');
    }

    public function down(): void
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
};
