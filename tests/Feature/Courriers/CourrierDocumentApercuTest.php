<?php

namespace Tests\Feature\Courriers;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use Mockery;
use Tests\TestCase;

class CourrierDocumentApercuTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(['profil_id' => $profil->id]);
    }

    private function courrierEnregistrePar(User $auteur, array $attributs = []): Courrier
    {
        $courrier = Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier de test',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create(['code' => 'TST'])->id,
        ], $attributs));

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => $auteur->id,
            'action' => 'creation',
        ]);

        return $courrier;
    }

    public function test_le_createur_peut_apercevoir_le_document_sans_le_telecharger(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('courriers/2026/TST/GEC-2026-TST-000001.pdf', 'contenu du document');

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['fichier_path' => 'courriers/2026/TST/GEC-2026-TST-000001.pdf']);

        $this->actingAs($agent);

        $response = $this->get(route('courriers.document.apercu', $courrier));

        $response->assertOk();
        // "inline", pas "attachment" : c'est toute la différence avec
        // courriers.document (téléchargement forcé) — le navigateur affiche
        // le document plutôt que de proposer de l'enregistrer.
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_un_agent_tiers_ne_peut_pas_apercevoir_le_document(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('courriers/2026/TST/GEC-2026-TST-000001.pdf', 'contenu du document');

        $createur = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($createur, ['fichier_path' => 'courriers/2026/TST/GEC-2026-TST-000001.pdf']);

        $autreAgent = User::factory()->create(['profil_id' => $createur->profil_id]);
        $this->actingAs($autreAgent);

        $this->get(route('courriers.document.apercu', $courrier))->assertForbidden();
    }

    public function test_aucun_document_a_apercevoir_donne_une_404(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent);

        $this->actingAs($agent);

        $this->get(route('courriers.document.apercu', $courrier))->assertNotFound();
    }

    // Bug réel (2026-09-24) : chemin enregistré mais fichier absent du
    // stockage → exception de stockage renvoyée en 500 ; désormais 404.
    public function test_un_fichier_absent_du_stockage_donne_une_404_et_non_une_500(): void
    {
        Storage::fake('s3');

        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['fichier_path' => 'courriers/2026/_en_attente/GEC-2026-000003.pdf']);

        $this->actingAs($agent);

        $this->get(route('courriers.document.apercu', $courrier))->assertNotFound();
        $this->get(route('courriers.document', $courrier))->assertNotFound();
    }

    // Deuxième bug réel (2026-10-02, retour utilisateur avec capture
    // d'écran : "Réponse HTTP 500 en récupérant le document") — le
    // correctif ci-dessus supposait que Storage::exists() ne pouvait que
    // retourner false pour un fichier absent, mais il peut aussi LEVER une
    // League\Flysystem\UnableToCheckFileExistence si le disque S3/MinIO
    // lui-même est injoignable (confirmé dans storage/logs/laravel.log —
    // "Unable to check existence for..."), ce qui redonnait une 500 brute
    // malgré le garde-fou. Désormais capturée et convertie en 503 (stockage
    // temporairement indisponible), distincte du 404 (fichier réellement
    // absent) ci-dessus.
    public function test_un_stockage_injoignable_donne_une_503_et_non_une_500(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierEnregistrePar($agent, ['fichier_path' => 'courriers/2026/TST/GEC-2026-TST-000001.pdf']);

        $disqueS3 = Mockery::mock(Filesystem::class);
        $disqueS3->shouldReceive('exists')
            ->with('courriers/2026/TST/GEC-2026-TST-000001.pdf')
            ->andThrow(UnableToCheckFileExistence::forLocation('courriers/2026/TST/GEC-2026-TST-000001.pdf'));
        Storage::shouldReceive('disk')->with('s3')->andReturn($disqueS3);

        $this->actingAs($agent);

        $this->get(route('courriers.document.apercu', $courrier))->assertStatus(503);
    }
}
