<?php

namespace Tests\Feature;

use App\Jobs\CalculerOccupationStockageJob;
use App\Livewire\Backend\SecuriteAudit;
use App\Models\OccupationStockage;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Module 9 — occupation du stockage (2026-11-09, demande « on doit aussi
// pouvoir voir l'espace de stockage », voir DECISIONS.md).
class OccupationStockageTest extends TestCase
{
    use RefreshDatabase;

    private function administrateur(): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Administrateur'])->id]);
    }

    public function test_le_calcul_totalise_par_type_et_repere_les_fichiers_absents_de_la_sauvegarde(): void
    {
        Storage::fake('s3');
        Storage::fake('s3_backup');
        config(['filesystems.disks.s3_backup.bucket' => 'gec-backup', 'services.stockage.chemin_disque' => storage_path()]);

        $principal = [
            'courriers/2026/DI/GEC-2026-000001.pdf' => 1000,
            'courriers/2026/DI/GEC-2026-000001-pdfa.pdf' => 400,
            'courriers/2026/DI/GEC-2026-000001/annexe.pdf' => 300,
            'brouillons/scan.pdf' => 200,
            'apercus/courrier/1/1.jpg' => 50,
            'avatars/1/photo.jpg' => 25,
        ];

        foreach ($principal as $chemin => $octets) {
            Storage::disk('s3')->put($chemin, str_repeat('x', $octets));
        }

        // Sauvegarde : tout sauf la pièce jointe (et les aperçus, jamais copiés).
        foreach (['courriers/2026/DI/GEC-2026-000001.pdf', 'courriers/2026/DI/GEC-2026-000001-pdfa.pdf', 'brouillons/scan.pdf', 'avatars/1/photo.jpg'] as $chemin) {
            Storage::disk('s3_backup')->put($chemin, str_repeat('x', $principal[$chemin]));
        }

        app()->call([new CalculerOccupationStockageJob, 'handle']);

        $calcul = OccupationStockage::sole();
        $this->assertSame(6, $calcul->fichiers);
        $this->assertSame(1975, (int) $calcul->octets);
        $this->assertSame(['fichiers' => 1, 'octets' => 1000], $calcul->par_type['documents']);
        $this->assertSame(['fichiers' => 1, 'octets' => 400], $calcul->par_type['pdfa']);
        $this->assertSame(['fichiers' => 1, 'octets' => 300], $calcul->par_type['pieces_jointes']);
        $this->assertSame(['fichiers' => 1, 'octets' => 50], $calcul->par_type['apercus']);
        $this->assertSame(4, $calcul->sauvegarde_fichiers);
        $this->assertSame(1, $calcul->sauvegarde_manquants);
        $this->assertGreaterThan(0, (int) $calcul->disque_total);
        $this->assertNotNull($calcul->disque_libre);
    }

    public function test_les_tailles_saffichent_en_unites_francaises(): void
    {
        $this->assertSame('0 o', OccupationStockage::taille(0));
        $this->assertSame('1,5 Ko', OccupationStockage::taille(1536));
        $this->assertSame('37,6 Mo', OccupationStockage::taille(39426457));
        $this->assertSame('—', OccupationStockage::taille(null));
    }

    public function test_le_premier_affichage_lance_le_calcul_en_file_dattente(): void
    {
        Queue::fake();
        $this->actingAs($this->administrateur());

        Livewire::test(SecuriteAudit::class)->call('changerOnglet', 'stockage')
            ->assertSee(__('Premier calcul de l\'occupation du stockage en cours : rouvrez cet onglet dans un instant.'));

        Queue::assertPushed(CalculerOccupationStockageJob::class);
    }

    public function test_longlet_affiche_le_dernier_calcul_et_permet_de_recalculer(): void
    {
        Queue::fake();
        OccupationStockage::create([
            'calcule_le' => now()->subDay(), 'fichiers' => 100, 'octets' => 30 * 1048576,
            'par_type' => ['documents' => ['fichiers' => 100, 'octets' => 30 * 1048576]],
            'sauvegarde_fichiers' => 90, 'sauvegarde_octets' => 28 * 1048576, 'sauvegarde_manquants' => 10,
            'disque_libre' => 50 * 1073741824, 'disque_total' => 100 * 1073741824,
        ]);
        OccupationStockage::create([
            'calcule_le' => now(), 'fichiers' => 113, 'octets' => 39426457,
            'par_type' => ['documents' => ['fichiers' => 61, 'octets' => 22020096], 'apercus' => ['fichiers' => 52, 'octets' => 17406361]],
            'sauvegarde_fichiers' => 113, 'sauvegarde_octets' => 39426457, 'sauvegarde_manquants' => 0,
            'disque_libre' => 5 * 1073741824, 'disque_total' => 100 * 1073741824,
        ]);

        $this->actingAs($this->administrateur());

        Livewire::test(SecuriteAudit::class, ['onglet' => 'stockage'])
            ->set('onglet', 'stockage')
            ->assertSee('37,6 Mo')
            ->assertSee(__('Documents principaux'))
            ->assertSee(__('Tous les fichiers sont sauvegardés'))
            ->assertSee(__('Disque presque plein : prévoir de l\'espace sans attendre.'))
            ->assertSee('+ 7,6 Mo')
            ->call('calculerStockage');

        Queue::assertPushed(CalculerOccupationStockageJob::class);
    }

    public function test_le_calcul_est_planifie_avec_rattrapage(): void
    {
        Artisan::call('schedule:list');
        $sortie = Artisan::output();

        $this->assertStringContainsString('occupation-stockage', $sortie);
        $this->assertStringContainsString('occupation-stockage-rattrapage', $sortie);
    }
}
