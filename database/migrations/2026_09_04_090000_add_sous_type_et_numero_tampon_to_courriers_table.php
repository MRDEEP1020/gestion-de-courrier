<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            // Module 3 — sous-type "Sinistre" confirmé par le client dès la
            // phase 1 (specifications-modules-GEC.md) : matériel | corporel.
            // Nullable : n'a de sens que pour les courriers de type "Sinistre".
            $table->string('sous_type_sinistre', 20)->nullable()->after('type_document');

            // Module 1/2 — numéro lu par OCR sur le tampon d'entrée existant
            // ("NSIA ASSURANCES {date} {heure}-{numéro}"), à titre indicatif
            // uniquement : n'alimente jamais numero_reference (Règle n°3 —
            // unicité garantie en base par NumeroReferenceGenerator), sert à
            // l'agent pour repérer un écart entre le tampon papier et le
            // numéro généré par le système.
            $table->string('numero_tampon_detecte', 160)->nullable()->after('numero_reference');
        });
    }

    public function down(): void
    {
        Schema::table('courriers', function (Blueprint $table) {
            $table->dropColumn(['sous_type_sinistre', 'numero_tampon_detecte']);
        });
    }
};
