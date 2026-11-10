<?php

namespace Tests\Feature;

use App\Jobs\ChiffrerFichiersExistantsJob;
use App\Models\Courrier;
use App\Models\EmpreinteFichier;
use App\Models\Service;
use App\Services\ChiffrementFichiers;
use App\Services\CleChiffrementAbsente;
use App\Services\FichierChiffreInvalide;
use App\Services\IntegriteFichiers;
use App\Services\StockageChiffre;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\TestCase;

// Module 9 — chiffrement des fichiers au repos (2026-11-09, voir DECISIONS.md
// « Fichiers : chiffrement, aperçu sécurisé, aucun téléchargement »).
class ChiffrementFichiersTest extends TestCase
{
    use RefreshDatabase;

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('framework/testing/chiffrement-'.uniqid());
        File::ensureDirectoryExists($this->dossier);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dossier);

        parent::tearDown();
    }

    // Blocs de 1 Kio pour exercer les cas multi-blocs avec de petits contenus.
    private function chiffrement(array $cles = [1 => 'cle-numero-un-de-trente-deux-oct'], ?int $courante = 1): ChiffrementFichiers
    {
        return new ChiffrementFichiers($cles, $courante, 10);
    }

    private function disque(?ChiffrementFichiers $chiffrement = null): array
    {
        $local = new LocalFilesystemAdapter($this->dossier);
        $chiffre = new StockageChiffre($local, $chiffrement ?? $this->chiffrement());

        return [new FilesystemAdapter(new Filesystem($chiffre), $chiffre), $local];
    }

    public function test_un_contenu_se_dechiffre_a_lidentique_quelle_que_soit_sa_taille(): void
    {
        $chiffrement = $this->chiffrement();

        foreach ([0, 1, 1023, 1024, 1025, 3000, 4096] as $taille) {
            $clair = $taille === 0 ? '' : random_bytes($taille);
            $chiffre = $chiffrement->chiffrerChaine($clair);

            $this->assertTrue(ChiffrementFichiers::estChiffre($chiffre), "{$taille} octets");
            $this->assertSame($clair, $chiffrement->dechiffrerChaine($chiffre), "{$taille} octets");
            $this->assertSame($taille, $chiffrement->tailleClaire($chiffre, strlen($chiffre)), "{$taille} octets");
        }
    }

    public function test_le_contenu_chiffre_ne_revele_rien_et_change_a_chaque_fois(): void
    {
        $chiffrement = $this->chiffrement();
        $clair = str_repeat('Réclamation sinistre n°4521 — Monsieur Kamga. ', 50);

        $premier = $chiffrement->chiffrerChaine($clair);
        $second = $chiffrement->chiffrerChaine($clair);

        $this->assertStringNotContainsString('Kamga', $premier);
        $this->assertNotSame($premier, $second);
    }

    public function test_toute_modification_troncature_ou_permutation_est_detectee(): void
    {
        $chiffrement = $this->chiffrement();
        $chiffre = $chiffrement->chiffrerChaine(random_bytes(3000));
        $bloc = 1024 + ChiffrementFichiers::TAILLE_ETIQUETTE;
        $entete = substr($chiffre, 0, ChiffrementFichiers::TAILLE_ENTETE);
        $blocs = str_split(substr($chiffre, ChiffrementFichiers::TAILLE_ENTETE), $bloc);

        $modifie = $chiffre;
        $modifie[500] = chr(ord($modifie[500]) ^ 0x01);
        $tronque = $entete.$blocs[0].$blocs[1];
        $permute = $entete.$blocs[1].$blocs[0].$blocs[2];

        foreach (['modifié' => $modifie, 'tronqué' => $tronque, 'permuté' => $permute] as $cas => $contenu) {
            try {
                $chiffrement->dechiffrerChaine($contenu);
                $this->fail("Fichier {$cas} accepté");
            } catch (FichierChiffreInvalide) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_une_autre_cle_ne_dechiffre_pas_mais_lancienne_cle_reste_utilisable(): void
    {
        $ancienne = $this->chiffrement();
        $chiffre = $ancienne->chiffrerChaine('archive de 2026');

        $this->expectExceptionObject(new FichierChiffreInvalide('Clé de chiffrement n°1 inconnue : fichier illisible avec la configuration actuelle.'));

        try {
            $this->chiffrement([2 => 'une-tout-autre-cle-de-32-octets!'], 2)->dechiffrerChaine($chiffre);
        } finally {
            // Changement de clé : la nouvelle chiffre, l'ancienne (gardée en
            // « précédente ») déchiffre toujours les fichiers existants.
            $rotation = $this->chiffrement([1 => 'cle-numero-un-de-trente-deux-oct', 2 => 'une-tout-autre-cle-de-32-octets!'], 2);
            $this->assertSame('archive de 2026', $rotation->dechiffrerChaine($chiffre));
            $this->assertSame(2, ord($rotation->chiffrerChaine('nouveau')[5]));
        }
    }

    public function test_sans_cle_aucun_fichier_nest_ecrit_en_clair(): void
    {
        [$disque] = $this->disque(new ChiffrementFichiers([], null));

        $this->expectException(CleChiffrementAbsente::class);

        $disque->put('courriers/doc.pdf', 'contenu');
    }

    public function test_le_disque_chiffre_tout_ce_quil_ecrit_et_dechiffre_tout_ce_quil_lit(): void
    {
        [$disque, $brut] = $this->disque();
        $clair = '%PDF-1.7 Réclamation Kamga '.random_bytes(2500);

        $disque->put('courriers/doc.pdf', $clair);
        $disque->putFileAs('courriers', UploadedFile::fake()->createWithContent('pj.pdf', $clair), 'pj.pdf');

        foreach (['courriers/doc.pdf', 'courriers/pj.pdf'] as $chemin) {
            $stocke = $brut->read($chemin);
            $this->assertTrue(ChiffrementFichiers::estChiffre($stocke), $chemin);
            $this->assertStringNotContainsString('Kamga', $stocke, $chemin);

            $this->assertSame($clair, $disque->get($chemin), $chemin);
            $this->assertSame($clair, stream_get_contents($disque->readStream($chemin)), $chemin);
            $this->assertSame(strlen($clair), $disque->size($chemin), $chemin);
        }

        // Déplacer recopie le contenu chiffré tel quel.
        $disque->move('courriers/doc.pdf', 'courriers/DI/doc.pdf');
        $this->assertSame($clair, $disque->get('courriers/DI/doc.pdf'));
    }

    public function test_un_fichier_encore_en_clair_reste_lisible(): void
    {
        [$disque, $brut] = $this->disque();
        $brut->write('courriers/ancien.pdf', '%PDF ancien document', new Config);

        $this->assertSame('%PDF ancien document', $disque->get('courriers/ancien.pdf'));
        $this->assertSame('%PDF ancien document', stream_get_contents($disque->readStream('courriers/ancien.pdf')));
    }

    public function test_les_fichiers_existants_sont_chiffres_en_place_sans_changer_leur_contenu(): void
    {
        $chiffrement = $this->chiffrement();
        $this->app->instance(ChiffrementFichiers::class, $chiffrement);
        [$disque, $brut] = $this->disque($chiffrement);
        Storage::set('s3', $disque);
        config(['filesystems.disks.s3_backup.bucket' => '']);

        $brut->write('courriers/2026/DI/a.pdf', '%PDF document A', new Config);
        $brut->write('avatars/1.png', 'image', new Config);
        $disque->put('courriers/2026/DI/b.pdf', '%PDF document B');

        app()->call([new ChiffrerFichiersExistantsJob, 'handle']);

        foreach (['courriers/2026/DI/a.pdf' => '%PDF document A', 'avatars/1.png' => 'image', 'courriers/2026/DI/b.pdf' => '%PDF document B'] as $chemin => $contenu) {
            $this->assertTrue(ChiffrementFichiers::estChiffre($brut->read($chemin)), $chemin);
            $this->assertSame($contenu, $disque->get($chemin), $chemin);
        }

        $this->assertSame(['chiffres' => 2, 'deja_chiffres' => 1, 'echecs' => 0], array_intersect_key(Cache::get(ChiffrerFichiersExistantsJob::CLE_ETAT), array_flip(['chiffres', 'deja_chiffres', 'echecs'])));
    }

    public function test_un_fichier_chiffre_modifie_sur_le_stockage_est_signale_altere(): void
    {
        $chiffrement = $this->chiffrement();
        [$disque, $brut] = $this->disque($chiffrement);
        Storage::set('s3', $disque);
        $disque->put('courriers/doc.pdf', '%PDF original '.str_repeat('x', 2000));

        $courrier = Courrier::create([
            'numero_reference' => 'GEC-2026-TST-000777', 'sens' => 'entrant', 'date_mouvement' => now(),
            'objet' => 'Courrier', 'type_document' => 'Lettre', 'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id, 'fichier_path' => 'courriers/doc.pdf',
        ]);
        $integrite = app(IntegriteFichiers::class);
        $empreinte = $integrite->sceller(EmpreinteFichier::TYPE_COURRIER, $courrier->id, 'courriers/doc.pdf');
        $this->assertSame(hash('sha256', '%PDF original '.str_repeat('x', 2000)), $empreinte->sha256);

        $stocke = $brut->read('courriers/doc.pdf');
        $stocke[100] = chr(ord($stocke[100]) ^ 0xFF);
        $brut->write('courriers/doc.pdf', $stocke, new Config);

        $this->assertSame(EmpreinteFichier::STATUT_ALTEREE, $integrite->verifier($empreinte->fresh())->statut);
    }

    public function test_les_disques_de_lapplication_sont_chiffres(): void
    {
        $this->assertInstanceOf(StockageChiffre::class, Storage::disk('s3')->getAdapter());
        $this->assertTrue(app(ChiffrementFichiers::class)->cleConfiguree());
    }
}
