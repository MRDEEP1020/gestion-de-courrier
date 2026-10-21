<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Assignation d'un privilège à UN utilisateur précis, en plus de ceux de
// son profil (ex. donner un droit à un agent particulier sans le sortir
// de son profil) — voir DECISIONS.md "Système de privilèges". Additif
// uniquement : pas de table de "retrait" explicite dans cette première
// version.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privilege_user', function (Blueprint $table) {
            $table->foreignId('privilege_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['privilege_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privilege_user');
    }
};
