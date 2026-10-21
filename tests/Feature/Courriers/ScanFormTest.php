<?php

namespace Tests\Feature\Courriers;

use App\Jobs\ProcessDocumentOcr;
use App\Jobs\ReplicateFichierJob;
use App\Livewire\Backend\ScanForm;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Parametre;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ScanFormTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(['profil_id' => $profil->id]);
    }

    private function courrierEnregistrePar(User $auteur): Courrier
    {
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier de test',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'service_id' => Service::factory()->create(['code' => 'TST'])->id,
        ]);

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => $auteur->id,
            'action' => 'creation',
        ]);

        return $courrier;
    }

    public function test_lagent_peut_numeriser_un_courrier(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(ScanForm::class, ['courrierId' => $courrier->id])
            ->set('document', UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf'))
            ->call('numeriser')
            ->assertHasNoErrors();

        $courrier->refresh();

        $this->assertNotNull($courrier->fichier_path);
        $this->assertSame('en_cours', $courrier->ocr_statut);
        Storage::disk('s3')->assertExists($courrier->fichier_path);

        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)
            ->where('action', 'numerisation')
            ->count());

        Queue::assertPushedOn('ocr', ProcessDocumentOcr::class, function ($job) use ($courrier) {
            return $job->courrier->id === $courrier->id;
        });

        // Règle n°4 (complétée) — copie de secours asynchrone (voir DECISIONS.md).
        Queue::assertPushedOn('replication', ReplicateFichierJob::class, fn (ReplicateFichierJob $job) => $job->chemin === $courrier->fichier_path);
    }

    public function test_une_image_trop_petite_est_refusee(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(ScanForm::class, ['courrierId' => $courrier->id])
            ->set('document', UploadedFile::fake()->image('miniature.jpg', 200, 200))
            ->call('numeriser')
            ->assertHasErrors(['document']);

        Queue::assertNotPushed(ProcessDocumentOcr::class);
    }

    public function test_une_image_en_pleine_resolution_est_acceptee(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(ScanForm::class, ['courrierId' => $courrier->id])
            ->set('document', UploadedFile::fake()->image('photo.jpg', 1200, 1600))
            ->call('numeriser')
            ->assertHasNoErrors();

        Queue::assertPushedOn('ocr', ProcessDocumentOcr::class);
    }

    // "Group A" (2026-09-24, voir DECISIONS.md "Paramètres système
    // configurables — Groupe A/B") : ex-`const RESOLUTION_MINIMALE`,
    // désormais lu dynamiquement sur Parametre — une image qui passait à
    // 600 px doit être refusée si l'administrateur relève le seuil.
    public function test_le_seuil_de_resolution_minimale_est_configurable(): void
    {
        Parametre::actuel()->update(['scan_resolution_minimale' => 2000]);
        Parametre::invaliderCache();

        Storage::fake('s3');
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        Livewire::test(ScanForm::class, ['courrierId' => $courrier->id])
            ->set('document', UploadedFile::fake()->image('photo.jpg', 1200, 1600))
            ->call('numeriser')
            ->assertHasErrors(['document']);
    }

    public function test_un_agent_tiers_ne_peut_pas_numeriser(): void
    {
        $createur = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($createur);

        $autreAgent = User::factory()->create(['profil_id' => $createur->profil_id]);
        $this->actingAs($autreAgent);

        Livewire::test(ScanForm::class, ['courrierId' => $courrier->id])
            ->assertForbidden();
    }

    public function test_on_ne_peut_pas_remplacer_un_document_deja_valide(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);
        $courrier->update(['fichier_path' => 'courriers/'.now()->year.'/TST/'.$courrier->numero_reference.'.pdf', 'ocr_statut' => 'reussi']);

        $this->actingAs($agent);

        Livewire::test(ScanForm::class, ['courrierId' => $courrier->id])
            ->set('document', UploadedFile::fake()->create('remplacement.pdf', 500, 'application/pdf'))
            ->call('numeriser');

        $this->assertSame(
            'courriers/'.now()->year.'/TST/'.$courrier->numero_reference.'.pdf',
            $courrier->refresh()->fichier_path,
        );

        Queue::assertNotPushed(ProcessDocumentOcr::class);
    }
}
