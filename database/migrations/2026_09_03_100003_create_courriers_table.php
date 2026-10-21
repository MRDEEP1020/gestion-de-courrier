<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courriers', function (Blueprint $table) {
            $table->id();

            // Module 1 — numéro de référence : unicité garantie en base (Règle n°3 CONVENTIONS.md),
            // jamais réattribuable, jamais modifiable une fois généré.
            $table->string('numero_reference')->unique();

            $table->enum('sens', ['entrant', 'sortant']);
            $table->date('date_mouvement'); // date de réception ou d'envoi

            $table->string('expediteur_nom')->nullable();
            $table->string('expediteur_organisation')->nullable();
            $table->string('expediteur_coordonnees')->nullable();
            $table->text('destinataire')->nullable();

            $table->string('objet');
            $table->string('type_document'); // ex. lettre, facture, réclamation, demande — clé de correspondance avec sla_regles.type_courrier
            $table->enum('mode_reception', ['depot_physique', 'email', 'poste', 'fax']);

            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();

            // Statuts du circuit — Module 4 (circuit générique unique en phase 1, voir PRD.md)
            $table->enum('statut', [
                'enregistre',
                'affecte',
                'en_traitement',
                'en_validation',
                'traite',
                'archive',
                'rejete',
                'en_attente_information',
            ])->default('enregistre');

            $table->enum('priorite', ['basse', 'normale', 'haute', 'urgente'])->default('normale');
            $table->enum('confidentialite', ['normale', 'confidentiel', 'tres_confidentiel'])->default('normale');

            // Renseigné par ProcessDocumentOcr une fois le scan effectué — jamais modifiable après validation (Règle n°4)
            $table->string('fichier_path')->nullable();

            $table->timestamps();
            $table->softDeletes(); // Règle n°5 — jamais de suppression physique

            $table->index('statut');
            $table->index('type_document');
            $table->index('created_at');
            $table->index('expediteur_nom');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courriers');
    }
};
