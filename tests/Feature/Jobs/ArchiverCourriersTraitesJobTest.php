<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ArchiverCourriersTraitesJob;
use App\Models\Courrier;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Module 9 — "un courrier clôturé (statut « Traité ») est automatiquement
// transféré vers l'espace d'archivage" (specifications-modules-GEC.md).
class ArchiverCourriersTraitesJobTest extends TestCase
{
    use RefreshDatabase;

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

    // Règle n°1/7 — le job se dispatche bien sur la queue.
    public function test_le_job_se_dispatche_sur_la_queue(): void
    {
        Queue::fake();

        ArchiverCourriersTraitesJob::dispatch();

        Queue::assertPushed(ArchiverCourriersTraitesJob::class);
    }

    // Première tâche planifiée de ce projet (voir bootstrap/app.php) —
    // vérifie que le Scheduler la connaît réellement (via la vraie commande
    // artisan, pas une inspection interne fragile de l'objet Schedule).
    public function test_le_job_est_enregistre_sur_le_scheduler(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('archiver-courriers-traites', Artisan::output());
    }

    public function test_un_courrier_traite_devient_archive_avec_une_trace_systeme(): void
    {
        $courrier = $this->courrier(['statut' => 'traite']);

        app()->call([new ArchiverCourriersTraitesJob, 'handle']);

        $this->assertSame('archive', $courrier->fresh()->statut);

        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'auteur_id' => null,
            'action' => 'archivage_automatique',
        ]);
    }

    public function test_un_courrier_dans_un_autre_statut_nest_pas_touche(): void
    {
        $courrier = $this->courrier(['statut' => 'affecte']);

        app()->call([new ArchiverCourriersTraitesJob, 'handle']);

        $this->assertSame('affecte', $courrier->fresh()->statut);
        $this->assertDatabaseMissing('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'action' => 'archivage_automatique',
        ]);
    }

    public function test_tous_les_courriers_traites_du_lot_sont_archives(): void
    {
        $traite1 = $this->courrier(['statut' => 'traite']);
        $traite2 = $this->courrier(['statut' => 'traite']);
        $nonConcerne = $this->courrier(['statut' => 'affecte']);

        app()->call([new ArchiverCourriersTraitesJob, 'handle']);

        $this->assertSame('archive', $traite1->fresh()->statut);
        $this->assertSame('archive', $traite2->fresh()->statut);
        $this->assertSame('affecte', $nonConcerne->fresh()->statut);
    }
}
