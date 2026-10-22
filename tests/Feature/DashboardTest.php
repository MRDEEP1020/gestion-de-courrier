<?php

namespace Tests\Feature;

use App\Livewire\Backend\Dashboard;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CourrierEnRetardNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Module 10 — tableau de bord (maquette GPT, 2026-09-16, voir DECISIONS.md
// "Tableau de bord") : une seule mise en page, contenu filtré par
// privilège/périmètre — même périmètre que CourrierList::resultats() pour
// les statistiques/la liste, pas des chiffres globaux à l'échelle de
// l'entreprise.
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil, ?Service $service = null): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id,
            'service_id' => $service?->id,
        ]);
    }

    private function courrier(array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'statut' => 'affecte',
        ], $attributs));
    }

    public function test_tout_utilisateur_connecte_accede_au_tableau_de_bord(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Collaborateur'));

        Livewire::test(Dashboard::class)->assertOk();
    }

    public function test_un_agent_ne_voit_dans_ses_statistiques_que_ses_propres_courriers(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $autreAgent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create();

        $lemien = $this->courrier(['sens' => 'entrant', 'date_mouvement' => today(), 'service_id' => $service->id]);
        CourrierHistorique::create(['courrier_id' => $lemien->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        $pasLeMien = $this->courrier(['sens' => 'entrant', 'date_mouvement' => today(), 'service_id' => $service->id]);
        CourrierHistorique::create(['courrier_id' => $pasLeMien->id, 'auteur_id' => $autreAgent->id, 'action' => 'creation']);

        $this->actingAs($agent);

        $component = Livewire::test(Dashboard::class);

        $this->assertSame(1, $component->get('courrierEntrantAujourdhui')['total']);
    }

    // Module 10 (2026-10-09) — Courrier::scopeNonTransfere() : seul le
    // statut initial 'enregistre' compte, pas un courrier déjà affecté.
    public function test_la_carte_non_transferes_compte_seulement_les_courriers_au_statut_enregistre(): void
    {
        $service = Service::factory()->create();
        $this->courrier(['statut' => 'enregistre', 'service_id' => $service->id]);
        $this->courrier(['statut' => 'affecte', 'service_id' => $service->id]);

        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        $component = Livewire::test(Dashboard::class);

        $this->assertSame(1, $component->get('courriersNonTransferes'));
    }

    public function test_un_administrateur_voit_toutes_les_statistiques(): void
    {
        $service = Service::factory()->create();
        $this->courrier(['sens' => 'entrant', 'date_mouvement' => today(), 'service_id' => $service->id]);
        $this->courrier(['sens' => 'entrant', 'date_mouvement' => today(), 'service_id' => $service->id]);

        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        $component = Livewire::test(Dashboard::class);

        $this->assertSame(2, $component->get('courrierEntrantAujourdhui')['total']);
    }

    public function test_le_delta_reflete_la_difference_avec_hier(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $service = Service::factory()->create();

        $this->courrier(['sens' => 'sortant', 'date_mouvement' => today(), 'service_id' => $service->id]);
        $this->courrier(['sens' => 'sortant', 'date_mouvement' => today(), 'service_id' => $service->id]);
        $this->courrier(['sens' => 'sortant', 'date_mouvement' => today()->subDay(), 'service_id' => $service->id]);

        $this->actingAs($admin);

        $stats = Livewire::test(Dashboard::class)->get('courrierSortantAujourdhui');

        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['delta']);
    }

    public function test_courriers_urgents_ne_compte_que_les_courriers_actifs_de_priorite_urgente(): void
    {
        $admin = $this->utilisateurAvecProfil('Administrateur');
        $service = Service::factory()->create();

        $this->courrier(['priorite' => 'urgente', 'statut' => 'affecte', 'service_id' => $service->id]);
        $this->courrier(['priorite' => 'normale', 'statut' => 'affecte', 'service_id' => $service->id]);
        // Urgent mais déjà traité — ne doit pas compter (pas dans statutsActifs()).
        $this->courrier(['priorite' => 'urgente', 'statut' => 'traite', 'service_id' => $service->id]);

        $this->actingAs($admin);

        $this->assertSame(1, Livewire::test(Dashboard::class)->get('courriersUrgents'));
    }

    // Règle n°6 — même périmètre que "Tous les courriers" pour Collaborateur : ne
    // voit que ce qui lui est actuellement affecté.
    public function test_taches_du_jour_est_scope_au_collaborateur(): void
    {
        $collaborateur = $this->utilisateurAvecProfil('Collaborateur');
        $autreCollaborateur = $this->utilisateurAvecProfil('Collaborateur');
        $service = Service::factory()->create();

        $lesien = $this->courrier(['statut' => 'affecte', 'service_id' => $service->id]);
        Affectation::create(['courrier_id' => $lesien->id, 'user_id' => $collaborateur->id, 'affecte_par_id' => $collaborateur->id]);

        $pasLeSien = $this->courrier(['statut' => 'affecte', 'service_id' => $service->id]);
        Affectation::create(['courrier_id' => $pasLeSien->id, 'user_id' => $autreCollaborateur->id, 'affecte_par_id' => $autreCollaborateur->id]);

        $this->actingAs($collaborateur);

        $taches = Livewire::test(Dashboard::class)->get('tachesDuJour');

        $this->assertNotNull($taches);
        $this->assertCount(1, $taches);
        $this->assertSame($lesien->id, $taches->first()->id);
    }

    // 2026-09-24 ("customize the tâche du jour") : triées par échéance (les
    // retards d'abord), chaque tâche affiche l'action attendue et son
    // échéance réelle, l'en-tête compte le total.
    public function test_taches_du_jour_triees_par_echeance_avec_action_et_delai(): void
    {
        $collaborateur = $this->utilisateurAvecProfil('Collaborateur');
        $service = Service::factory()->create(['code' => 'DIS']);

        $dansCinqJours = $this->courrier(['statut' => 'affecte', 'service_id' => $service->id, 'echeance' => today()->addDays(5), 'numero_reference' => 'GEC-2026-000101']);
        $enRetard = $this->courrier(['statut' => 'en_traitement', 'service_id' => $service->id, 'echeance' => today()->subDays(2), 'numero_reference' => 'GEC-2026-000102']);
        $aujourdhui = $this->courrier(['statut' => 'affecte', 'service_id' => $service->id, 'echeance' => today(), 'numero_reference' => 'GEC-2026-000103']);

        foreach ([$dansCinqJours, $enRetard, $aujourdhui] as $courrier) {
            Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id, 'affecte_par_id' => $collaborateur->id]);
        }

        $this->actingAs($collaborateur);

        $composant = Livewire::test(Dashboard::class);

        $this->assertSame([$enRetard->id, $aujourdhui->id, $dansCinqJours->id], $composant->get('tachesDuJour')->pluck('id')->all());
        $this->assertSame(3, $composant->get('totalTachesDuJour'));

        // Échéance affichée en chronomètre en direct (2026-09-24) : le
        // compte à rebours est calculé côté navigateur, le serveur fournit
        // la fin réelle de chaque tâche (data-fin), dans l'ordre d'urgence.
        $composant
            ->assertSeeInOrder([
                'GEC-2026-000102', 'data-fin="'.$enRetard->fresh()->chronoFin()->getTimestampMs().'"', 'À traiter',
                'GEC-2026-000103', 'data-fin="'.$aujourdhui->fresh()->chronoFin()->getTimestampMs().'"', 'À démarrer',
                'GEC-2026-000101', 'data-fin="'.$dansCinqJours->fresh()->chronoFin()->getTimestampMs().'"',
            ], false)
            ->assertSee('x-data="chronometre"', false)
            ->assertSee('DIS');
    }

    // 2026-10-02 — demande explicite de l'utilisateur ("remove on the
    // dashboard too", suite directe du même changement sur "Tous les
    // courriers") : <x-chronometre> masque son compte à rebours sur CETTE
    // page (:masquer-temps="true" dans "Derniers courriers" ET "Tâches du
    // jour"), ne garde que les badges d'état "Bientôt en retard"/"En
    // retard". Le composant Alpine reste bien présent (x-data="chronometre"
    // toujours là, etat toujours calculé côté client) — seul le badge
    // visible compte à rebours (classe tabular-nums) disparaît.
    public function test_le_compte_a_rebours_est_masque_sur_le_tableau_de_bord(): void
    {
        $collaborateur = $this->utilisateurAvecProfil('Collaborateur');
        $service = Service::factory()->create(['code' => 'DIS']);

        $enRetard = $this->courrier(['statut' => 'en_traitement', 'service_id' => $service->id, 'echeance' => today()->subDays(2)]);
        Affectation::create(['courrier_id' => $enRetard->id, 'user_id' => $collaborateur->id, 'affecte_par_id' => $collaborateur->id]);

        $this->actingAs($collaborateur);

        $html = Livewire::test(Dashboard::class)->html();

        $this->assertStringContainsString('x-data="chronometre"', $html);
        $this->assertStringNotContainsString('tabular-nums', $html, 'Le compte à rebours ne doit pas apparaître sur le tableau de bord.');
    }

    // Privilège dashboard.taches_du_jour non assigné à Agent par défaut
    // (voir PrivilegeSeeder) — le widget doit rester absent (null), pas une
    // liste vide affichée à tort.
    public function test_taches_du_jour_est_absent_sans_le_privilege(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        $this->assertNull(Livewire::test(Dashboard::class)->get('tachesDuJour'));
    }

    // 2026-10-05 (Module 7) — la carte "Notifications" affichait jusqu'ici
    // le texte figé "Bientôt disponible." (voir SimulationDashboardTest,
    // constat n°4, mis à jour le même jour) ; elle montre désormais un vrai
    // aperçu des notifications de l'utilisateur connecté.
    public function test_la_carte_notifications_affiche_un_vrai_apercu(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrier();
        $agent->notify(new CourrierEnRetardNotification($courrier, enRetard: true));
        $this->actingAs($agent);

        Livewire::test(Dashboard::class)
            ->assertSee($courrier->numero_reference)
            ->assertDontSee('Bientôt disponible');
    }

    public function test_la_carte_notifications_affiche_letat_vide_honnete_sans_notification(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(Dashboard::class)
            ->assertSee(__('Aucune notification pour l\'instant.'))
            ->assertDontSee('Bientôt disponible');
    }

    // Règle n°6 — jamais les notifications d'un autre utilisateur.
    public function test_la_carte_notifications_najamais_les_notifications_dun_autre_utilisateur(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $autre = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrier();
        $autre->notify(new CourrierEnRetardNotification($courrier, enRetard: true));
        $this->actingAs($agent);

        Livewire::test(Dashboard::class)->assertDontSee($courrier->numero_reference);
    }
}
