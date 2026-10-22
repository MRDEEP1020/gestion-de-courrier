<?php

namespace Tests\Feature\Seeders;

use App\Models\Service;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_14_services_reels_sont_crees(): void
    {
        (new ServiceSeeder)->run();

        $this->assertSame(14, Service::count());
        $this->assertSame(
            ['ACG', 'DAF', 'DC', 'DCOM', 'DI', 'DSIN', 'DT', 'RAG', 'RH', 'SANTE', 'SDG', 'SG', 'SJ', 'TRANS'],
            Service::query()->orderBy('code')->pluck('code')->all(),
        );
    }

    public function test_le_seeder_est_idempotent_et_ne_duplique_pas_un_service_existant(): void
    {
        $service = Service::factory()->create(['code' => 'SDG', 'nom' => 'Nom personnalisé en pilote']);

        (new ServiceSeeder)->run();

        $this->assertSame(1, Service::where('code', 'SDG')->count());
        $this->assertSame('Nom personnalisé en pilote', $service->refresh()->nom, 'un service déjà présent (ex. renommé en pilote) n\'est jamais écrasé');
    }
}
