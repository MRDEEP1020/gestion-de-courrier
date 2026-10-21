<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module 3/9 — partage d'un dossier par utilisateur précis ("le créateur du
// dossier définit qui y a accès", specifications-modules-GEC.md). Ce n'est
// PAS un privilège (voir PrivilegeSeeder.php, commentaire au-dessus de
// dossiers_classement.creer) — un enregistrement par octroi, même forme
// que destinataires_transfert (clé composite, pas de timestamps).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_classement_user', function (Blueprint $table) {
            $table->foreignId('dossier_classement_id')->constrained('dossiers_classement')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['dossier_classement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_classement_user');
    }
};
