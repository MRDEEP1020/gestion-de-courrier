<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\MesCourriers;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Module 1/4 — page dédiée réceptionniste (2026-09-15, "new page for
// receptionist", suite de "where can i see all receptionist register doc
// enttand and with buttons for select bulk and transfere to a supervisor")
// — voir DECISIONS.md "Synchronisation avec le nouveau document SRS-GEC.pdf".
class MesCourriersTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil): User
    {
        return User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id]);
    }

    private function courrier(Service $service, array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => $service->id,
        ], $attributs));
    }

    private function courrierDe(User $agent, Service $service, array $attributs = []): Courrier
    {
        $courrier = $this->courrier($service, $attributs);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        return $courrier;
    }

    public function test_un_agent_ne_voit_que_ses_propres_courriers(): void
    {
        $agent = $this->utilisateur('Agent');
        $autreAgent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);

        $lemien = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $pasLeMien = $this->courrierDe($autreAgent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->assertSee($lemien->numero_reference)
            ->assertDontSee($pasLeMien->numero_reference);
    }

    public function test_seuls_les_courriers_entrant_apparaissent(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);

        $entrant = $this->courrierDe($agent, $service, ['sens' => 'entrant', 'statut' => 'enregistre']);
        $sortant = $this->courrierDe($agent, $service, ['sens' => 'sortant', 'statut' => 'enregistre']);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->call('changerOnglet', 'enregistre')
            ->assertSee($entrant->numero_reference)
            ->assertDontSee($sortant->numero_reference);
    }

    public function test_chaque_onglet_montre_le_bon_sous_statut(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);

        $enAttente = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $enCours = $this->courrierDe($agent, $service, ['statut' => 'en_cours_de_transfert', 'service_id' => null]);
        $transfere = $this->courrierDe($agent, $service, ['statut' => 'enregistre']);

        $this->actingAs($agent);

        $component = Livewire::test(MesCourriers::class)
            ->assertSee($enAttente->numero_reference)
            ->assertDontSee($enCours->numero_reference)
            ->assertDontSee($transfere->numero_reference);

        $component->call('changerOnglet', 'en_cours_de_transfert')
            ->assertSee($enCours->numero_reference)
            ->assertDontSee($enAttente->numero_reference);

        $component->call('changerOnglet', 'enregistre')
            ->assertSee($transfere->numero_reference)
            ->assertDontSee($enCours->numero_reference);
    }

    public function test_un_agent_transfere_plusieurs_de_ses_courriers_en_un_geste(): void
    {
        $agent = $this->utilisateur('Agent');
        $dga = $this->utilisateur('DGA');
        $agent->destinatairesTransfert()->attach($dga->id);
        $service = Service::factory()->create(['code' => 'TST']);

        $premier = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $second = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$premier->id, $second->id])
            ->set('destinataireChoisi', $dga->id)
            ->call('transfererSelection');

        $this->assertSame('en_cours_de_transfert', $premier->refresh()->statut);
        $this->assertSame('en_cours_de_transfert', $second->refresh()->statut);
        $this->assertSame($dga->id, $premier->destinataire_transfert_id);
        $this->assertSame($dga->id, $second->destinataire_transfert_id);
        $this->assertSame(2, CourrierHistorique::whereIn('courrier_id', [$premier->id, $second->id])->where('action', 'transfert')->count());
    }

    // Règle n°6 — jamais fait confiance aux ids postés : un courrier qui
    // n'appartient pas à l'agent est silencieusement ignoré, pas transféré,
    // et ne fait pas échouer le reste de la sélection.
    public function test_le_transfert_en_masse_ignore_les_courriers_qui_ne_sont_pas_les_siens(): void
    {
        $agent = $this->utilisateur('Agent');
        $autreAgent = $this->utilisateur('Agent');
        $dga = $this->utilisateur('DGA');
        $agent->destinatairesTransfert()->attach($dga->id);
        $service = Service::factory()->create(['code' => 'TST']);

        $lemien = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $pasLeMien = $this->courrierDe($autreAgent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$lemien->id, $pasLeMien->id])
            ->set('destinataireChoisi', $dga->id)
            ->call('transfererSelection');

        $this->assertSame('en_cours_de_transfert', $lemien->refresh()->statut);
        $this->assertSame('en_attente_de_transfert', $pasLeMien->refresh()->statut, 'le courrier d\'un autre agent ne doit pas être transféré');
    }

    public function test_le_transfert_en_masse_ignore_un_courrier_deja_transfere(): void
    {
        $agent = $this->utilisateur('Agent');
        $dga = $this->utilisateur('DGA');
        $agent->destinatairesTransfert()->attach($dga->id);
        $service = Service::factory()->create(['code' => 'TST']);

        $dejaTransfere = $this->courrierDe($agent, $service, ['statut' => 'en_cours_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$dejaTransfere->id])
            ->set('destinataireChoisi', $dga->id)
            ->call('transfererSelection');

        $this->assertSame('en_cours_de_transfert', $dejaTransfere->refresh()->statut);
        $this->assertSame(0, CourrierHistorique::where('courrier_id', $dejaTransfere->id)->where('action', 'transfert')->count());
    }

    // Règle n°6 — même garde-fou que CircuitCourrierTest pour ShowCourrier :
    // un destinataire hors de SA liste autorisée est refusé, aucun courrier
    // n'est transféré même si le reste de la requête est valide.
    public function test_le_transfert_en_masse_refuse_un_destinataire_non_autorise(): void
    {
        $agent = $this->utilisateur('Agent');
        $dga = $this->utilisateur('DGA');
        // Pas de attach() ici : $dga n'est PAS dans la liste autorisée de $agent.
        $service = Service::factory()->create(['code' => 'TST']);

        $courrier = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$courrier->id])
            ->set('destinataireChoisi', $dga->id)
            ->call('transfererSelection')
            ->assertHasErrors('destinataireChoisi');

        $this->assertSame('en_attente_de_transfert', $courrier->refresh()->statut);
    }

    public function test_changer_donglet_vide_la_selection(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrierDe($agent, $service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($agent);

        Livewire::test(MesCourriers::class)
            ->set('selection', [$courrier->id])
            ->call('changerOnglet', 'en_cours_de_transfert')
            ->assertSet('selection', []);
    }

    public function test_un_collaborateur_na_pas_acces_a_la_page(): void
    {
        $this->actingAs($this->utilisateur('Collaborateur'));

        Livewire::test(MesCourriers::class)->assertForbidden();
    }
}
