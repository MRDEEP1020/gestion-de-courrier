<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Module 1/5/7 (2026-09-23, voir DECISIONS.md "Paramètres système
// configurables") — "les petites choses qui demandaient normalement du code
// doivent être configurables depuis l'UI" (demande explicite de
// l'utilisateur, format du numéro de référence donné en exemple). Table
// SINGLETON (une seule ligne, id=1 créée ici) plutôt qu'un magasin
// clé/valeur générique : peu de champs, tous typés, même convention que le
// reste du projet (pas de JSON fourre-tout sauf pour sla_par_type, qui est
// une vraie carte type→jours de longueur variable — même pattern déjà
// utilisé par RegleClassement::mots_cles/champs/tags).
//
// Horodatage DÉLIBÉRÉMENT antérieur à 2026_09_23_140000_add_sla_columns_to_
// courriers_table (déjà appliquée) : son rattrapage appelle
// SlaCalculatorService::calculerDateLimite(), qui lit désormais
// App\Models\Parametre — sur une installation neuve, cette table doit donc
// exister AVANT que cette migration-là ne s'exécute.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametres', function (Blueprint $table) {
            $table->id();

            // Module 1 — numéro de référence (App\Services\NumeroReferenceGenerator).
            // Format actuel : "{prefixe}-{année}-{séquence paddée}", ex. GEC-2026-000123.
            $table->string('numero_reference_prefixe', 10)->default('GEC');
            $table->unsignedTinyInteger('numero_reference_chiffres_sequence')->default(6);

            // Module 5/7 — SLA et alertes (App\Services\SlaCalculatorService,
            // App\Jobs\SendMailAlertJob) — remplace config/gec.php.
            $table->unsignedSmallInteger('sla_jours_defaut')->default(10);
            $table->unsignedSmallInteger('sla_seuil_risque_jours')->default(2);
            $table->unsignedSmallInteger('sla_relance_jours')->default(2);
            // Carte "type_document" (valeur exacte de CourrierForm::TYPES_DOCUMENT)
            // => délai en jours. Vide par défaut — comportement inchangé
            // (repli sur sla_jours_defaut, voir SlaCalculatorService::delaiJours()).
            $table->json('sla_par_type')->nullable();

            $table->timestamps();
        });

        // Ligne singleton créée ici (pas via firstOrCreate() paresseux au
        // premier accès) : garantit qu'elle existe dès la migration, jamais
        // de course au premier chargement de page après un déploiement.
        DB::table('parametres')->insert([
            'id' => 1,
            'numero_reference_prefixe' => 'GEC',
            'numero_reference_chiffres_sequence' => 6,
            'sla_jours_defaut' => 10,
            'sla_seuil_risque_jours' => 2,
            'sla_relance_jours' => 2,
            'sla_par_type' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres');
    }
};
