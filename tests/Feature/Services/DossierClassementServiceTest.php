<?php

namespace Tests\Feature\Services;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\DossierClassement;
use App\Models\DossierClassementHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\DossierClassementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Module 3/9 — filer/retirer un courrier d'un dossier de classement
// (2026-09-22, "les trois" points d'entrée demandés par l'utilisateur).
class DossierClassementServiceTest extends TestCase
{
    use RefreshDatabase;

    private DossierClassementService $service;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DossierClassementService;
        $this->auteur = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Administrateur'])->id]);
    }

    private function courrier(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id,
        ], $attributs));
    }

    public function test_classer_met_a_jour_le_courrier_et_ecrit_les_deux_historiques(): void
    {
        $courrier = $this->courrier();
        $dossier = DossierClassement::create(['nom' => 'Sinistres 2026', 'cree_par_id' => $this->auteur->id]);

        $this->service->classer($courrier, $dossier, $this->auteur);

        $this->assertSame($dossier->id, $courrier->fresh()->dossier_classement_id);

        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'auteur_id' => $this->auteur->id,
            'action' => 'classement_dossier',
        ]);

        $this->assertDatabaseHas('dossier_classement_historiques', [
            'dossier_classement_id' => $dossier->id,
            'auteur_id' => $this->auteur->id,
            'action' => 'courrier_ajoute',
            'commentaire' => $courrier->numero_reference,
        ]);
    }

    public function test_classer_permet_de_deplacer_un_courrier_dun_dossier_a_un_autre(): void
    {
        $dossierA = DossierClassement::create(['nom' => 'Dossier A', 'cree_par_id' => $this->auteur->id]);
        $dossierB = DossierClassement::create(['nom' => 'Dossier B', 'cree_par_id' => $this->auteur->id]);
        $courrier = $this->courrier(['dossier_classement_id' => $dossierA->id]);

        $this->service->classer($courrier, $dossierB, $this->auteur);

        $this->assertSame($dossierB->id, $courrier->fresh()->dossier_classement_id);
    }

    public function test_retirer_vide_le_champ_et_ecrit_les_deux_historiques_sur_lancien_dossier(): void
    {
        $dossier = DossierClassement::create(['nom' => 'Sinistres 2026', 'cree_par_id' => $this->auteur->id]);
        $courrier = $this->courrier(['dossier_classement_id' => $dossier->id]);

        $this->service->retirer($courrier, $this->auteur);

        $this->assertNull($courrier->fresh()->dossier_classement_id);

        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'auteur_id' => $this->auteur->id,
            'action' => 'declassement_dossier',
        ]);

        $this->assertDatabaseHas('dossier_classement_historiques', [
            'dossier_classement_id' => $dossier->id,
            'auteur_id' => $this->auteur->id,
            'action' => 'courrier_retire',
            'commentaire' => $courrier->numero_reference,
        ]);
    }

    public function test_retirer_sur_un_courrier_deja_non_classe_ne_fait_rien(): void
    {
        $courrier = $this->courrier();

        $this->service->retirer($courrier, $this->auteur);

        $this->assertNull($courrier->fresh()->dossier_classement_id);
        $this->assertSame(0, CourrierHistorique::where('courrier_id', $courrier->id)->count());
        $this->assertSame(0, DossierClassementHistorique::count());
    }
}
