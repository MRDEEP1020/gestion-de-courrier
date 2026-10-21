<?php

namespace Tests\Feature\Services;

use App\Models\Courrier;
use App\Models\Parametre;
use App\Models\Service;
use App\Services\SlaCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Module 5 — date limite SLA et statut de délai (2026-09-23, voir
// DECISIONS.md "SLA et alertes").
class SlaCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private function courrier(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => '2026-09-01',
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id,
            'statut' => 'affecte',
        ], $attributs));
    }

    // 2026-09-23 — les délais viennent de Parametre::actuel() (page
    // "Paramètres système", éditable sans redéploiement), plus de
    // config/gec.php. La ligne singleton met à jour son propre cache
    // (Parametre::invaliderCache()) — jamais oublier après un ->update()
    // direct sur le modèle dans un test.
    private function definirParametresSla(array $attributs): void
    {
        Parametre::actuel()->update($attributs);
        Parametre::invaliderCache();
    }

    public function test_delai_par_defaut(): void
    {
        // 10 = valeur par défaut de la migration, déjà en place — vérifie
        // simplement qu'elle est bien lue depuis Parametre, pas codée en dur.
        $this->assertSame(10, Parametre::actuel()->sla_jours_defaut);
        $this->assertSame('2026-09-11', $this->courrier()->date_limite->toDateString());
    }

    public function test_delai_par_type_puis_sla_du_courrier_puis_echeance(): void
    {
        $this->definirParametresSla(['sla_par_type' => ['Réclamation' => 5]]);

        $this->assertSame('2026-09-06', $this->courrier(['type_document' => 'Réclamation'])->date_limite->toDateString());
        $this->assertSame('2026-09-04', $this->courrier(['type_document' => 'Réclamation', 'sla_jours' => 3])->date_limite->toDateString());
        $this->assertSame('2026-09-30', $this->courrier(['sla_jours' => 3, 'echeance' => '2026-09-30'])->date_limite->toDateString());
    }

    public function test_la_date_limite_suit_une_modification_du_courrier(): void
    {
        $courrier = $this->courrier();

        $courrier->update(['sla_jours' => 1]);

        $this->assertSame('2026-09-02', $courrier->fresh()->date_limite->toDateString());
    }

    public function test_statut_de_delai(): void
    {
        $sla = app(SlaCalculatorService::class);

        $this->assertSame(SlaCalculatorService::EN_RETARD, $sla->calculerStatutDelai($this->courrier(['echeance' => today()->subDay()])));
        $this->assertSame(SlaCalculatorService::A_RISQUE, $sla->calculerStatutDelai($this->courrier(['echeance' => today()->addDay()])));
        $this->assertSame(SlaCalculatorService::A_TEMPS, $sla->calculerStatutDelai($this->courrier(['echeance' => today()->addDays(10)])));
        // Un courrier clôturé n'est jamais en retard.
        $this->assertSame(SlaCalculatorService::A_TEMPS, $sla->calculerStatutDelai($this->courrier(['echeance' => today()->subDay(), 'statut' => 'traite'])));
    }
}
