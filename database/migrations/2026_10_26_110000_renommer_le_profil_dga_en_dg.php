<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 2026-10-26, demande explicite de l'utilisateur (« it is dg not dga », puis
// « fais-les ») — le profil « DGA » est celui du Directeur Général : renommé
// « DG ». Données uniquement ; les privilèges et les utilisateurs restent
// attachés au même profil (même id). Sans effet si le profil n'existe pas
// encore (base neuve : ProfilSeeder crée directement « DG ») ou si un profil
// « DG » existe déjà (jamais deux profils fusionnés en silence).
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('profils')->where('nom', 'DG')->exists()) {
            return;
        }

        DB::table('profils')->where('nom', 'DGA')->update(['nom' => 'DG']);
    }

    public function down(): void
    {
        if (DB::table('profils')->where('nom', 'DGA')->exists()) {
            return;
        }

        DB::table('profils')->where('nom', 'DG')->update(['nom' => 'DGA']);
    }
};
