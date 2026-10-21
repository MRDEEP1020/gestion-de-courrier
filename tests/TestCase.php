<?php

namespace Tests;

use Database\Seeders\PrivilegeSeeder;
use Database\Seeders\ProfilSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    // Système de privilèges (2026-09-15, voir DECISIONS.md "Système de
    // privilèges") : les Policies vérifient désormais $user->hasPrivilege(...)
    // au lieu de $user->profil?->nom en dur — sans les 5 profils ET leurs
    // privilèges par défaut déjà en base, TOUT test créant un utilisateur via
    // ...::firstOrCreate(['nom' => 'Agent']) (le profil existe mais sans
    // aucun privilège) verrait chaque vérification d'autorisation échouer.
    // Semé automatiquement ici plutôt que dans chaque fichier de test :
    // Profil::firstOrCreate() dans les tests existants devient alors un
    // no-op qui retrouve le profil déjà semé, sans toucher aux ~280 tests
    // déjà écrits. Conditionné à RefreshDatabase : un test qui n'a pas de
    // base migrée (ex. ReplicateFichierJobTest, disques fake uniquement)
    // ne doit pas tenter de semer une table inexistante.
    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->seed([ProfilSeeder::class, PrivilegeSeeder::class]);
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
