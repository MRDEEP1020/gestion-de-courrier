<?php

namespace Tests\Feature;

use App\Jobs\RefreshStatistiquesJob;
use App\Livewire\Backend\Statistiques;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

// Module 10 étendu — « Dashboard statistiques » façon Power BI (2026-10-30).
// Voir RefreshStatistiquesJob (table de faits reconstruite en file
// d'attente, Règle n°1) et Statistiques (périmètre et niveau de
// confidentialité, Règle n°6 ; filtres au clic).
class StatistiquesTest extends TestCase
{
    use RefreshDatabase;

    private User $responsableA;

    private Service $serviceA;

    private Service $serviceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responsableA = $this->utilisateur('Responsable de service');
        $this->serviceA = Service::factory()->create(['code' => 'SVA', 'nom' => 'Service Alpha', 'responsable_id' => $this->responsableA->id]);
        $this->serviceB = Service::factory()->create(['code' => 'SVB', 'nom' => 'Service Bêta', 'responsable_id' => $this->utilisateur('Responsable de service')->id]);
    }

    private function utilisateur(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    private function courrier(Service $service, array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-TST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'priorite' => 'normale',
            'service_id' => $service->id,
            'statut' => 'en_traitement',
            'confidentialite' => 1,
        ], $attributs));
    }

    private function calculer(): void
    {
        (new RefreshStatistiquesJob)->handle();
    }

    // Règle n°1/7 — jamais calculé : le calcul part en file d'attente (une
    // seule fois), jamais exécuté pendant l'affichage.
    public function test_le_calcul_part_en_file_dattente_quand_rien_na_ete_calcule(): void
    {
        Queue::fake();
        $this->actingAs($this->responsableA);

        Livewire::test(Statistiques::class)->assertSee(__('Statistiques en cours de calcul'));
        Livewire::test(Statistiques::class);

        Queue::assertPushed(RefreshStatistiquesJob::class, 1);
    }

    public function test_le_job_construit_une_ligne_de_faits_par_courrier(): void
    {
        $collaborateur = $this->utilisateur('Collaborateur');
        // Échéances posées sans relancer le calcul automatique de la date
        // limite SLA (Courrier::saving, qui l'écraserait).
        $traite = $this->courrier($this->serviceA, ['date_mouvement' => now()->subDays(3), 'statut' => 'traite']);
        $traite->forceFill(['date_limite' => now()->addDays(5)])->saveQuietly();
        CourrierHistorique::create(['courrier_id' => $traite->id, 'auteur_id' => $this->responsableA->id, 'action' => 'validation_acceptee']);
        $enRetard = $this->courrier($this->serviceA, ['date_mouvement' => now()->subDays(10)]);
        $enRetard->forceFill(['date_limite' => now()->subDay()])->saveQuietly();
        Affectation::create(['courrier_id' => $enRetard->id, 'user_id' => $collaborateur->id, 'affecte_par_id' => $this->responsableA->id]);
        $this->courrier($this->serviceB, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->calculer();
        $faits = DB::table('statistiques_courriers')->get()->keyBy('courrier_id');

        $this->assertCount(3, $faits);
        $this->assertSame(3, (int) $faits[$traite->id]->jours_traitement);
        $this->assertSame(1, (int) $faits[$traite->id]->dans_les_delais);
        $this->assertSame(0, (int) $faits[$traite->id]->en_cours);
        $this->assertSame('en_traitement', $faits[$enRetard->id]->etape);
        $this->assertSame(1, (int) $faits[$enRetard->id]->en_retard);
        $this->assertSame(10, (int) $faits[$enRetard->id]->anciennete_jours);
        $this->assertSame($collaborateur->id, (int) $faits[$enRetard->id]->collaborateur_id);
    }

    // Règle n°6 — un responsable ne voit que son service, et jamais un
    // courrier au-dessus de son niveau de confidentialité ; un autre service
    // posté à la main est ignoré.
    public function test_le_responsable_ne_voit_que_son_perimetre(): void
    {
        $this->courrier($this->serviceA);
        $this->courrier($this->serviceA);
        $this->courrier($this->serviceA, ['confidentialite' => 5]);
        $this->courrier($this->serviceB);
        $this->calculer();
        $this->actingAs($this->responsableA);

        $test = Livewire::test(Statistiques::class)->assertSee('Service Alpha')->assertDontSee('Service Bêta');
        $this->assertSame(2, $test->get('indicateurs')['recus']);

        $test->call('filtrer', 'service', (string) $this->serviceB->id);
        $this->assertSame(2, $test->get('indicateurs')['recus']);
    }

    public function test_la_periode_filtre_les_mois(): void
    {
        $this->courrier($this->serviceA);
        $this->courrier($this->serviceA, ['date_mouvement' => now()->startOfMonth()->subMonth()]);
        $this->calculer();
        $this->actingAs($this->responsableA);

        $test = Livewire::test(Statistiques::class)->set('periode', 'ce_mois');
        $this->assertSame(1, $test->get('indicateurs')['recus']);

        $test->set('periode', '3_mois');
        $this->assertSame(2, $test->get('indicateurs')['recus']);
    }

    // Filtre au clic (façon Power BI) : la page entière est filtrée, le
    // graphique cliqué garde toutes ses barres ; second clic = retiré.
    public function test_un_clic_filtre_toute_la_page(): void
    {
        $this->courrier($this->serviceA, ['type_document' => 'Facture']);
        $this->courrier($this->serviceA, ['type_document' => 'Lettre']);
        $this->courrier($this->serviceA, ['type_document' => 'Lettre']);
        $this->calculer();
        $this->actingAs($this->responsableA);

        $test = Livewire::test(Statistiques::class)
            ->call('filtrer', 'type', 'Facture')
            ->assertSet('type', 'Facture')
            ->assertSee(__('Tout effacer'));

        $this->assertSame(1, $test->get('indicateurs')['recus']);
        $this->assertCount(2, $test->get('parType'), 'le graphique cliqué garde toutes ses barres');
        $this->assertSame(1, array_sum(array_column($test->get('parEtape'), 'valeur')));

        $test->call('filtrer', 'type', 'Facture')->assertSet('type', '');
        $this->assertSame(3, $test->get('indicateurs')['recus']);

        // Dimension inconnue (requête modifiée) : ignorée.
        $test->call('filtrer', 'colonne_inconnue', 'x');
        $this->assertSame(3, $test->get('indicateurs')['recus']);
    }

    // Droits refusés (Règle n°7) : sans « Consulter les statistiques ».
    public function test_sans_le_privilege_la_page_est_refusee(): void
    {
        $this->actingAs($this->utilisateur('Agent'));

        Livewire::test(Statistiques::class)->assertForbidden();
        $this->get(route('statistiques.index'))->assertForbidden();
    }

    public function test_le_menu_mene_a_la_page(): void
    {
        $this->actingAs($this->responsableA)
            ->get(route('profile.edit'))
            ->assertSee(route('statistiques.index'));
    }
}
