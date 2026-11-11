<?php

namespace Tests\Feature\Services;

use App\Jobs\SauvegarderDocumentsLocalementJob;
use App\Models\Courrier;
use App\Models\DossierClassement;
use App\Models\PieceJointe;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\SauvegardeLocale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

// 2026-11-11 (« je les veux aussi dans le pc … si un jour l'appli crash on
// pourra récupérer les doc ») — copie de secours sur le PC, rangée par
// dossier de classement, un ZIP chiffré AES-256 par courrier : copie
// complète au passe-partout + une copie par personne (son mot de passe de
// sauvegarde, limitée à ce qu'elle voit dans GEC).
class SauvegardeLocaleTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'secret-de-sauvegarde';

    private const TOUT = SauvegardeLocale::DOSSIER_TOUT;

    private string $racine;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('s3');

        $this->racine = sys_get_temp_dir().'/gec-sauvegarde-'.uniqid();
        config([
            'services.sauvegarde_locale.dossier' => $this->racine,
            'services.sauvegarde_locale.mot_de_passe' => self::MOT_DE_PASSE,
        ]);

        $this->service = Service::factory()->create(['nom' => 'Service Trésorerie', 'code' => 'ST']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->racine);

        parent::tearDown();
    }

    // Un vrai PDF : les copies personnelles l'assemblent avec qpdf.
    private function courrier(string $reference, array $attributs = []): Courrier
    {
        $chemin = "courriers/2026/ST/{$reference}.pdf";
        Storage::disk('s3')->put($chemin, Pdf::loadHTML("<p>Document {$reference}</p>")->output());

        return Courrier::create(array_merge([
            'numero_reference' => $reference,
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Facture de novembre',
            'type_document' => 'Facture',
            'mode_reception' => 'email',
            'statut' => 'affecte',
            'service_id' => $this->service->id,
            'expediteur_organisation' => 'TBS SARL',
            'fichier_path' => $chemin,
        ], $attributs));
    }

    private function synchroniser(): array
    {
        return app(SauvegardeLocale::class)->synchroniser();
    }

    private function qpdf(array $arguments): Process
    {
        $process = new Process([(string) config('services.sauvegarde_locale.qpdf'), ...$arguments]);
        $process->run();

        return $process;
    }

    private function exigerQpdf(): void
    {
        if (! $this->qpdf(['--version'])->isSuccessful()) {
            $this->markTestSkipped('qpdf absent de ce poste (GEC_QPDF_BINAIRE).');
        }
    }

    private function ouvrir(string $relatif, ?string $motDePasse = self::MOT_DE_PASSE): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->racine.'/'.$relatif), "archive absente : {$relatif}");

        if ($motDePasse !== null) {
            $zip->setPassword($motDePasse);
        }

        return $zip;
    }

    public function test_un_courrier_classe_est_copie_dans_larbre_des_dossiers_en_zip_chiffre(): void
    {
        $parent = DossierClassement::create(['nom' => 'Comptabilité', 'service_id' => $this->service->id]);
        $dossier = DossierClassement::create(['nom' => 'Facture', 'parent_id' => $parent->id, 'service_id' => $this->service->id]);
        $courrier = $this->courrier('GEC-2026-000014', ['dossier_classement_id' => $dossier->id]);
        Storage::disk('s3')->put('courriers/2026/ST/GEC-2026-000014/a1.pdf', '%PDF pièce');
        PieceJointe::create(['courrier_id' => $courrier->id, 'fichier_path' => 'courriers/2026/ST/GEC-2026-000014/a1.pdf', 'nom_original' => 'Bon de commande.pdf', 'type_mime' => 'application/pdf', 'taille' => 10]);

        $stats = $this->synchroniser();

        $this->assertSame(1, $stats['ecrits']);
        $zip = $this->ouvrir(self::TOUT.'/Dossiers/Comptabilité/Facture/GEC-2026-000014.zip');
        $this->assertSame(Storage::disk('s3')->get($courrier->fichier_path), $zip->getFromName('document.pdf'));
        $this->assertSame('%PDF pièce', $zip->getFromName('pieces-jointes/1.pdf'));
        $fiche = $zip->getFromName('fiche.txt');
        $this->assertStringContainsString('GEC-2026-000014', $fiche);
        $this->assertStringContainsString('TBS SARL', $fiche);
        $this->assertStringContainsString('Dossier : Facture', $fiche);
        $this->assertStringContainsString('pieces-jointes/1.pdf ← Bon de commande.pdf', $fiche);
        $zip->close();

        // Chiffré : chaque entrée en AES-256, illisible sans le mot de passe.
        $sansMotDePasse = $this->ouvrir(self::TOUT.'/Dossiers/Comptabilité/Facture/GEC-2026-000014.zip', null);
        $this->assertSame(ZipArchive::EM_AES_256, $sansMotDePasse->statName('document.pdf')['encryption_method']);
        $this->assertFalse($sansMotDePasse->getFromName('document.pdf'));
        $sansMotDePasse->close();

        $this->assertFileExists($this->racine.'/LISEZ-MOI.txt');
    }

    public function test_un_courrier_non_classe_va_dans_non_classes_par_service_et_annee(): void
    {
        $courrier = $this->courrier('GEC-2026-025');

        $this->synchroniser();

        $this->assertFileExists($this->racine.'/'.self::TOUT.'/Non classés/Service Trésorerie/'.$courrier->created_at->format('Y').'/GEC-2026-025.zip');
    }

    public function test_un_nom_de_dossier_avec_des_barres_devient_un_nom_windows_valide(): void
    {
        $dossier = DossierClassement::create(['nom' => '12/10/2026 - Offre Pro', 'service_id' => $this->service->id]);
        $this->courrier('GEC-2026-000004', ['dossier_classement_id' => $dossier->id]);

        $this->synchroniser();

        $this->assertFileExists($this->racine.'/'.self::TOUT.'/Dossiers/12-10-2026 - Offre Pro/GEC-2026-000004.zip');
    }

    public function test_un_courrier_reclasse_est_deplace_et_un_courrier_inchange_nest_pas_reecrit(): void
    {
        $facture = DossierClassement::create(['nom' => 'Facture', 'service_id' => $this->service->id]);
        $partenaire = DossierClassement::create(['nom' => 'partenaire', 'service_id' => $this->service->id]);
        $courrier = $this->courrier('GEC-2026-020', ['dossier_classement_id' => $facture->id]);
        $this->courrier('GEC-2026-021', ['dossier_classement_id' => $facture->id]);

        $this->synchroniser();
        $courrier->update(['dossier_classement_id' => $partenaire->id]);
        $stats = $this->synchroniser();

        $this->assertSame(['ecrits' => 0, 'deplaces' => 1, 'supprimes' => 0, 'inchanges' => 1, 'erreurs' => 0, 'personnes' => 0], $stats);
        $this->assertFileExists($this->racine.'/'.self::TOUT.'/Dossiers/partenaire/GEC-2026-020.zip');
        $this->assertFileDoesNotExist($this->racine.'/'.self::TOUT.'/Dossiers/Facture/GEC-2026-020.zip');
        $this->assertFileExists($this->racine.'/'.self::TOUT.'/Dossiers/Facture/GEC-2026-021.zip');
    }

    // Un courrier supprimé ou détruit à échéance (ConservationService::detruire()
    // le supprime) ne doit pas survivre dans la copie.
    public function test_un_courrier_supprime_ou_detruit_disparait_de_la_copie(): void
    {
        $dossier = DossierClassement::create(['nom' => 'Facture', 'service_id' => $this->service->id]);
        $courrier = $this->courrier('GEC-2026-000008', ['dossier_classement_id' => $dossier->id]);

        $this->synchroniser();
        $courrier->delete();
        $stats = $this->synchroniser();

        $this->assertSame(1, $stats['supprimes']);
        $this->assertFileDoesNotExist($this->racine.'/'.self::TOUT.'/Dossiers/Facture/GEC-2026-000008.zip');
    }

    // Miroir des dossiers de GEC : un dossier vide existe aussi sur le PC ;
    // un dossier supprimé dans GEC disparaît quand il est vide.
    public function test_chaque_dossier_existe_sur_le_pc_meme_vide_et_disparait_avec_gec(): void
    {
        $partenaire = DossierClassement::create(['nom' => 'partenaire', 'service_id' => $this->service->id]);
        $ancien = DossierClassement::create(['nom' => 'Ancien', 'service_id' => $this->service->id]);

        $this->synchroniser();
        $this->assertDirectoryExists($this->racine.'/'.self::TOUT.'/Dossiers/partenaire');
        $this->assertDirectoryExists($this->racine.'/'.self::TOUT.'/Dossiers/Ancien');

        $ancien->delete();
        $this->synchroniser();

        $this->assertDirectoryExists($this->racine.'/'.self::TOUT.'/Dossiers/partenaire');
        $this->assertDirectoryDoesNotExist($this->racine.'/'.self::TOUT.'/Dossiers/Ancien');
        $this->assertNotNull($partenaire->fresh());
    }

    public function test_sans_mot_de_passe_rien_nest_copie(): void
    {
        config(['services.sauvegarde_locale.mot_de_passe' => null]);
        $this->courrier('GEC-2026-025');

        $this->assertFalse(app(SauvegardeLocale::class)->active());
        app()->call([new SauvegarderDocumentsLocalementJob, 'handle']);

        $this->assertDirectoryDoesNotExist($this->racine);
        $this->assertNull(Cache::get(SauvegardeLocale::CLE_ETAT));
    }

    // « Les login password … plus un passe-partout » → mot de passe de
    // sauvegarde par personne : sa copie ne contient que ce qu'elle voit
    // dans GEC (même règle que l'application). Puis (« un mot de passe
    // auquel si j'entre elle pourra l'ouvrir sans le mot de passe de
    // l'utilisateur ») : un PDF qui s'ouvre avec SON mot de passe OU le
    // passe-partout, et rien d'autre.
    public function test_chaque_personne_a_sa_copie_limitee_a_ce_quelle_voit(): void
    {
        $this->exigerQpdf();
        $responsable = User::factory()->create(['name' => 'Responsable Trésorerie', 'profil_id' => Profil::where('nom', 'Responsable de service')->value('id')]);
        $responsable->forceFill(['mot_de_passe_sauvegarde' => 'perso-tresorerie', 'mot_de_passe_sauvegarde_le' => now()])->save();
        $this->service->update(['responsable_id' => $responsable->id]);
        $autreService = Service::factory()->create(['nom' => 'Département Informatique', 'code' => 'DI']);

        $sonDossier = DossierClassement::create(['nom' => 'Facture', 'service_id' => $this->service->id, 'responsable_id' => $responsable->id]);
        DossierClassement::create(['nom' => 'partenaire', 'service_id' => $autreService->id]);
        $courrier = $this->courrier('GEC-2026-000014', ['dossier_classement_id' => $sonDossier->id]);
        $this->courrier('GEC-2026-024', ['service_id' => $autreService->id]);

        // Une image (devient une page) et un fichier Word (renvoyé à « Tout »).
        Storage::disk('s3')->put('courriers/2026/ST/GEC-2026-000014/photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        PieceJointe::create(['courrier_id' => $courrier->id, 'fichier_path' => 'courriers/2026/ST/GEC-2026-000014/photo.png', 'nom_original' => 'Photo.png', 'type_mime' => 'image/png', 'taille' => 70]);
        Storage::disk('s3')->put('courriers/2026/ST/GEC-2026-000014/devis.docx', 'contenu word');
        PieceJointe::create(['courrier_id' => $courrier->id, 'fichier_path' => 'courriers/2026/ST/GEC-2026-000014/devis.docx', 'nom_original' => 'Devis.docx', 'type_mime' => 'application/msword', 'taille' => 12]);

        $stats = $this->synchroniser();

        $this->assertSame(1, $stats['personnes']);
        $this->assertSame(0, $stats['erreurs']);
        $sienne = 'Personnes/Responsable Trésorerie (n°'.$responsable->id.')';
        $pdf = $this->racine.'/'.$sienne.'/Dossiers/Facture/GEC-2026-000014.pdf';
        $this->assertFileExists($pdf);

        // Fiche + document + image ; le Word reste dans « Tout ».
        $this->assertSame('3', trim($this->qpdf(['--show-npages', '--password=perso-tresorerie', $pdf])->getOutput()));
        $this->assertSame('3', trim($this->qpdf(['--show-npages', '--password='.self::MOT_DE_PASSE, $pdf])->getOutput()));
        $this->assertSame(2, $this->qpdf(['--show-npages', '--password=mauvais', $pdf])->getExitCode());
        $this->assertSame(2, $this->qpdf(['--show-npages', $pdf])->getExitCode());
        // Avec son mot de passe : lecture seule (« on ne peut pas le
        // télécharger ou imprimer ») ; le passe-partout est le propriétaire.
        $chiffrement = $this->qpdf(['--show-encryption', '--password=perso-tresorerie', $pdf])->getOutput();
        $this->assertStringContainsString('AESv3', $chiffrement);
        $this->assertStringContainsString('Supplied password is user password', $chiffrement);
        $this->assertStringContainsString('print high resolution: not allowed', $chiffrement);
        $this->assertStringContainsString('extract for any purpose: not allowed', $chiffrement);
        $this->assertStringContainsString('modify anything: not allowed', $chiffrement);
        $this->assertStringContainsString('Supplied password is owner password', $this->qpdf(['--show-encryption', '--password='.self::MOT_DE_PASSE, $pdf])->getOutput());

        // Ni le courrier ni le dossier d'un service qu'elle ne voit pas.
        $this->assertDirectoryDoesNotExist($this->racine.'/'.$sienne.'/Non classés/Département Informatique');
        $this->assertDirectoryDoesNotExist($this->racine.'/'.$sienne.'/Dossiers/partenaire');

        // La copie complète, elle, a tout.
        $this->assertDirectoryExists($this->racine.'/'.self::TOUT.'/Dossiers/partenaire');
        $this->assertFileExists($this->racine.'/'.self::TOUT.'/Non classés/Département Informatique/'.now()->format('Y').'/GEC-2026-024.zip');
    }

    public function test_sans_mot_de_passe_de_sauvegarde_pas_de_copie_et_retire_elle_disparait(): void
    {
        $collaborateur = User::factory()->create(['name' => 'Collab Test', 'profil_id' => Profil::where('nom', 'Collaborateur')->value('id')]);
        $this->courrier('GEC-2026-025');

        $this->synchroniser();
        $this->assertDirectoryDoesNotExist($this->racine.'/Personnes/Collab Test (n°'.$collaborateur->id.')');

        $collaborateur->forceFill(['mot_de_passe_sauvegarde' => 'perso-collab', 'mot_de_passe_sauvegarde_le' => now()])->save();
        $this->synchroniser();
        $this->assertDirectoryExists($this->racine.'/Personnes/Collab Test (n°'.$collaborateur->id.')');

        $collaborateur->forceFill(['mot_de_passe_sauvegarde' => null, 'mot_de_passe_sauvegarde_le' => null])->save();
        $this->synchroniser();
        $this->assertDirectoryDoesNotExist($this->racine.'/Personnes/Collab Test (n°'.$collaborateur->id.')');
    }

    // La copie unique d'avant (Dossiers/ et Non classés/ à la racine) passe
    // dans « Tout (passe-partout) » sans qu'aucun ZIP soit réécrit.
    public function test_lancienne_copie_unique_est_rangee_dans_tout_sans_reecriture(): void
    {
        $dossier = DossierClassement::create(['nom' => 'Facture', 'service_id' => $this->service->id]);
        $this->courrier('GEC-2026-000014', ['dossier_classement_id' => $dossier->id]);
        $this->courrier('GEC-2026-025');
        $this->synchroniser();

        foreach (['Dossiers', 'Non classés', '.gec-manifeste.json'] as $element) {
            rename($this->racine.'/'.self::TOUT.'/'.$element, $this->racine.'/'.$element);
        }

        $stats = $this->synchroniser();

        $this->assertSame(2, $stats['inchanges']);
        $this->assertSame(0, $stats['ecrits'] + $stats['deplaces']);
        $this->assertFileExists($this->racine.'/'.self::TOUT.'/Dossiers/Facture/GEC-2026-000014.zip');
        $this->assertDirectoryDoesNotExist($this->racine.'/Dossiers');
    }

    // « Est-ce qu'elle serait créée automatiquement chaque fois qu'un
    // utilisateur va créer un dossier » — oui, dans les secondes qui suivent.
    public function test_creer_un_dossier_ou_classer_un_courrier_relance_la_copie_aussitot(): void
    {
        $dossier = DossierClassement::create(['nom' => 'Nouveau dossier', 'service_id' => $this->service->id]);
        Queue::assertPushed(SauvegarderDocumentsLocalementJob::class, 1);

        // Une seule mise à jour en attente à la fois : les changements
        // suivants n'en ajoutent pas tant qu'elle n'a pas démarré.
        $courrier = $this->courrier('GEC-2026-030');
        Queue::assertPushed(SauvegarderDocumentsLocalementJob::class, 1);

        // Elle a démarré (verrou d'unicité libéré) : un classement en relance une.
        Cache::lock(UniqueLock::getKey(new SauvegarderDocumentsLocalementJob))->forceRelease();
        $courrier->update(['dossier_classement_id' => $dossier->id]);
        Queue::assertPushed(SauvegarderDocumentsLocalementJob::class, 2);
    }

    public function test_sans_configuration_aucune_mise_a_jour_nest_demandee(): void
    {
        config(['services.sauvegarde_locale.mot_de_passe' => null]);

        DossierClassement::create(['nom' => 'Nouveau dossier', 'service_id' => $this->service->id]);

        Queue::assertNotPushed(SauvegarderDocumentsLocalementJob::class);
    }

    public function test_la_commande_met_la_copie_a_jour_et_garde_letat(): void
    {
        $this->courrier('GEC-2026-025');

        $this->artisan('gec:sauvegarde-locale')->assertSuccessful();

        $this->assertSame(1, Cache::get(SauvegardeLocale::CLE_ETAT)['ecrits']);
    }
}
