<?php

namespace Tests\Feature\Courriers;

use App\Jobs\ProcessBrouillonOcr;
use App\Jobs\ReplicateFichierJob;
use App\Livewire\Backend\ScanPremier;
use App\Models\CourrierBrouillon;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ScanPremierTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(['profil_id' => $profil->id]);
    }

    public function test_un_agent_peut_scanner_un_document_sans_courrier_existant(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(ScanPremier::class)
            ->set('document', UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf'))
            ->call('numeriser')
            ->assertHasNoErrors();

        $brouillon = CourrierBrouillon::firstOrFail();

        $this->assertSame($agent->id, $brouillon->cree_par_id);
        $this->assertStringStartsWith('brouillons/', $brouillon->fichier_path);
        Storage::disk('s3')->assertExists($brouillon->fichier_path);

        Queue::assertPushedOn('ocr', ProcessBrouillonOcr::class, fn (ProcessBrouillonOcr $job) => $job->brouillon->id === $brouillon->id);
        Queue::assertPushedOn('replication', ReplicateFichierJob::class, fn (ReplicateFichierJob $job) => $job->chemin === $brouillon->fichier_path);
    }

    // 2026-09-22 — un scan manuel (ce test) doit être tracé comme tel, pour
    // ne JAMAIS apparaître dans le tableau admin "Documents importés depuis
    // le dossier surveillé" (voir importsDossierSurveille() ci-dessous).
    public function test_un_scan_manuel_est_trace_avec_la_source_manuel(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(ScanPremier::class)
            ->set('document', UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf'))
            ->call('numeriser');

        $this->assertSame(CourrierBrouillon::SOURCE_MANUEL, CourrierBrouillon::firstOrFail()->source);
    }

    public function test_un_agent_non_autorise_a_enregistrer_ne_peut_pas_scanner(): void
    {
        Storage::fake('s3');

        // Seuls Agent et Administrateur peuvent créer un courrier
        // (CourrierPolicy::create) — un scan-first en est le point d'entrée.
        // 2026-09-23 : refusé dès le chargement de la page (mount), plus
        // seulement à l'upload — le composant n'est donc jamais instancié.
        $this->actingAs($this->utilisateurAvecProfil('Collaborateur'));

        Livewire::test(ScanPremier::class)->assertForbidden();
        $this->get(route('courriers.numeriser-nouveau'))->assertForbidden();

        $this->assertDatabaseCount('courrier_brouillons', 0);
    }

    // 2026-09-23 — le lien de la sidebar n'est plus affiché à qui ne peut
    // pas ouvrir la page (auparavant visible pour tous les profils).
    public function test_le_lien_numerisation_nest_affiche_quaux_profils_autorises(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Collaborateur'));
        $this->get(route('dashboard'))->assertDontSee(route('courriers.numeriser-nouveau'));

        $this->actingAs($this->utilisateurAvecProfil('Agent'));
        $this->get(route('dashboard'))->assertSee(route('courriers.numeriser-nouveau'));
    }

    public function test_une_image_trop_petite_est_refusee(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(ScanPremier::class)
            ->set('document', UploadedFile::fake()->image('photo.jpg', 200, 200))
            ->call('numeriser')
            ->assertHasErrors(['document']);

        $this->assertDatabaseCount('courrier_brouillons', 0);
    }

    // Module 1/2 — dossier surveillé (2026-09-09, voir DECISIONS.md "Import
    // automatique depuis un dossier surveillé") : point d'entrée alternatif
    // à numeriser() ci-dessus, piloté par resources/js/scan-watcher.js via
    // $wire.upload()+$wire.numeriserAutomatique() plutôt qu'un vrai
    // <input type="file">. Mêmes contrôles serveur (authorize/validate),
    // seule la redirection change.

    public function test_un_agent_peut_scanner_automatiquement_sans_redirection(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        Livewire::test(ScanPremier::class)
            ->set('document', UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf'))
            ->call('numeriserAutomatique')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $brouillon = CourrierBrouillon::firstOrFail();

        $this->assertSame($agent->id, $brouillon->cree_par_id);
        Storage::disk('s3')->assertExists($brouillon->fichier_path);
        Queue::assertPushedOn('ocr', ProcessBrouillonOcr::class, fn (ProcessBrouillonOcr $job) => $job->brouillon->id === $brouillon->id);
        Queue::assertPushedOn('replication', ReplicateFichierJob::class, fn (ReplicateFichierJob $job) => $job->chemin === $brouillon->fichier_path);
    }

    public function test_numeriser_automatique_retourne_lid_du_brouillon_pour_le_js(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(ScanPremier::class)
            ->set('document', UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf'))
            ->call('numeriserAutomatique')
            ->assertReturned(function (array $valeur) {
                $brouillon = CourrierBrouillon::firstOrFail();

                return $valeur['brouillonId'] === $brouillon->id
                    && $valeur['nomOriginal'] === 'scan.pdf';
            });
    }

    public function test_un_agent_non_autorise_ne_peut_pas_scanner_automatiquement(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->utilisateurAvecProfil('Collaborateur'));

        // Refusé dès mount() (2026-09-23), voir test ci-dessus.
        Livewire::test(ScanPremier::class)->assertForbidden();

        $this->assertDatabaseCount('courrier_brouillons', 0);
    }

    public function test_une_image_trop_petite_est_refusee_en_mode_automatique(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(ScanPremier::class)
            ->set('document', UploadedFile::fake()->image('photo.jpg', 200, 200))
            ->call('numeriserAutomatique')
            ->assertHasErrors(['document']);

        $this->assertDatabaseCount('courrier_brouillons', 0);
    }

    // 2026-09-22 — un import automatique (dossier surveillé) doit être tracé
    // avec la source dédiée pour apparaître dans le tableau admin.
    public function test_un_scan_automatique_est_trace_avec_la_source_dossier_surveille(): void
    {
        Storage::fake('s3');
        Queue::fake();

        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(ScanPremier::class)
            ->set('document', UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf'))
            ->call('numeriserAutomatique');

        $this->assertSame(CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE, CourrierBrouillon::firstOrFail()->source);
    }

    // ===== Table admin "Documents importés depuis le dossier surveillé" =====
    // (2026-09-22, demande explicite de l'utilisateur : table persistante,
    // visible même après rechargement/depuis un autre poste — distincte du
    // journal Alpine éphémère et de RegistrationForm::brouillonsEnAttente(),
    // qui liste TOUS les brouillons en attente quelle que soit leur origine.)

    public function test_le_tableau_exclut_les_brouillons_scannes_manuellement(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        CourrierBrouillon::create([
            'fichier_path' => 'brouillons/manuel.pdf', 'nom_original' => 'manuel.pdf',
            'type_mime' => 'application/pdf', 'taille' => 100, 'cree_par_id' => $agent->id,
            'source' => CourrierBrouillon::SOURCE_MANUEL,
        ]);
        $surveille = CourrierBrouillon::create([
            'fichier_path' => 'brouillons/surveille.pdf', 'nom_original' => 'surveille.pdf',
            'type_mime' => 'application/pdf', 'taille' => 100, 'cree_par_id' => $agent->id,
            'source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE,
        ]);

        $resultats = Livewire::test(ScanPremier::class)->get('importsDossierSurveille');

        $this->assertCount(1, $resultats);
        $this->assertSame($surveille->id, $resultats->first()->id);
    }

    // Contrairement à RegistrationForm::brouillonsEnAttente(), un brouillon
    // déjà finalisé (enregistré) reste visible — c'est un historique complet,
    // pas une liste "à traiter".
    public function test_le_tableau_inclut_les_imports_deja_finalises(): void
    {
        Storage::fake('s3');
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        CourrierBrouillon::create([
            'fichier_path' => 'brouillons/fini.pdf', 'nom_original' => 'fini.pdf',
            'type_mime' => 'application/pdf', 'taille' => 100, 'cree_par_id' => $agent->id,
            'source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE, 'finalise_le' => now(),
        ]);

        $resultats = Livewire::test(ScanPremier::class)->get('importsDossierSurveille');

        $this->assertCount(1, $resultats);
    }

    // 2026-10-08 — revu suite à un retour utilisateur réel : cette liste
    // est déjà entièrement restreinte à source=dossier_surveille (voir
    // ScanPremier::importsDossierSurveille()), une ressource du POSTE
    // partagé plutôt que personnelle à qui était connecté au moment précis
    // de l'import (l'import est attribué à Auth::id() de quelle que soit
    // la session active à cet instant, pas forcément celle de l'agent qui
    // consulte ensuite cette page) — tout agent qui atteint cette page voit
    // donc TOUS ces imports, sans condition de privilège. Volontairement
    // PAS brouillons.utiliser_tout (explicitement refusé par l'utilisateur,
    // "not the whole thing the admin can see" — ce privilège couvrirait
    // aussi des scans manuels d'autres agents, hors sujet ici).
    public function test_un_agent_voit_les_imports_dossier_surveille_de_tous_les_agents(): void
    {
        Storage::fake('s3');
        $agent = $this->utilisateurAvecProfil('Agent');
        $autreAgent = $this->utilisateurAvecProfil('Agent');

        $lemien = CourrierBrouillon::create([
            'fichier_path' => 'brouillons/a.pdf', 'nom_original' => 'a.pdf',
            'type_mime' => 'application/pdf', 'taille' => 100, 'cree_par_id' => $agent->id,
            'source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE,
        ]);
        $pasLeMien = CourrierBrouillon::create([
            'fichier_path' => 'brouillons/b.pdf', 'nom_original' => 'b.pdf',
            'type_mime' => 'application/pdf', 'taille' => 100, 'cree_par_id' => $autreAgent->id,
            'source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE,
        ]);

        $this->actingAs($agent);

        $resultats = Livewire::test(ScanPremier::class)->get('importsDossierSurveille');

        $this->assertCount(2, $resultats);
        $this->assertTrue($resultats->contains('id', $lemien->id));
        $this->assertTrue($resultats->contains('id', $pasLeMien->id));
    }

    // Carte de recherche ajoutée au-dessus du tableau (2026-09-22, "it
    // should look like userlist") — même gabarit que UserList::recherche.
    public function test_la_recherche_filtre_par_nom_de_fichier(): void
    {
        Storage::fake('s3');
        $agent = $this->utilisateurAvecProfil('Agent');
        $this->actingAs($agent);

        CourrierBrouillon::create([
            'fichier_path' => 'brouillons/facture.pdf', 'nom_original' => 'facture-fournisseur.pdf',
            'type_mime' => 'application/pdf', 'taille' => 100, 'cree_par_id' => $agent->id,
            'source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE,
        ]);
        CourrierBrouillon::create([
            'fichier_path' => 'brouillons/lettre.pdf', 'nom_original' => 'lettre-reclamation.pdf',
            'type_mime' => 'application/pdf', 'taille' => 100, 'cree_par_id' => $agent->id,
            'source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE,
        ]);

        $resultats = Livewire::test(ScanPremier::class)
            ->set('rechercheImports', 'facture')
            ->get('importsDossierSurveille');

        $this->assertCount(1, $resultats);
        $this->assertSame('facture-fournisseur.pdf', $resultats->first()->nom_original);
    }

    public function test_un_administrateur_voit_tous_les_imports(): void
    {
        Storage::fake('s3');
        $agent = $this->utilisateurAvecProfil('Agent');
        $autreAgent = $this->utilisateurAvecProfil('Agent');

        foreach ([$agent, $autreAgent] as $auteur) {
            CourrierBrouillon::create([
                'fichier_path' => "brouillons/{$auteur->id}.pdf", 'nom_original' => "{$auteur->id}.pdf",
                'type_mime' => 'application/pdf', 'taille' => 100, 'cree_par_id' => $auteur->id,
                'source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE,
            ]);
        }

        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        $resultats = Livewire::test(ScanPremier::class)->get('importsDossierSurveille');

        $this->assertCount(2, $resultats);
    }
}
