<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\CourrierList;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-09-24 — la page "Transferts" (WorkflowQueue) est supprimée à la
// demande de l'utilisateur (doublon de "Tous les courriers") : ses tests
// sont repris ici sur CourrierList avec le filtre "En cours (tous)"
// (statut=actifs), même périmètre par rôle.
class CourrierListEnCoursTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil, ?Service $service = null): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id,
            'service_id' => $service?->id,
        ]);
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
            'service_id' => $service->id,
        ], $attributs));
    }

    private function enCours()
    {
        return Livewire::withQueryParams(['statut' => CourrierList::STATUT_ACTIFS])->test(CourrierList::class);
    }

    public function test_un_responsable_ne_voit_que_les_courriers_en_cours_de_son_service(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $monService = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $autreService = Service::factory()->create(['code' => 'AUT']);

        $aTraiter = $this->courrier($monService, ['statut' => 'enregistre']);
        $cloture = $this->courrier($monService, ['statut' => 'traite']);
        $horsPerimetre = $this->courrier($autreService, ['statut' => 'enregistre']);

        $this->actingAs($responsable);

        $this->enCours()
            ->assertSee($aTraiter->numero_reference)
            ->assertDontSee($cloture->numero_reference)
            ->assertDontSee($horsPerimetre->numero_reference);
    }

    public function test_un_collaborateur_ne_voit_que_les_courriers_qui_lui_sont_affectes(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $autreCollaborateur = $this->utilisateur('Collaborateur', $service);

        $lemien = $this->courrier($service, ['statut' => 'en_traitement']);
        Affectation::create(['courrier_id' => $lemien->id, 'user_id' => $collaborateur->id]);
        $pasLeMien = $this->courrier($service, ['statut' => 'en_traitement']);
        Affectation::create(['courrier_id' => $pasLeMien->id, 'user_id' => $autreCollaborateur->id]);

        $this->actingAs($collaborateur);

        $this->enCours()
            ->assertSee($lemien->numero_reference)
            ->assertDontSee($pasLeMien->numero_reference);
    }

    public function test_une_dga_retrouve_ce_qui_attend_sa_validation(): void
    {
        $service = Service::factory()->create(['code' => 'TST']);
        $enAttente = $this->courrier($service, ['statut' => 'en_cours_de_transfert']);
        $dejaValideParAutrui = $this->courrier($service, ['statut' => 'enregistre']);

        $this->actingAs($this->utilisateur('DGA'));

        Livewire::withQueryParams(['statut' => 'en_cours_de_transfert'])->test(CourrierList::class)
            ->assertSee($enAttente->numero_reference)
            ->assertDontSee($dejaValideParAutrui->numero_reference);
    }

    public function test_un_administrateur_voit_les_courriers_en_cours_de_tous_les_services(): void
    {
        $courrierA = $this->courrier(Service::factory()->create(['code' => 'AAA']), ['statut' => 'enregistre']);
        $courrierB = $this->courrier(Service::factory()->create(['code' => 'BBB']), ['statut' => 'affecte']);
        $archive = $this->courrier(Service::factory()->create(['code' => 'CCC']), ['statut' => 'archive']);

        $this->actingAs($this->utilisateur('Administrateur'));

        $this->enCours()
            ->assertSee($courrierA->numero_reference)
            ->assertSee($courrierB->numero_reference)
            ->assertDontSee($archive->numero_reference);
    }

    // L'ancienne adresse ne casse aucun lien existant.
    public function test_lancienne_adresse_de_la_file_redirige_vers_la_liste_en_cours(): void
    {
        $this->actingAs($this->utilisateur('Collaborateur'));

        $this->get('/courriers/a-traiter')->assertRedirect('/courriers/rechercher?statut=actifs');
    }
}
