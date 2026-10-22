<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ProfilSeeder::class);
        // Système de privilèges (2026-09-15) — dépend des profils ci-dessus,
        // voir DECISIONS.md "Système de privilèges".
        $this->call(PrivilegeSeeder::class);
        $this->call(ServiceSeeder::class);
        // Comptes de démonstration pour tester le circuit courrier entrant
        // dans le navigateur — voir ComptesTestPiloteSeeder (local/testing
        // uniquement, garde-fou dans le seeder lui-même).
        $this->call(ComptesTestPiloteSeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
