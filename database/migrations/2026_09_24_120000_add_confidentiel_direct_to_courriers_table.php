<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            // Module 1/9 (2026-09-24, voir DECISIONS.md "Courrier confidentiel :
            // accès et clôture par le destinataire") — marque EXPLICITE d'un
            // pli enregistré via RegistrationFormConfidentiel (jamais ouvert,
            // envoyé directement à une personne), plutôt que de le déduire de
            // service_id null + destinataire_transfert_id + statut : c'est sur
            // ce drapeau que reposent l'accès du destinataire (CourrierPolicy)
            // et la clôture "Marquer comme remis" (WorkflowService).
            $table->boolean('confidentiel_direct')->default(false)->after('confidentialite');
        });

        // Rattrapage des plis confidentiels déjà enregistrés : seul ce flux
        // crée un courrier 'enregistre' SANS service mais AVEC un destinataire
        // (un courrier normal reçoit toujours son service à la validation DGA,
        // un sinistre dès l'enregistrement) et sans document scanné.
        DB::table('courriers')
            ->where('statut', 'enregistre')
            ->whereNull('service_id')
            ->whereNotNull('destinataire_transfert_id')
            ->whereNull('fichier_path')
            ->update(['confidentiel_direct' => true]);
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn('confidentiel_direct');
        });
    }
};
