<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 3/5 — historique append-only d'un dossier de classement, même
// principe que courrier_historiques (Règle n°5) : table dédiée, pas
// polymorphique (convention "une table par concept" déjà établie par
// affectations/courrier_brouillons).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_classement_historiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dossier_classement_id')->constrained('dossiers_classement')->cascadeOnDelete();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // creation, renommage, deplacement, partage_ajoute, partage_retire, suppression
            $table->text('commentaire')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Nom explicite : l'auto-généré ("dossier_classement_historiques_
            // dossier_classement_id_created_at_index") dépasse la limite de
            // 64 caractères d'un identifiant MySQL.
            $table->index(['dossier_classement_id', 'created_at'], 'dossier_classement_hist_dossier_id_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_classement_historiques');
    }
};
