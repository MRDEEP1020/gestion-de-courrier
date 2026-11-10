<?php

namespace Database\Seeders;

use App\Models\Profil;
use Illuminate\Database\Seeder;

class ProfilSeeder extends Seeder
{
    // 5 profils — voir DECISIONS.md ("Profil", jamais "Role"). « DG » (ex-« DGA »,
    // renommé le 2026-10-26 — c'est le Directeur Général) ajouté le
    // 2026-09-08 (validation du service pour un courrier entrant non-sinistre,
    // voir DECISIONS.md "Circuit courrier entrant : validation DGA/ADJ").
    public function run(): void
    {
        foreach (['Agent', 'Collaborateur', 'Responsable de service', 'Administrateur', 'DG'] as $nom) {
            Profil::firstOrCreate(['nom' => $nom]);
        }
    }
}
