<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            // Maquette "Modifier le courrier" (2026-09-18, demande explicite
            // de l'utilisateur) — champs réellement nouveaux, distincts des
            // champs existants déjà relabellés dans cette même maquette
            // (Type = sens, Catégorie = type_document, Service émetteur =
            // service_id — voir editForm.blade.php). Tous nullable : champ
            // net nouveau sur des courriers déjà existants, jamais rendu
            // required tant qu'aucune donnée réelle n'existe pour eux.
            $table->foreignId('direction_origine_id')->nullable()
                ->after('service_id')->constrained('services')->nullOnDelete();
            $table->date('echeance')->nullable()->after('date_mouvement');
            $table->string('type_traitement')->nullable()->after('mode_reception');
            $table->string('expediteur_fonction')->nullable()->after('expediteur_nom');
            $table->text('note_interne')->nullable()->after('destinataire');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('direction_origine_id');
            $table->dropColumn(['echeance', 'type_traitement', 'expediteur_fonction', 'note_interne']);
        });
    }
};
