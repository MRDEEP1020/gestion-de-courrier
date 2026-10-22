<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 1/4 — mémorise QUI la réceptionniste a choisi au moment du
// transfert (voir migration create_destinataires_transfert_table.php et
// DECISIONS.md) — nullable : reste null pour les courriers déjà
// `en_cours_de_transfert` avant ce changement (traités comme "visible à
// tout DGA-privilégié", comportement d'avant, jamais réassigné
// rétroactivement) et pour tout courrier qui n'est jamais passé par cette
// étape (sortant, sinistre auto-routé).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->foreignId('destinataire_transfert_id')->nullable()->after('service_propose_id')->constrained('users')->nullOnDelete();
            $table->index('destinataire_transfert_id');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('destinataire_transfert_id');
        });
    }
};
