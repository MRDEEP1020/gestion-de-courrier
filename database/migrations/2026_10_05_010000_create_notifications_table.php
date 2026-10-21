<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 7 — centre de notifications in-app (2026-10-05). Schéma standard
// Laravel (canal "database" des Notifications natives, voir
// Illuminate\Notifications\DatabaseNotification) — CourrierEnRetardNotification
// l'utilise désormais en plus du mail (voir Notifications/CourrierEnRetardNotification.php).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
