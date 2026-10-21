<?php

namespace Tests\Feature\Admin;

use App\Jobs\ReanalyserCourriersJob;
use App\Livewire\Backend\RegleList;
use App\Models\Courrier;
use App\Models\Profil;
use App\Models\RegleClassement;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RegleListTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecProfil(string $nomProfil): User
    {
        $profil = Profil::firstOrCreate(['nom' => $nomProfil]);

        return User::factory()->create(['profil_id' => $profil->id]);
    }

    public function test_un_administrateur_cree_une_regle(): void
    {
        $service = Service::factory()->create(['code' => 'SIN']);
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(RegleList::class)
            ->set('nom', 'Réclamations')
            ->set('motsCles', 'réclamation, Sinistre , ,dommage')
            ->set('champs', ['objet', 'texte_ocr'])
            ->set('typeDocumentPropose', 'Réclamation')
            ->set('serviceProposeId', $service->id)
            ->set('tags', 'réclamation')
            ->set('priorite', 10)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $regle = RegleClassement::firstOrFail();

        $this->assertSame(['réclamation', 'Sinistre', 'dommage'], $regle->mots_cles);
        $this->assertSame(['objet', 'texte_ocr'], $regle->champs);
        $this->assertSame('Réclamation', $regle->type_document_propose);
        $this->assertSame($service->id, $regle->service_propose_id);
        $this->assertSame(10, $regle->priorite);
        $this->assertTrue($regle->actif);
    }

    public function test_une_regle_sans_proposition_est_refusee(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(RegleList::class)
            ->set('nom', 'Vide')
            ->set('motsCles', 'sinistre')
            ->call('enregistrer')
            ->assertHasErrors(['typeDocumentPropose']);

        $this->assertSame(0, RegleClassement::count());
    }

    public function test_un_administrateur_modifie_desactive_et_supprime_une_regle(): void
    {
        $regle = RegleClassement::factory()->create(['nom' => 'Avant']);
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        $composant = Livewire::test(RegleList::class)
            ->call('modifier', $regle->id)
            ->assertSet('nom', 'Avant')
            ->assertSet('motsCles', 'réclamation, sinistre')
            ->set('nom', 'Après')
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame('Après', $regle->refresh()->nom);

        $composant->call('basculer', $regle->id);
        $this->assertFalse($regle->refresh()->actif);

        $composant->call('supprimer', $regle->id);
        $this->assertSame(0, RegleClassement::count());
    }

    public function test_un_administrateur_relance_lanalyse_des_courriers_sans_decision(): void
    {
        Queue::fake();

        Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000001',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Réclamation',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create(['code' => 'TST'])->id,
        ]);
        $this->actingAs($this->utilisateurAvecProfil('Administrateur'));

        Livewire::test(RegleList::class)
            ->assertSee('1 courrier(s) sans décision de classement')
            ->call('reanalyser')
            ->assertOk();

        Queue::assertPushedOn('indexation', ReanalyserCourriersJob::class);
    }

    public function test_un_agent_ne_peut_pas_acceder_aux_regles(): void
    {
        $this->actingAs($this->utilisateurAvecProfil('Agent'));

        Livewire::test(RegleList::class)->assertForbidden();
    }
}
