<?php

namespace Tests\Feature\Jobs;

use App\Events\ClassementPropose;
use App\Jobs\IndexCourrierJob;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\MotCle;
use App\Models\RegleClassement;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IndexCourrierJobTest extends TestCase
{
    use RefreshDatabase;

    private function courrier(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Déclaration de sinistre',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create(['code' => 'TST'])->id,
        ], $attributs));
    }

    public function test_le_job_propose_un_classement_attache_les_tags_trace_et_notifie(): void
    {
        Event::fake([ClassementPropose::class]);

        $regle = RegleClassement::factory()->create(['mots_cles' => ['sinistre'], 'type_document_propose' => 'Réclamation', 'tags' => ['sinistre']]);
        $courrier = $this->courrier(['texte_ocr' => 'indemnisation indemnisation indemnisation véhicule véhicule assuré', 'ocr_statut' => 'reussi']);

        app()->call([new IndexCourrierJob($courrier), 'handle']);

        $courrier->refresh();

        $this->assertSame('propose', $courrier->classement_statut);
        $this->assertSame('Réclamation', $courrier->type_document_propose);
        $this->assertSame($regle->id, $courrier->classement_regle_id);
        $this->assertSame('Lettre', $courrier->type_document, 'la valeur saisie n\'est pas écrasée avant validation');

        $tags = $courrier->motsCles->mapWithKeys(fn (MotCle $m) => [$m->libelle => $m->pivot->source])->all();
        $this->assertSame('regle', $tags['sinistre']);
        $this->assertSame('ocr', $tags['indemnisation']);

        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'classement_propose')->count());
        Event::assertDispatched(ClassementPropose::class, fn (ClassementPropose $e) => $e->courrierId === $courrier->id);
    }

    public function test_sans_regle_correspondante_le_courrier_reste_non_classe_mais_recoit_les_mots_cles_ocr(): void
    {
        Event::fake([ClassementPropose::class]);

        $courrier = $this->courrier(['objet' => 'Demande de devis', 'texte_ocr' => 'devis devis assurance habitation', 'ocr_statut' => 'reussi']);

        app()->call([new IndexCourrierJob($courrier), 'handle']);

        $this->assertSame('non_classe', $courrier->refresh()->classement_statut);
        $this->assertNotNull($courrier->classement_analyse_le, 'l\'analyse est horodatée même sans correspondance');
        $this->assertContains('devis', $courrier->motsCles->pluck('libelle')->all());
        // La première analyse est notifiée pour sortir la fiche de « en attente ».
        Event::assertDispatchedTimes(ClassementPropose::class, 1);

        // Une réanalyse sans changement ne notifie plus.
        app()->call([new IndexCourrierJob($courrier), 'handle']);

        Event::assertDispatchedTimes(ClassementPropose::class, 1);
    }

    public function test_une_proposition_en_attente_qui_ne_tient_plus_est_retiree(): void
    {
        Event::fake([ClassementPropose::class]);

        $regle = RegleClassement::factory()->create(['mots_cles' => ['sinistre'], 'type_document_propose' => 'Réclamation']);
        $courrier = $this->courrier([
            'objet' => 'Demande de devis', // ne correspond plus à la règle
            'classement_statut' => 'propose',
            'type_document_propose' => 'Réclamation',
            'classement_regle_id' => $regle->id,
            'classement_propose_le' => now(),
        ]);

        app()->call([new IndexCourrierJob($courrier), 'handle']);

        $courrier->refresh();

        $this->assertSame('non_classe', $courrier->classement_statut);
        $this->assertNull($courrier->type_document_propose);
        $this->assertNull($courrier->classement_regle_id);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'classement_retire')->count());
        Event::assertDispatched(ClassementPropose::class);
    }

    public function test_une_proposition_validee_qui_ne_tient_plus_nest_pas_touchee(): void
    {
        Event::fake([ClassementPropose::class]);

        $courrier = $this->courrier(['objet' => 'Demande de devis', 'classement_statut' => 'valide', 'type_document_propose' => 'Réclamation', 'classement_analyse_le' => now()]);

        app()->call([new IndexCourrierJob($courrier), 'handle']);

        $this->assertSame('valide', $courrier->refresh()->classement_statut);
        $this->assertSame('Réclamation', $courrier->type_document_propose);
        Event::assertNotDispatched(ClassementPropose::class);
    }

    public function test_une_decision_de_lagent_nest_pas_remise_en_cause_si_la_proposition_est_identique(): void
    {
        Event::fake([ClassementPropose::class]);

        RegleClassement::factory()->create(['mots_cles' => ['sinistre'], 'type_document_propose' => 'Réclamation']);
        $courrier = $this->courrier(['classement_statut' => 'ignore', 'type_document_propose' => 'Réclamation', 'classement_analyse_le' => now()]);

        app()->call([new IndexCourrierJob($courrier), 'handle']);

        $this->assertSame('ignore', $courrier->refresh()->classement_statut);
        $this->assertSame(0, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'classement_propose')->count());
        Event::assertNotDispatched(ClassementPropose::class);
    }

    public function test_un_texte_ocr_de_qualite_insuffisante_nest_jamais_utilise_pour_classer(): void
    {
        Event::fake([ClassementPropose::class]);

        // Scan jugé illisible par le Module 2 (echec_qualite) : le texte est
        // conservé pour affichage, mais ne doit alimenter ni la classification
        // ni les mots-clés OCR (constat de revue, corrigé le 2026-09-04).
        RegleClassement::factory()->create(['mots_cles' => ['sinistre'], 'type_document_propose' => 'Réclamation']);
        $courrier = $this->courrier([
            'objet' => 'Demande de devis',
            'texte_ocr' => 'sinistre sinistre sinistre indemnisation',
            'ocr_statut' => 'echec_qualite',
        ]);

        app()->call([new IndexCourrierJob($courrier), 'handle']);

        $courrier->refresh();

        $this->assertSame('non_classe', $courrier->classement_statut);
        $this->assertNull($courrier->classement_regle_id);
        $this->assertSame([], $courrier->motsCles->pluck('libelle')->all());
    }

    public function test_un_tag_de_regle_aussi_present_dans_le_texte_ocr_nest_attache_quune_fois(): void
    {
        Event::fake([ClassementPropose::class]);

        RegleClassement::factory()->create(['mots_cles' => ['réclamation'], 'type_document_propose' => 'Réclamation', 'tags' => ['réclamation']]);
        $courrier = $this->courrier(['objet' => 'Réclamation', 'texte_ocr' => 'réclamation réclamation réclamation contrat', 'ocr_statut' => 'reussi']);

        app()->call([new IndexCourrierJob($courrier), 'handle']);

        $tags = $courrier->refresh()->motsCles->mapWithKeys(fn (MotCle $m) => [$m->libelle => $m->pivot->source])->all();

        $this->assertSame('regle', $tags['réclamation'], 'la règle l\'emporte sur l\'OCR pour un même libellé');
        $this->assertSame('ocr', $tags['contrat']);
        $this->assertSame(2, $courrier->motsCles()->count());
    }

    public function test_un_tag_manuel_existant_nest_pas_ecrase(): void
    {
        $courrier = $this->courrier(['texte_ocr' => 'urgent urgent urgent', 'ocr_statut' => 'reussi']);
        $courrier->motsCles()->attach(MotCle::create(['libelle' => 'urgent'])->id, ['source' => 'manuel']);

        app()->call([new IndexCourrierJob($courrier), 'handle']);

        $this->assertSame('manuel', $courrier->refresh()->motsCles->firstWhere('libelle', 'urgent')->pivot->source);
    }
}
