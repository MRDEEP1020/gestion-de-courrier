<?php

namespace Tests\Feature\Courriers;

use App\Jobs\IndexCourrierJob;
use App\Livewire\Backend\RegistrationForm;
use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\MotCle;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ClassementCourrierTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(['profil_id' => $profil->id]);
    }

    private function courrierPropose(User $auteur, Service $servicePropose): Courrier
    {
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Déclaration de sinistre',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create(['code' => 'TST'])->id,
            'classement_statut' => 'propose',
            'type_document_propose' => 'Réclamation',
            'service_propose_id' => $servicePropose->id,
            'classement_propose_le' => now(),
        ]);

        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $auteur->id, 'action' => 'creation']);

        return $courrier;
    }

    public function test_lenregistrement_declenche_le_classement_sur_la_queue_indexation(): void
    {
        Queue::fake();

        $agent = $this->utilisateurAvecProfil('Agent');
        $service = Service::factory()->create(['code' => 'DIR']);
        $this->actingAs($agent);

        Livewire::test(RegistrationForm::class)
            ->set('form.date_mouvement', now()->format('Y-m-d'))
            ->set('form.service_id', $service->id)
            ->set('form.objet', 'Déclaration de sinistre')
            ->set('form.type_document', 'Lettre')
            ->call('enregistrer')
            ->assertHasNoErrors();

        Queue::assertPushedOn('indexation', IndexCourrierJob::class);
    }

    public function test_lagent_valide_la_proposition_qui_est_appliquee_et_tracee(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $sinistres = Service::factory()->create(['code' => 'SIN']);
        $courrier = $this->courrierPropose($agent, $sinistres);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Le système propose un classement')
            ->call('validerClassement')
            ->assertOk()
            ->assertSee('Classement automatique validé');

        $courrier->refresh();

        $this->assertSame('valide', $courrier->classement_statut);
        $this->assertSame('Réclamation', $courrier->type_document);
        $this->assertSame($sinistres->id, $courrier->service_id);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'classement_valide')->count());
    }

    public function test_lagent_ignore_la_proposition_et_garde_ses_valeurs(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierPropose($agent, Service::factory()->create(['code' => 'SIN']));
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('ignorerClassement')
            ->assertOk();

        $courrier->refresh();

        $this->assertSame('ignore', $courrier->classement_statut);
        $this->assertSame('Lettre', $courrier->type_document);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'classement_ignore')->count());
    }

    public function test_lagent_ajoute_puis_retire_un_mot_cle_manuel(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierPropose($agent, Service::factory()->create(['code' => 'SIN']));
        $this->actingAs($agent);

        $composant = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('nouveauMotCle', ' Urgent ')
            ->call('ajouterMotCle')
            ->assertHasNoErrors()
            ->assertSee('urgent');

        $motCle = MotCle::where('libelle', 'urgent')->firstOrFail();
        $this->assertSame('manuel', $courrier->motsCles()->where('mot_cle_id', $motCle->id)->first()->pivot->source);

        // Le libellé reste visible dans l'historique (Règle n°5) : on vérifie la
        // disparition du tag via la relation, pas via le HTML.
        $composant->call('retirerMotCle', $motCle->id)->assertOk();

        $this->assertSame(0, $courrier->motsCles()->count());
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'mot_cle_ajoute')->count());
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'mot_cle_retire')->count());
    }

    public function test_la_fiche_distingue_analyse_en_attente_et_absence_de_correspondance(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierPropose($agent, Service::factory()->create(['code' => 'SIN']));
        $courrier->update(['classement_statut' => 'non_classe', 'type_document_propose' => null, 'service_propose_id' => null, 'classement_propose_le' => null]);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('en attente de traitement')
            ->assertDontSee('Aucune règle de classement ne correspond');

        $courrier->update(['classement_analyse_le' => now()]);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Aucune règle de classement ne correspond')
            ->assertDontSee('en attente de traitement');
    }

    public function test_un_administrateur_voit_la_proposition_mais_un_collaborateur_non_affecte_ne_voit_pas_le_courrier(): void
    {
        $agent = $this->utilisateurAvecProfil('Agent');
        $courrier = $this->courrierPropose($agent, Service::factory()->create(['code' => 'SIN']));

        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertSee('Valider');

        $this->actingAs($this->utilisateurAvecProfil('Collaborateur'));
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertForbidden();
    }
}
