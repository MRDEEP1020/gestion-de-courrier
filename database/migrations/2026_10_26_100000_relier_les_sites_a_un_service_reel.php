<?php

use App\Models\OrganizationUnit;
use App\Services\ServiceReelSynchroniseur;
use Illuminate\Database\Migrations\Migration;

// 2026-10-26 (voir DECISIONS.md "Titres des chefs et Direction Générale") —
// données uniquement : chaque Site existant (la racine de l'organigramme,
// ex. « Direction Générale ») reçoit son service réel, pour pouvoir recevoir
// un courrier transféré. Même règle que la création depuis la page
// Organisation (ServiceReelSynchroniseur) et que la migration des Directions
// (2026_10_23_120000) : nom, code unique, responsable du nœud. Idempotent :
// un Site déjà relié n'est pas touché.
return new class extends Migration
{
    public function up(): void
    {
        $synchroniseur = app(ServiceReelSynchroniseur::class);

        OrganizationUnit::query()
            ->where('type', OrganizationUnit::TYPE_SITE)
            ->whereNull('service_id')
            ->get()
            ->each(fn (OrganizationUnit $site) => $synchroniseur->synchroniser($site));
    }

    // Volontairement sans effet : un service créé ici peut déjà porter des
    // courriers et leur historique (Règle n°5 — jamais de suppression).
    public function down(): void {}
};
