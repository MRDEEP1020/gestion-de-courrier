<?php

namespace Tests\Feature\Courriers;

use App\Jobs\IndexCourrierJob;
use App\Jobs\ReplicateFichierJob;
use App\Livewire\Backend\RegistrationForm;
use App\Livewire\Backend\ScanPremier;
use App\Models\Courrier;
use App\Models\CourrierBrouillon;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\PieceJointeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Module 1/2 — scans du dossier surveillé conservés et rangés sur le poste de
// numérisation (2026-11-09, voir DECISIONS.md « Scans conservés et rangés sur
// le poste de numérisation »). Le déplacement du fichier lui-même se fait
// dans le navigateur (resources/js/scan-watcher.js) ; ici : le lien
// scan → courrier et ce que le serveur accepte d'en dire.
class RangementScansLocauxTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    private function brouillon(User $agent): CourrierBrouillon
    {
        Storage::disk('s3')->put('brouillons/scan-'.uniqid().'.pdf', 'contenu du scan');

        return CourrierBrouillon::create([
            'fichier_path' => Storage::disk('s3')->files('brouillons')[0],
            'nom_original' => 'scan.pdf',
            'type_mime' => 'application/pdf',
            'taille' => 15,
            'cree_par_id' => $agent->id,
            'ocr_statut' => 'reussi',
            'texte_ocr' => 'Texte reconnu.',
            'source' => CourrierBrouillon::SOURCE_DOSSIER_SURVEILLE,
        ]);
    }

    public function test_le_scan_enregistre_reste_relie_a_son_courrier_pour_etre_range(): void
    {
        Storage::fake('s3');
        Queue::fake([ReplicateFichierJob::class, IndexCourrierJob::class]);
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $brouillon = $this->brouillon($agent);
        $this->actingAs($agent);

        $formulaire = Livewire::test(RegistrationForm::class, ['brouillonId' => $brouillon->id])
            ->set('form.sens', 'sortant')
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Courrier scanné')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasNoErrors()
            // La page déclenche le rangement sur le poste.
            ->assertDispatched('courrier-enregistre');

        $courrier = $service->courriers()->firstOrFail();
        $this->assertSame($brouillon->id, $courrier->brouillon_origine_id);
        $this->assertDatabaseMissing('courrier_brouillons', ['id' => $brouillon->id]);

        $this->assertSame([[
            'brouillonId' => $brouillon->id,
            'reference' => $courrier->numero_reference,
            'annee' => $courrier->created_at->format('Y'),
            'mois' => $courrier->created_at->format('m'),
            'piece' => false,
        ]], $formulaire->instance()->infosRangement([$brouillon->id, 999999, 'x']));
    }

    public function test_un_scan_devenu_piece_jointe_est_range_comme_annexe(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $admin = $this->utilisateur('Administrateur');
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-2026-TST-000321', 'sens' => 'entrant', 'date_mouvement' => now(),
            'objet' => 'Courrier', 'type_document' => 'Lettre', 'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id,
        ]);
        $brouillon = $this->brouillon($admin);

        $piece = app(PieceJointeService::class)->attacherDepuisBrouillon($courrier, $brouillon, $admin->id);
        $this->assertSame($brouillon->id, $piece->brouillon_origine_id);

        $this->actingAs($admin);
        $infos = Livewire::test(ScanPremier::class)->instance()->infosRangement([$brouillon->id]);

        $this->assertCount(1, $infos);
        $this->assertTrue($infos[0]['piece']);
        $this->assertSame('GEC-2026-TST-000321', $infos[0]['reference']);
    }

    // Règle n°6 — le serveur ne révèle la référence que de courriers que la
    // personne peut voir.
    public function test_un_courrier_hors_perimetre_nest_jamais_revele(): void
    {
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-2026-TST-000654', 'sens' => 'entrant', 'date_mouvement' => now(),
            'objet' => 'Courrier', 'type_document' => 'Lettre', 'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id, 'brouillon_origine_id' => 4242,
        ]);

        $this->actingAs($this->utilisateur('Agent'));

        $this->assertSame([], Livewire::test(ScanPremier::class)->instance()->infosRangement([4242]));
        $this->assertSame([], Livewire::test(ScanPremier::class)->instance()->infosRangement([]));
        $this->assertNotNull($courrier->fresh());
    }
}
