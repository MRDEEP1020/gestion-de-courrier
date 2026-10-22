<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Assignation d'un privilège à TOUS les utilisateurs d'un profil — voir
// DECISIONS.md "Système de privilèges".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privilege_profil', function (Blueprint $table) {
            $table->foreignId('privilege_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profil_id')->constrained()->cascadeOnDelete();
            $table->primary(['privilege_id', 'profil_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privilege_profil');
    }
};
