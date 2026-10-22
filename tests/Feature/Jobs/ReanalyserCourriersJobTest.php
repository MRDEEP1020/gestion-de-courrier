<?php

namespace Tests\Feature\Jobs;

use App\Jobs\IndexCourrierJob;
use App\Jobs\ReanalyserCourriersJob;
use App\Models\Courrier;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReanalyserCourriersJobTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private ?Service $service = null;

    private function courrier(string $statut): Courrier
    {
        // Propriété d'instance (pas static) : la base est réinitialisée entre deux tests.
        $this->service ??= Service::factory()->create(['code' => 'TST']);

        return Courrier::create([
            'numero_reference' => sprintf('GEC-%d-TST-%06d', now()->year, ++$this->sequence),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Objet',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => $this->service->id,
            'classement_statut' => $statut,
        ]);
    }

    public function test_le_job_relance_le_classement_des_courriers_sans_decision(): void
    {
        Queue::fake();

        $nonClasse = $this->courrier('non_classe');
        $propose = $this->courrier('propose');
        $this->courrier('valide');
        $this->courrier('ignore');

        app()->call([new ReanalyserCourriersJob, 'handle']);

        Queue::assertPushed(IndexCourrierJob::class, 2);
        Queue::assertPushedOn('indexation', IndexCourrierJob::class, fn (IndexCourrierJob $job) => $job->courrier->id === $nonClasse->id);
        Queue::assertPushedOn('indexation', IndexCourrierJob::class, fn (IndexCourrierJob $job) => $job->courrier->id === $propose->id);
    }

    public function test_avec_tous_les_courriers_deja_decides_sont_aussi_relances(): void
    {
        Queue::fake();

        $this->courrier('non_classe');
        $this->courrier('valide');
        $this->courrier('ignore');

        app()->call([new ReanalyserCourriersJob(tous: true), 'handle']);

        Queue::assertPushed(IndexCourrierJob::class, 3);
    }

    public function test_la_commande_planifie_la_reanalyse_sur_la_queue_indexation(): void
    {
        Queue::fake();

        $this->courrier('non_classe');

        $this->artisan('courriers:reanalyser')
            ->expectsOutputToContain('1 courrier(s)')
            ->assertSuccessful();

        Queue::assertPushedOn('indexation', ReanalyserCourriersJob::class, fn (ReanalyserCourriersJob $job) => $job->tous === false);

        $this->artisan('courriers:reanalyser', ['--tous' => true])->assertSuccessful();

        Queue::assertPushed(ReanalyserCourriersJob::class, fn (ReanalyserCourriersJob $job) => $job->tous === true);
    }
}
