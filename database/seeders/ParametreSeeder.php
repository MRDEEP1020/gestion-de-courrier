<?php

namespace Database\Seeders;

use App\Models\Parametre;
use Illuminate\Database\Seeder;

// Module 5 — valeurs PLACEHOLDER de délai SLA par type de courrier, en
// attendant les vrais chiffres de Nsia (voir notes internes /
// le journal de développement : "sla_par_type" ship vide, aucun seeder ne le
// remplissait jusqu'ici, donc tout type retombait sur le seul délai par
// défaut tant qu'un administrateur ne l'avait pas configuré à la main).
// Idempotent et NON destructeur : ne remplit QUE les types encore absents
// de sla_par_type — une valeur déjà configurée par un administrateur
// depuis "Paramètres système" n'est jamais écrasée. Clés = les 9 types de
// document seedés par la migration 2026_09_24_090100_create_listes_reference_table.php.
class ParametreSeeder extends Seeder
{
    public function run(): void
    {
        $placeholders = [
            'Sinistre' => 5,
            'Réclamation' => 5,
            'Convocation' => 7,
            'Lettre' => 10,
            'Demande' => 10,
            'Proposition commerciale' => 15,
            'Relevé ou bordereau' => 15,
            'Facture' => 15,
            'Contrat ou avenant' => 20,
        ];

        $parametre = Parametre::actuel();
        $actuel = $parametre->sla_par_type ?? [];

        $parametre->update([
            'sla_par_type' => array_merge($placeholders, $actuel),
        ]);

        Parametre::invaliderCache();

        $this->command?->info('Délais SLA placeholder écrits pour les types sans valeur configurée — à remplacer par les vrais chiffres Nsia sur /admin/parametres.');
    }
}
