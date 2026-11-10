<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->json('notifications_defaut')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences_notifications')->nullable();
        });

        Schema::create('courrier_alerte_utilisateurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courrier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date_limite');
            $table->timestamp('notifie_le');
            $table->timestamps();
            $table->unique(['courrier_id', 'user_id']);
            $table->index(['user_id', 'notifie_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courrier_alerte_utilisateurs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences_notifications');
        });

        Schema::table('parametres', function (Blueprint $table) {
            $table->dropColumn('notifications_defaut');
        });
    }
};
