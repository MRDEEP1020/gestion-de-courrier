<?php

use App\Models\OrganizationUnit;
use App\Services\ServiceReelSynchroniseur;
use Illuminate\Database\Migrations\Migration;

// 2026-10-23 (voir DECISIONS.md "Transmission descendante par la Direction")
// — données uniquement : chaque Direction existante reçoit son service réel
// (comme un Département), pour pouvoir recevoir un courrier transféré. Même
// règle que la création depuis la page Organisation
// (ServiceReelSynchroniseur) : nom, code unique, responsable du nœud.
// Idempotent : une Direction déjà reliée n'est pas touchée.
return new class extends Migration
{
    public function up(): void
    {
        $synchroniseur = app(ServiceReelSynchroniseur::class);

        OrganizationUnit::query()
            ->where('type', OrganizationUnit::TYPE_DIRECTION)
            ->whereNull('service_id')
            ->get()
            ->each(fn (OrganizationUnit $direction) => $synchroniseur->synchroniser($direction));
    }

    // Volontairement sans effet : un service créé ici peut déjà porter des
    // courriers et leur historique (Règle n°5 — jamais de suppression).
    public function down(): void {}
};
