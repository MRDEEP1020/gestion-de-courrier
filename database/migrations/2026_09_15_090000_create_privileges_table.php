<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 9 — "droits d'accès gérés finement" : catalogue de privilèges
// créables/assignables sans changer de code, remplace le contrôle d'accès
// codé en dur (voir DECISIONS.md "Système de privilèges").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privileges', function (Blueprint $table) {
            $table->id();
            $table->string('cle')->unique();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privileges');
    }
};
