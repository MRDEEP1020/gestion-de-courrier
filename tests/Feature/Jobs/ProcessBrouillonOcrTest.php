<?php

namespace Tests\Feature\Jobs;

use App\Events\BrouillonOcrTermine;
use App\Jobs\ProcessBrouillonOcr;
use App\Models\CourrierBrouillon;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class ProcessBrouillonOcrTest extends TestCase
{
    use RefreshDatabase;

    private function brouillon(): CourrierBrouillon
    {
        $agent = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id]);

        return CourrierBrouillon::create([
            'fichier_path' => 'brouillons/uuid-test.pdf',
            'nom_original' => 'scan.pdf',
            'type_mime' => 'application/pdf',
            'taille' => 1000,
            'cree_par_id' => $agent->id,
            'ocr_statut' => 'en_cours',
        ]);
    }

    // handle() appelle réellement Tesseract — comme ProcessDocumentOcrTest, on
    // ne teste pas ce chemin ici (vérifié manuellement, voir le journal de développement) ;
    // failed() et la réutilisation des méthodes statiques de ProcessDocumentOcr
    // (déjà testées dans ProcessDocumentOcrTest) sont couvertes.
    public function test_un_echec_marque_le_brouillon_et_notifie(): void
    {
        Event::fake([BrouillonOcrTermine::class]);

        $brouillon = $this->brouillon();

        (new ProcessBrouillonOcr($brouillon))->failed(new RuntimeException('Segmentation fault'));

        $this->assertSame('echec', $brouillon->refresh()->ocr_statut);

        Event::assertDispatched(BrouillonOcrTermine::class, fn (BrouillonOcrTermine $e) => $e->brouillonId === $brouillon->id
            && $e->creeParId === $brouillon->cree_par_id
            && $e->statut === 'echec');
    }

    public function test_aucun_historique_de_courrier_nest_cree_pour_un_brouillon(): void
    {
        // Un brouillon n'est pas encore un Courrier (courrier_historiques a une
        // FK NOT NULL) — voir DECISIONS.md "Flux scan-first" : le job ne doit
        // jamais tenter d'y écrire.
        $brouillon = $this->brouillon();

        (new ProcessBrouillonOcr($brouillon))->failed(new RuntimeException('Erreur.'));

        $this->assertDatabaseCount('courrier_historiques', 0);
    }
}
