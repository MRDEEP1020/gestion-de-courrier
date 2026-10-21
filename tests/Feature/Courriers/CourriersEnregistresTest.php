<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\CourriersEnregistres;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Module 1/4/8 — "Courriers enregistrés" (2026-09-16, voir DECISIONS.md
// "Navigation (navbar + sidebar)") : mêmes 3 onglets que MesCourriers, mais
// à l'échelle de ce que l'utilisateur peut voir (périmètre CourrierList),
// pas limité à ses propres courriers.
class CourriersEnregistresTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil, ?Service $service = null): User
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
        ], $attributs));
    }

    public function test_un_administrateur_voit_les_courriers_de_tout_le_monde(): void
    {
        $admin = $this->utilisateur('Administrateur');
        $courrier = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);

        $this->actingAs($admin);

        Livewire::test(CourriersEnregistres::class)->assertSee($courrier->numero_reference);
    }

    public function test_un_responsable_de_service_ne_voit_que_les_courriers_de_son_service(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $monService = Service::factory()->create(['responsable_id' => $responsable->id]);
        $autreService = Service::factory()->create();

        $lemien = $this->courrier(['statut' => 'enregistre', 'service_id' => $monService->id]);
        $pasLeMien = $this->courrier(['statut' => 'enregistre', 'service_id' => $autreService->id]);

        $this->actingAs($responsable);

        Livewire::test(CourriersEnregistres::class)
            ->call('changerOnglet', 'enregistre')
            ->assertSee($lemien->numero_reference)
            ->assertDontSee($pasLeMien->numero_reference);
    }

    public function test_un_collaborateur_ne_voit_que_les_courriers_qui_lui_sont_affectes(): void
    {
        $collaborateur = $this->utilisateur('Collaborateur');
        $autreCollaborateur = $this->utilisateur('Collaborateur');

        $lesien = $this->courrier(['statut' => 'affecte']);
        Affectation::create(['courrier_id' => $lesien->id, 'user_id' => $collaborateur->id, 'affecte_par_id' => $collaborateur->id]);

        $pasLeSien = $this->courrier(['statut' => 'affecte']);
        Affectation::create(['courrier_id' => $pasLeSien->id, 'user_id' => $autreCollaborateur->id, 'affecte_par_id' => $autreCollaborateur->id]);

        $this->actingAs($collaborateur);

        // "affecte" n'est aucun des 3 onglets — même périmètre que
        // CourrierList, mais rien à voir ici (les 3 onglets ne couvrent que
        // en_attente_de_transfert/en_cours_de_transfert/enregistre).
        Livewire::test(CourriersEnregistres::class)
            ->assertDontSee($lesien->numero_reference)
            ->assertDontSee($pasLeSien->numero_reference);
    }

    public function test_seuls_les_courriers_entrant_apparaissent(): void
    {
        $admin = $this->utilisateur('Administrateur');
        $entrant = $this->courrier(['sens' => 'entrant', 'statut' => 'enregistre']);
        $sortant = $this->courrier(['sens' => 'sortant', 'statut' => 'enregistre']);

        $this->actingAs($admin);

        Livewire::test(CourriersEnregistres::class)
            ->call('changerOnglet', 'enregistre')
            ->assertSee($entrant->numero_reference)
            ->assertDontSee($sortant->numero_reference);
    }

    public function test_chaque_onglet_montre_le_bon_sous_statut(): void
    {
        $admin = $this->utilisateur('Administrateur');
        $enAttente = $this->courrier(['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        $enCours = $this->courrier(['statut' => 'en_cours_de_transfert', 'service_id' => null]);

        $this->actingAs($admin);

        Livewire::test(CourriersEnregistres::class)
            ->assertSee($enAttente->numero_reference)
            ->assertDontSee($enCours->numero_reference)
            ->call('changerOnglet', 'en_cours_de_transfert')
            ->assertSee($enCours->numero_reference)
            ->assertDontSee($enAttente->numero_reference);
    }

    public function test_un_profil_sans_privilege_rechercher_na_pas_acces(): void
    {
        // Aucun profil de test n'a "rechercher" retiré par défaut — on
        // vérifie plutôt le garde-fou lui-même via la Policy, cohérent avec
        // CourrierListTest::test_un_profil_inconnu_narrive_pas_a_la_page().
        $utilisateur = User::factory()->create(['profil_id' => null]);

        $this->actingAs($utilisateur);

        Livewire::test(CourriersEnregistres::class)->assertForbidden();
    }
}
