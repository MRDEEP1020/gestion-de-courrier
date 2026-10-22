<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 9 — "Décharge" (specifications-modules-GEC.md, point 5) : quand
// quelqu'un emprunte l'original physique d'un courrier archivé, le système
// génère un reçu traçable (qui, quand, où le ranger le retrouver). Jamais
// supprimée (même esprit que courrier_historiques — Règle n°5) : une
// décharge rendue reste dans la table, seule `rendu_le` se renseigne.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decharges', function (Blueprint $table) {
            $table->id();
            // Généré après création à partir de l'id auto-incrémenté
            // (Decharge::booted(), voir le modèle) — DB-atomique comme le
            // numéro de courrier (Règle n°3), mais SANS table de séquence
            // dédiée : contrairement au numéro de référence d'un courrier
            // (Module 1, repère métier principal), celui d'une décharge est
            // un simple reçu secondaire — l'id auto-incrémenté suffit comme
            // source d'unicité, pas besoin de recommencer à 1 chaque année.
            // Nullable : rempli juste après l'insertion (Decharge::booted(),
            // "created") à partir de l'id auto-incrémenté — pas encore
            // connu au moment de l'INSERT lui-même.
            $table->string('numero_reference')->nullable()->unique();
            $table->foreignId('courrier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('emprunteur_id')->constrained('users');
            $table->foreignId('emis_par_id')->constrained('users');
            // Capturée au moment de l'emprunt (pas une FK vers
            // dossiers_classement.reference_localisation_physique) : si le
            // dossier est déplacé plus tard, le reçu déjà remis en main
            // propre doit rester fidèle à ce qui était écrit dessus.
            $table->string('lieu_rangement')->nullable();
            $table->text('motif')->nullable();
            $table->timestamp('emprunte_le');
            $table->timestamp('rendu_le')->nullable();
            $table->timestamps();

            $table->index(['courrier_id', 'rendu_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decharges');
    }
};
