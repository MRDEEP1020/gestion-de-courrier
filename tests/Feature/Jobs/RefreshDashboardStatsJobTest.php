<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RefreshDashboardStatsJob;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Parametre;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Module 10 — délai moyen de traitement pré-calculé (2026-09-23).
class RefreshDashboardStatsJobTest extends TestCase
{
    use RefreshDatabase;

    private function courrierClotureApres(int $jours, Service $service): Courrier
    {
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => today()->subDays($jours),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => $service->id,
            'statut' => 'traite',
        ]);

        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => null, 'action' => 'validation_acceptee']);

        return $courrier;
    }

    public function test_le_job_se_dispatche_sur_la_queue(): void
    {
        Queue::fake();

        RefreshDashboardStatsJob::dispatch();

        Queue::assertPushed(RefreshDashboardStatsJob::class);
    }

    public function test_le_job_est_enregistre_sur_le_scheduler(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('statistiques-tableau-de-bord', Artisan::output());
    }

    public function test_calcule_le_delai_moyen_global_et_par_service(): void
    {
        $a = Service::factory()->create();
        $b = Service::factory()->create();
        $this->courrierClotureApres(2, $a);
        $this->courrierClotureApres(4, $a);
        $this->courrierClotureApres(9, $b);

        app()->call([new RefreshDashboardStatsJob, 'handle']);

        $stats = Cache::get(RefreshDashboardStatsJob::CLE_CACHE);
        $this->assertSame(5.0, RefreshDashboardStatsJob::delaiMoyen($stats));
        $this->assertSame(3.0, RefreshDashboardStatsJob::delaiMoyen($stats, [$a->id]));
        $this->assertNull(RefreshDashboardStatsJob::delaiMoyen($stats, [999]));
    }

    // "Group A" (2026-09-24, voir DECISIONS.md "Paramètres système
    // configurables — Groupe A/B") : ex-`const PERIODE_JOURS = 90`,
    // désormais lue dynamiquement sur Parametre.
    public function test_la_periode_du_delai_moyen_est_configurable(): void
    {
        $service = Service::factory()->create();
        // Délai de 5 jours (mouvement à J-105, clôture à J-100) — la
        // clôture elle-même (courrier_historiques.created_at, filtrée par
        // la période) tombe hors de la fenêtre par défaut (90 jours).
        $courrierHorsPeriodeParDefaut = Courrier::create([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => today()->subDays(105),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => $service->id,
            'statut' => 'traite',
        ]);
        CourrierHistorique::create(['courrier_id' => $courrierHorsPeriodeParDefaut->id, 'auteur_id' => null, 'action' => 'validation_acceptee'])
            ->forceFill(['created_at' => now()->subDays(100)])->save();

        app()->call([new RefreshDashboardStatsJob, 'handle']);
        $stats = Cache::get(RefreshDashboardStatsJob::CLE_CACHE);
        $this->assertNull(RefreshDashboardStatsJob::delaiMoyen($stats), 'exclu par la période par défaut (90 jours)');

        Parametre::actuel()->update(['dashboard_delai_moyen_periode_jours' => 120]);
        Parametre::invaliderCache();

        app()->call([new RefreshDashboardStatsJob, 'handle']);
        $stats = Cache::get(RefreshDashboardStatsJob::CLE_CACHE);
        $this->assertSame(5.0, RefreshDashboardStatsJob::delaiMoyen($stats), 'inclus une fois la période élargie à 120 jours');
    }

    public function test_le_tableau_de_bord_affiche_retards_et_delai_moyen(): void
    {
        $admin = User::factory()->create(['profil_id' => Profil::where('nom', 'Administrateur')->value('id')]);
        $service = Service::factory()->create();
        $this->courrierClotureApres(6, $service);
        Courrier::create([
            'numero_reference' => 'GEC-2026-RETARD', 'sens' => 'entrant', 'date_mouvement' => today()->subDays(30),
            'echeance' => today()->subDay(), 'objet' => 'x', 'type_document' => 'Lettre', 'mode_reception' => 'email',
            'service_id' => $service->id, 'statut' => 'affecte',
        ]);
        app()->call([new RefreshDashboardStatsJob, 'handle']);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('En retard'))
            ->assertSee(__('Délai moyen de traitement'))
            ->assertSee('6 jours');
    }

    // Un collaborateur n'a pas de périmètre de service à moyenner.
    public function test_la_carte_delai_moyen_est_masquee_pour_un_collaborateur(): void
    {
        $collaborateur = User::factory()->create(['profil_id' => Profil::where('nom', 'Collaborateur')->value('id')]);

        $this->actingAs($collaborateur)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(__('Délai moyen de traitement'));
    }
}
