<?php

namespace Tests\Feature;

use App\Livewire\Backend\CourrierList;
use App\Livewire\Backend\RegleList;
use App\Models\Courrier;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// "same thing for all the pages, each card should be a permission, go one
// page by page" (2026-09-23, voir DECISIONS.md "Chaque carte du tableau de
// bord sa propre permission" — étendu ce même jour à courrierList et
// regleList, les deux seules autres pages avec une carte de statistiques/
// compteur partagée ou totalement ungated). Assertions sur les computed
// properties (Livewire::test()->get(...)), pas sur du texte brut — même
// piège que sur le tableau de bord (des libellés génériques réapparaissent
// ailleurs sur une page complète).
class PagesCardsPrivilegesTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $profil): User
    {
        return User::factory()->create(['profil_id' => Profil::where('nom', $profil)->value('id')]);
    }

    private function retirer(string $cle, string $profil): void
    {
        Privilege::where('cle', $cle)->firstOrFail()->profils()->detach(Profil::where('nom', $profil)->value('id'));
    }

    private function remettre(string $cle, string $profil): void
    {
        Privilege::where('cle', $cle)->firstOrFail()->profils()->syncWithoutDetaching(Profil::where('nom', $profil)->value('id'));
    }

    // ===== "Tous les courriers" : 4 cartes KPI =====

    public function test_courrierlist_toutes_les_cartes_visibles_par_defaut(): void
    {
        $this->actingAs($this->utilisateur('Agent'));

        $stats = Livewire::test(CourrierList::class)->get('statistiques');

        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('enTraitement', $stats);
        $this->assertArrayHasKey('termines', $stats);
        $this->assertArrayHasKey('enErreur', $stats);
    }

    // Chaque carte disparaît INDÉPENDAMMENT des autres — jamais un bloc
    // partagé comme l'ancienne courriers.voir_statistiques.
    public function test_courrierlist_chaque_carte_a_sa_propre_cle_independante(): void
    {
        $cartes = [
            'courriers.voir_carte_total' => 'total',
            'courriers.voir_carte_en_traitement' => 'enTraitement',
            'courriers.voir_carte_termines' => 'termines',
            'courriers.voir_carte_en_erreur' => 'enErreur',
        ];

        $agent = $this->utilisateur('Agent');

        foreach ($cartes as $cle => $entree) {
            $this->retirer($cle, 'Agent');
            $this->actingAs(User::find($agent->id));

            $stats = Livewire::test(CourrierList::class)->get('statistiques');
            $this->assertArrayNotHasKey($entree, $stats, "{$entree} devrait être absente sans {$cle}");

            foreach ($cartes as $autreCle => $autreEntree) {
                if ($autreCle !== $cle) {
                    $this->assertArrayHasKey($autreEntree, $stats, "{$autreEntree} ne devrait pas être affectée par le retrait de {$cle}");
                }
            }

            $this->remettre($cle, 'Agent');
        }
    }

    public function test_courrierlist_sans_aucune_carte_le_tableau_est_vide(): void
    {
        $agent = $this->utilisateur('Agent');
        foreach (['courriers.voir_carte_total', 'courriers.voir_carte_en_traitement', 'courriers.voir_carte_termines', 'courriers.voir_carte_en_erreur'] as $cle) {
            $this->retirer($cle, 'Agent');
        }
        $this->actingAs(User::find($agent->id));

        $stats = Livewire::test(CourrierList::class)->assertOk()->get('statistiques');

        $this->assertSame([], $stats);
    }

    public function test_courrierlist_ladministrateur_garde_toutes_les_cartes(): void
    {
        $this->actingAs($this->utilisateur('Administrateur'));

        $stats = Livewire::test(CourrierList::class)->get('statistiques');

        $this->assertCount(4, $stats);
    }

    public function test_lancienne_cle_partagee_courrierlist_a_ete_retiree_du_catalogue(): void
    {
        $this->assertDatabaseMissing('privileges', ['cle' => 'courriers.voir_statistiques']);
    }

    // ===== "Règles de classement" : carte "X courrier(s) sans décision" =====

    public function test_reglelist_le_compteur_de_reanalyse_est_absent_sans_le_privilege(): void
    {
        // Lecteur avec accès à la page (regles_classement.voir) mais pas
        // au privilège d'action (regles_classement.reanalyser).
        $lecteur = User::factory()->create(['profil_id' => null]);
        $lecteur->privilegesDirectes()->attach(Privilege::where('cle', 'regles_classement.voir')->value('id'));
        $this->actingAs(User::find($lecteur->id));

        $composant = Livewire::test(RegleList::class)->assertOk();

        $this->assertNull($composant->get('courriersAReanalyser'));
        $composant->assertDontSee('sans décision de classement');
    }

    public function test_reglelist_le_compteur_de_reanalyse_est_visible_avec_le_privilege(): void
    {
        $service = Service::factory()->create();
        Courrier::create([
            'numero_reference' => 'GEC-2026-'.random_int(1, 999999),
            'sens' => 'entrant', 'date_mouvement' => now(), 'objet' => 'x',
            'type_document' => 'Lettre', 'mode_reception' => 'email',
            'service_id' => $service->id,
        ]);

        $this->actingAs($this->utilisateur('Administrateur'));

        $composant = Livewire::test(RegleList::class);

        $this->assertNotNull($composant->get('courriersAReanalyser'));
        $composant->assertSee('sans décision de classement');
    }
}
