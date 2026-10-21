<?php

namespace Tests\Feature\Courriers;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Règle n°6 — régression constatée le 2026-09-23 (audit des pages) : un
// courrier dont la fiche était refusée (niveau de confidentialité
// insuffisant) restait listé, numéro et objet compris, sur le tableau de
// bord, "Courriers enregistrés", la file d'attente et "Mes courriers" —
// chaque page tenait sa propre copie du périmètre au lieu de
// Courrier::scopeVisiblePar().
class VisibiliteListesTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->agent = User::where('email', 'agent@test.local')->firstOrFail();
        $this->responsable = User::where('email', 'responsable.dsin@test.local')->firstOrFail();
    }

    private function courrier(string $statut, int $confidentialite, string $objet): Courrier
    {
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => $objet,
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::where('code', 'DSIN')->firstOrFail()->id,
            'statut' => $statut,
            'confidentialite' => $confidentialite,
        ]);

        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $this->agent->id, 'action' => 'creation']);

        return $courrier;
    }

    public function test_un_courrier_trop_confidentiel_nest_liste_nulle_part(): void
    {
        $this->courrier('en_attente_de_transfert', 5, 'OBJET-SECRET-A');
        $this->courrier('enregistre', 5, 'OBJET-SECRET-B');

        foreach (['/dashboard', '/courriers/enregistres', '/courriers/mes-courriers'] as $page) {
            $this->actingAs($this->agent)->get($page)->assertOk()->assertDontSee('OBJET-SECRET');
        }

        // '/courriers/rechercher?statut=actifs' : remplace l'ancienne file
        // '/courriers/a-traiter' (supprimée le 2026-09-24, redirige ici).
        foreach (['/dashboard', '/courriers/enregistres', '/courriers/rechercher?statut=actifs'] as $page) {
            $this->actingAs($this->responsable)->get($page.($page === '/courriers/enregistres' ? '?onglet=enregistre' : ''))
                ->assertOk()->assertDontSee('OBJET-SECRET');
        }
    }

    public function test_un_courrier_au_niveau_autorise_reste_liste(): void
    {
        $this->courrier('en_attente_de_transfert', 1, 'OBJET-VISIBLE-A');
        $this->courrier('enregistre', 1, 'OBJET-VISIBLE-B');

        $this->actingAs($this->agent)->get('/courriers/mes-courriers')->assertSee('OBJET-VISIBLE-A');
        $this->actingAs($this->responsable)->get('/courriers/rechercher?statut=actifs')->assertSee('OBJET-VISIBLE-B');
        $this->actingAs($this->responsable)->get('/dashboard')->assertSee('OBJET-VISIBLE-B');
    }

    // Un utilisateur sans profil (ex. inscription publique) n'a aucun
    // privilège de consultation : il ne doit rien voir, pas tout voir.
    public function test_un_utilisateur_sans_profil_ne_voit_aucun_courrier(): void
    {
        $this->courrier('enregistre', 1, 'OBJET-ORPHELIN');

        $sansProfil = User::factory()->create(['profil_id' => null]);

        $this->assertSame(0, Courrier::query()->visiblePar($sansProfil)->count());
        // Sans dashboard.voir : redirigé vers ses paramètres (menus pilotés
        // par privilège, 2026-09-23), donc jamais la liste du tableau de bord.
        $this->actingAs($sansProfil)->get('/dashboard')->assertRedirect(route('profile.edit'));
    }
}
