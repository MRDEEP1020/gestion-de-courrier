<?php

namespace Tests\Feature\Services;

use App\Models\NumeroSequence;
use App\Models\Parametre;
use App\Services\NumeroReferenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Module 1 — numéro de référence, format configurable depuis "Paramètres
// système" (2026-09-23, voir DECISIONS.md "Paramètres système configurables").
class NumeroReferenceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_par_defaut(): void
    {
        $numero = app(NumeroReferenceGenerator::class)->generer(2026);

        $this->assertSame('GEC-2026-000001', $numero);
    }

    public function test_prefixe_et_nombre_de_chiffres_configurables(): void
    {
        Parametre::actuel()->update(['numero_reference_prefixe' => 'NSIA', 'numero_reference_chiffres_sequence' => 4]);
        Parametre::invaliderCache();

        $numero = app(NumeroReferenceGenerator::class)->generer(2026);

        $this->assertSame('NSIA-2026-0001', $numero);
    }

    // "une fois confirmé ne changera plus jamais" (PRD.md) — changer le
    // format n'affecte QUE l'affichage du prochain numéro, jamais la
    // séquence déjà consommée : le 2e numéro généré suit bien le 1er.
    public function test_changer_le_format_ne_rejoue_jamais_la_sequence(): void
    {
        $generateur = app(NumeroReferenceGenerator::class);
        $premier = $generateur->generer(2026);
        $this->assertSame('GEC-2026-000001', $premier);

        Parametre::actuel()->update(['numero_reference_prefixe' => 'GEC']);
        Parametre::invaliderCache();

        $second = $generateur->generer(2026);
        $this->assertSame('GEC-2026-000002', $second);
        $this->assertSame(2, NumeroSequence::where('annee', 2026)->value('dernier_numero'));
    }
}
