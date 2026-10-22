<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module "Organisation" v2 (2026-09-22, spec technique complète fournie par
// l'utilisateur) — vraie hiérarchie dynamique Company → Site → Department →
// Service → Sub-service (le niveau Service est OPTIONNEL, spec §1 : "Ne pas
// considérer Service comme un niveau obligatoire" — un Department peut avoir
// directement des utilisateurs rattachés sans Service).
//
// `service_id` est un PONT vers la table `services` EXISTANTE (14 services
// réels déjà utilisés par courriers/utilisateurs) — pas dans la spec
// littérale, mais nécessaire pour intégrer Courriers/Permissions sans
// réécrire toute la chaîne `courriers.service_id` déjà testée (voir le plan
// approuvé : "pont, pas remplacement"). Seuls les nœuds pontés à un service
// réel sont sélectionnables comme destination finale d'un transfert.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('organization_units')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            // company|site|department|service|sub_service
            $table->string('type');
            $table->text('description')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            // active|inactive — jamais de suppression physique (spec §15),
            // seulement une désactivation.
            $table->string('status')->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_units');
    }
};
