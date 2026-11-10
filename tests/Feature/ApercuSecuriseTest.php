<?php

namespace Tests\Feature;

use App\Events\ApercuPret;
use App\Jobs\ChiffrerFichiersExistantsJob;
use App\Jobs\PreparerApercuJob;
use App\Jobs\ScellerFichierJob;
use App\Livewire\Backend\SecuriteAudit;
use App\Models\ApercuDocument;
use App\Models\Courrier;
use App\Models\CourrierBrouillon;
use App\Models\CourrierConsultation;
use App\Models\CourrierHistorique;
use App\Models\EmpreinteFichier;
use App\Models\PieceJointe;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\ApercuSecurise;
use App\Services\IntegriteFichiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Module 9 — aperçu sécurisé (2026-11-09, voir DECISIONS.md « Fichiers :
// chiffrement, aperçu sécurisé, aucun téléchargement ») : pages images
// préparées en file d'attente, filigranées à l'affichage, jamais le fichier.
class ApercuSecuriseTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    private function avecPrivileges(array $cles): User
    {
        $user = User::factory()->create(['profil_id' => null]);
        $user->privilegesDirectes()->attach(Privilege::whereIn('cle', $cles)->pluck('id'));

        return User::find($user->id);
    }

    private function courrier(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-2026-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id,
            'statut' => 'en_traitement',
            'fichier_path' => 'courriers/doc.pdf',
        ], $attributs));
    }

    private function image(int $largeur = 400, int $hauteur = 560, string $format = 'png'): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 20, 20, 'Courrier de test', imagecolorallocate($image, 0, 0, 0));
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    private function preparer(string $type, int $id): void
    {
        app()->call([new PreparerApercuJob($type, $id), 'handle']);
    }

    public function test_un_document_pas_encore_prepare_part_en_file_dattente(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $courrier = $this->courrier();

        $this->actingAs($this->utilisateur('Administrateur'))
            ->get(route('apercus.manifeste', ['courrier', $courrier->id]))
            ->assertOk()
            ->assertJson(['pret' => false, 'canal' => "courrier.{$courrier->id}", 'evenement' => '.apercu.pret']);

        Queue::assertPushed(PreparerApercuJob::class, fn ($job) => $job->type === 'courrier' && $job->id === $courrier->id);
        Queue::assertPushedOn('ocr', PreparerApercuJob::class);
    }

    public function test_une_photo_est_preparee_puis_servie_filigranee_jamais_en_original(): void
    {
        Storage::fake('s3');
        Event::fake([ApercuPret::class]);
        Storage::disk('s3')->put('courriers/scan.png', $this->image());
        $courrier = $this->courrier(['fichier_path' => 'courriers/scan.png']);

        $this->preparer('courrier', $courrier->id);

        Event::assertDispatched(ApercuPret::class, fn ($e) => $e->canal === "courrier.{$courrier->id}");
        $apercu = ApercuDocument::where('fichier_type', 'courrier')->where('fichier_id', $courrier->id)->firstOrFail();
        $this->assertSame([[400, 560]], $apercu->pages);
        $stockee = Storage::disk('s3')->get('apercus/courrier/'.$courrier->id.'/1.jpg');

        $admin = $this->utilisateur('Administrateur');
        $this->actingAs($admin)->get(route('apercus.manifeste', ['courrier', $courrier->id]))
            ->assertOk()
            ->assertJson(['pret' => true, 'pages' => [['url' => route('apercus.page', ['courrier', $courrier->id, 1]), 'largeur' => 400, 'hauteur' => 560]]]);

        $page = $this->get(route('apercus.page', ['courrier', $courrier->id, 1]))->assertOk();
        $this->assertSame('image/jpeg', $page->headers->get('content-type'));
        $this->assertStringContainsString('no-store', $page->headers->get('cache-control'));
        $this->assertStringStartsWith("\xFF\xD8", $page->getContent());
        $this->assertNotSame($stockee, $page->getContent(), 'La page servie doit porter le filigrane.');
        $this->assertSame([400, 560], array_slice(getimagesizefromstring($page->getContent()), 0, 2));

        $this->assertDatabaseHas('courrier_consultations', ['courrier_id' => $courrier->id, 'user_id' => $admin->id, 'action' => 'apercu']);
    }

    public function test_un_pdf_est_rendu_page_par_page_par_ghostscript_en_mode_sur(): void
    {
        Storage::fake('s3');
        Event::fake([ApercuPret::class]);
        config(['services.ghostscript.binary' => 'C:/gs/bin/gswin64c.exe']);
        Storage::disk('s3')->put('courriers/doc.pdf', '%PDF-1.7 deux pages');
        $courrier = $this->courrier();
        $page = $this->image(300, 420, 'jpeg');

        Process::fake(function (PendingProcess $process) use ($page) {
            $sortie = substr(collect($process->command)->first(fn ($argument) => str_starts_with($argument, '-sOutputFile=')), strlen('-sOutputFile='));
            file_put_contents(str_replace('%04d', '0001', $sortie), $page);
            file_put_contents(str_replace('%04d', '0002', $sortie), $page);

            return Process::result();
        });

        $this->preparer('courrier', $courrier->id);

        Process::assertRan(fn ($process) => in_array('-dSAFER', $process->command, true) && in_array('-sDEVICE=jpeg', $process->command, true));
        $this->assertSame([[300, 420], [300, 420]], ApercuDocument::where('fichier_type', 'courrier')->where('fichier_id', $courrier->id)->firstOrFail()->pages);
        Storage::disk('s3')->assertExists('apercus/courrier/'.$courrier->id.'/2.jpg');
    }

    public function test_un_format_sans_rendu_possible_est_signale(): void
    {
        Storage::fake('s3');
        Event::fake([ApercuPret::class]);
        Storage::disk('s3')->put('courriers/scan.tiff', 'II*');
        $courrier = $this->courrier(['fichier_path' => 'courriers/scan.tiff']);

        $this->preparer('courrier', $courrier->id);

        $this->actingAs($this->utilisateur('Administrateur'))
            ->get(route('apercus.manifeste', ['courrier', $courrier->id]))
            ->assertOk()
            ->assertJson(['pret' => true, 'pages' => [], 'message' => __('Aperçu sécurisé indisponible pour ce format de fichier.')]);
    }

    public function test_une_page_hors_document_ou_sans_droit_est_refusee(): void
    {
        Storage::fake('s3');
        Event::fake([ApercuPret::class]);
        Storage::disk('s3')->put('courriers/scan.png', $this->image());
        $courrier = $this->courrier(['fichier_path' => 'courriers/scan.png', 'confidentialite' => 5]);
        $this->preparer('courrier', $courrier->id);

        $this->actingAs($this->utilisateur('Administrateur'))->get(route('apercus.page', ['courrier', $courrier->id, 2]))->assertNotFound();
        $this->actingAs($this->avecPrivileges(['courriers.voir_tout']))->get(route('apercus.page', ['courrier', $courrier->id, 1]))->assertForbidden();
        $this->get(route('apercus.manifeste', ['inconnu', $courrier->id]))->assertNotFound();
    }

    public function test_une_piece_jointe_se_consulte_avec_le_droit_aux_pieces_jointes(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $courrier = $this->courrier();
        $piece = PieceJointe::create(['courrier_id' => $courrier->id, 'fichier_path' => 'courriers/pj.pdf', 'nom_original' => 'pj.pdf', 'type_mime' => 'application/pdf', 'taille' => 12]);

        $this->actingAs($this->avecPrivileges(['courriers.voir_tout']))->get(route('apercus.manifeste', ['piece-jointe', $piece->id]))->assertForbidden();
        $this->actingAs($this->avecPrivileges(['courriers.voir_tout', 'courriers.voir_pieces_jointes']))->get(route('apercus.manifeste', ['piece-jointe', $piece->id]))->assertOk();
    }

    public function test_un_depot_prepare_lapercu_mais_pas_un_simple_deplacement(): void
    {
        config(['services.archivage.apercu_auto' => true]);
        Queue::fake();

        $courrier = $this->courrier();
        PieceJointe::create(['courrier_id' => $courrier->id, 'fichier_path' => 'courriers/pj.pdf', 'nom_original' => 'pj.pdf', 'type_mime' => 'application/pdf', 'taille' => 12]);
        CourrierBrouillon::create(['cree_par_id' => $this->utilisateur('Agent')->id, 'fichier_path' => 'brouillons/scan.pdf', 'nom_original' => 'scan.pdf', 'type_mime' => 'application/pdf']);
        $courrier->update(['fichier_path' => 'courriers/2026/DI/doc.pdf']);

        foreach (['courrier', 'piece-jointe', 'brouillon'] as $type) {
            Queue::assertPushed(PreparerApercuJob::class, fn ($job) => $job->type === $type);
        }
        Queue::assertPushed(PreparerApercuJob::class, 3);
    }

    public function test_une_nouvelle_numerisation_reprepare_lapercu_et_rescelle_sans_alerte(): void
    {
        config(['services.archivage.apercu_auto' => true, 'services.archivage.scellement_auto' => true]);
        Queue::fake();
        $courrier = $this->courrier();
        ApercuDocument::create(['fichier_type' => 'courrier', 'fichier_id' => $courrier->id, 'statut' => ApercuDocument::STATUT_PRET, 'pages' => [[10, 10]], 'genere_le' => now()]);

        $courrier->documentRemplace = true;
        $courrier->update(['ocr_statut' => 'en_cours']);

        $this->assertNull(ApercuDocument::where('fichier_id', $courrier->id)->first());
        Queue::assertPushed(PreparerApercuJob::class, fn ($job) => $job->type === 'courrier' && $job->id === $courrier->id);
        Queue::assertPushed(ScellerFichierJob::class, fn ($job) => $job->id === $courrier->id && $job->remplacement);

        // Le scellement d'un remplacement remplace l'empreinte, jamais une alerte.
        Storage::fake('s3');
        Storage::disk('s3')->put('courriers/doc.pdf', 'nouvelle numérisation');
        EmpreinteFichier::create(['fichier_type' => EmpreinteFichier::TYPE_COURRIER, 'fichier_id' => $courrier->id, 'chemin' => 'courriers/doc.pdf', 'sha256' => hash('sha256', 'ancienne numérisation'), 'scelle_le' => now()->subDay(), 'statut' => EmpreinteFichier::STATUT_OK]);

        $empreinte = app(IntegriteFichiers::class)->sceller(EmpreinteFichier::TYPE_COURRIER, $courrier->id, 'courriers/doc.pdf', true);

        $this->assertSame(hash('sha256', 'nouvelle numérisation'), $empreinte->sha256);
        $this->assertSame(EmpreinteFichier::STATUT_OK, $empreinte->statut);
        $this->assertSame(0, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'integrite_alerte')->count());
    }

    public function test_le_filigrane_couvre_la_page_sans_changer_ses_dimensions(): void
    {
        $source = $this->image(800, 1100, 'jpeg');
        $filigrane = app(ApercuSecurise::class)->filigraner($source, 'Responsable Tresor · 09/11/2026 11:40 · GEC-2026-000012 · consultation seule');

        $this->assertNotSame($source, $filigrane);
        $this->assertSame([800, 1100], array_slice(getimagesizefromstring($filigrane), 0, 2));
    }

    public function test_letat_du_chiffrement_et_la_conversion_des_fichiers_existants(): void
    {
        Queue::fake();

        $this->actingAs($this->utilisateur('Administrateur'));
        Livewire::test(SecuriteAudit::class)->set('onglet', 'systeme')
            ->assertSee(__('Chiffrement des fichiers'))
            ->assertSee(__('Chaque nouveau fichier est chiffré (AES-256) sur le stockage et sur la copie de secours.'))
            ->call('chiffrerExistants');
        Queue::assertPushed(ChiffrerFichiersExistantsJob::class);

        $this->actingAs($this->avecPrivileges(['administration.audit']));
        Livewire::test(SecuriteAudit::class)->call('chiffrerExistants')->assertForbidden();
        Queue::assertPushed(ChiffrerFichiersExistantsJob::class, 1);
    }

    public function test_le_journal_distingue_la_consultation_dune_piece_jointe(): void
    {
        $this->assertSame(__('Pièce jointe consultée'), (new CourrierConsultation(['action' => 'apercu_piece_jointe']))->libelle());
    }
}
