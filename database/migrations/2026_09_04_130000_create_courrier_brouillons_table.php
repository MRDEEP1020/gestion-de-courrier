<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module 1/2 — flux "scan d'abord" : un document scanné avant qu'aucun
        // Courrier n'existe. Table volontairement séparée de `courriers` (aucune
        // FK), voir DECISIONS.md "Flux scan-first" — un brouillon n'a ni
        // numero_reference, ni service_id, ni objet.
        Schema::create('courrier_brouillons', function (Blueprint $table) {
            $table->id();
            $table->string('fichier_path');
            $table->string('nom_original');
            $table->string('type_mime');
            $table->unsignedBigInteger('taille')->default(0);

            // Même vocabulaire que courriers.ocr_statut (Module 2).
            $table->enum('ocr_statut', ['non_traite', 'en_cours', 'reussi', 'echec_qualite', 'echec'])
                ->default('non_traite');
            $table->longText('texte_ocr')->nullable();
            $table->unsignedTinyInteger('ocr_confiance')->nullable();
            $table->string('numero_tampon_detecte', 160)->nullable();

            $table->foreignId('cree_par_id')->constrained('users')->restrictOnDelete();

            // Posé sous verrou de ligne au moment de la finalisation — empêche
            // qu'une double soumission (double clic, deux onglets) ne tente de
            // déplacer/supprimer le même fichier deux fois (Règle n°3, esprit).
            $table->timestamp('finalise_le')->nullable();

            $table->timestamps();

            $table->index('cree_par_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courrier_brouillons');
    }
};
