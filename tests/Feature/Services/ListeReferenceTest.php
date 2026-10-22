<?php

namespace Tests\Feature\Services;

use App\Models\ListeReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// Listes de référence gérables depuis "Paramètres système" ("Group B",
// 2026-09-24, voir DECISIONS.md "Paramètres système configurables — Groupe
// A/B") : type de document, mode de réception, priorité (Module 1).
class ListeReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_3_listes_sont_seedees_par_la_migration(): void
    {
        $this->assertDatabaseCount('listes_reference', 17);
        $this->assertContains('Sinistre', ListeReference::valeursActives(ListeReference::TYPE_DOCUMENT));
        $this->assertContains('email', ListeReference::valeursActives(ListeReference::MODE_RECEPTION));
        $this->assertContains('urgente', ListeReference::valeursActives(ListeReference::PRIORITE));
    }

    public function test_sinistre_email_fax_depot_physique_et_les_4_priorites_sont_protegees(): void
    {
        $this->assertTrue(ListeReference::where('type', 'type_document')->where('valeur', 'Sinistre')->value('protege'));
        $this->assertTrue(ListeReference::where('type', 'mode_reception')->where('valeur', 'email')->value('protege'));
        $this->assertTrue(ListeReference::where('type', 'mode_reception')->where('valeur', 'fax')->value('protege'));
        $this->assertTrue(ListeReference::where('type', 'mode_reception')->where('valeur', 'depot_physique')->value('protege'));
        $this->assertFalse(ListeReference::where('type', 'mode_reception')->where('valeur', 'poste')->value('protege'));

        $this->assertSame(
            4,
            ListeReference::where('type', 'priorite')->where('protege', true)->count(),
        );
    }

    public function test_valeurs_actives_exclut_les_valeurs_desactivees_et_respecte_lordre(): void
    {
        ListeReference::where('type', 'mode_reception')->where('valeur', 'fax')->update(['actif' => false]);

        $valeurs = ListeReference::valeursActives(ListeReference::MODE_RECEPTION);

        $this->assertNotContains('fax', $valeurs);
        $this->assertSame(['depot_physique', 'email', 'poste'], $valeurs);
    }

    public function test_valeurs_actives_est_mis_en_cache_et_invalide_a_la_sauvegarde(): void
    {
        ListeReference::valeursActives(ListeReference::PRIORITE);
        $this->assertTrue(Cache::has('listes_reference.priorite'));

        ListeReference::where('type', 'priorite')->where('valeur', 'basse')->first()->update(['actif' => false]);

        $this->assertFalse(Cache::has('listes_reference.priorite'), 'le cache doit être invalidé automatiquement à la sauvegarde');
        $this->assertNotContains('basse', ListeReference::valeursActives(ListeReference::PRIORITE));
    }

    public function test_toutes_retourne_actives_et_inactives(): void
    {
        ListeReference::where('type', 'priorite')->where('valeur', 'basse')->first()->update(['actif' => false]);

        $this->assertCount(4, ListeReference::toutes(ListeReference::PRIORITE));
        $this->assertCount(3, ListeReference::valeursActives(ListeReference::PRIORITE));
    }
}
