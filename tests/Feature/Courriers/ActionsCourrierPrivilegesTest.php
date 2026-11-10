<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\CourrierList;
use App\Models\Courrier;
use App\Models\CourrierBrouillon;
use App\Models\CourrierHistorique;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Actions sur un courrier séparées de la consultation (2026-09-23) :
// courriers.imprimer_bordereau, courriers.supprimer (courriers.telecharger
// retiré le 2026-11-09 : aperçu sécurisé seulement).
class ActionsCourrierPrivilegesTest extends TestCase
{
    use RefreshDatabase;

    private function courrier(array $attributs = []): Courrier
    {
        Storage::disk('s3')->put('courriers/test.pdf', "%PDF-1.4\n%%EOF");

        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'service_id' => Service::factory()->create()->id,
            'fichier_path' => 'courriers/test.pdf',
        ], $attributs));
    }

    private function avecPrivileges(array $cles, ?string $profil = null): User
    {
        $user = User::factory()->create(['profil_id' => $profil ? Profil::where('nom', $profil)->value('id') : null]);
        $user->privilegesDirectes()->attach(Privilege::whereIn('cle', $cles)->pluck('id'));

        return User::find($user->id);
    }

    // 2026-11-09 — plus aucun téléchargement pour personne (voir DECISIONS.md
    // « Fichiers : chiffrement, aperçu sécurisé, aucun téléchargement ») :
    // consulter = aperçu sécurisé ; imprimer = bordereau seulement.
    public function test_voir_sans_imprimer_et_sans_aucun_telechargement(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $courrier = $this->courrier();

        $this->actingAs($this->avecPrivileges(['courriers.voir_tout']));

        $this->get(route('apercus.manifeste', ['courrier', $courrier->id]))->assertOk()->assertJson(['pret' => false]);
        $this->get(route('courriers.bordereau', $courrier))->assertForbidden();
        $this->get(route('courriers.show', $courrier->id))->assertOk()
            ->assertDontSee(route('courriers.bordereau', $courrier->id));

        foreach (['courriers.document', 'courriers.document.apercu', 'pieces-jointes.telecharger', 'brouillons.telecharger', 'brouillons.apercu'] as $route) {
            $this->assertFalse(Route::has($route), "{$route} existe encore");
        }

        $this->assertNull(Privilege::where('cle', 'courriers.telecharger')->first());
    }

    public function test_avec_le_privilege_le_bordereau_simprime(): void
    {
        Storage::fake('s3');
        $courrier = $this->courrier();

        $this->actingAs($this->avecPrivileges(['courriers.voir_tout', 'courriers.imprimer_bordereau']));

        $this->get(route('courriers.bordereau', $courrier))->assertOk();
    }

    // L'aperçu ne contourne jamais la consultation (niveau, périmètre...).
    public function test_consulter_sans_pouvoir_voir_est_refuse(): void
    {
        Storage::fake('s3');
        Queue::fake();
        $courrier = $this->courrier(['confidentialite' => 5]);

        $this->actingAs($this->avecPrivileges(['courriers.voir_tout']));

        $this->get(route('apercus.manifeste', ['courrier', $courrier->id]))->assertForbidden();
        $this->get(route('apercus.page', ['courrier', $courrier->id, 1]))->assertForbidden();
    }

    public function test_un_brouillon_se_consulte_par_qui_peut_lutiliser(): void
    {
        Storage::fake('s3');
        Queue::fake();
        Storage::disk('s3')->put('brouillons/scan.pdf', 'scan');
        $proprietaire = $this->avecPrivileges(['courriers.creer']);
        $brouillon = CourrierBrouillon::create(['cree_par_id' => $proprietaire->id, 'fichier_path' => 'brouillons/scan.pdf', 'nom_original' => 'scan.pdf', 'type_mime' => 'application/pdf']);

        $this->actingAs($proprietaire)->get(route('apercus.manifeste', ['brouillon', $brouillon->id]))->assertOk();
        $this->actingAs($this->avecPrivileges([]))->get(route('apercus.manifeste', ['brouillon', $brouillon->id]))->assertForbidden();
    }

    // Règle n°5 : suppression logique, motif tracé, jamais un archivé.
    public function test_ladministrateur_supprime_logiquement_avec_motif(): void
    {
        Storage::fake('s3');
        $courrier = $this->courrier();
        $admin = User::factory()->create(['profil_id' => Profil::where('nom', 'Administrateur')->value('id')]);
        $this->actingAs($admin);

        Livewire::test(CourrierList::class)
            ->call('supprimerCourrier', $courrier->id)
            ->assertHasErrors('motifSuppression');
        $this->assertNotSoftDeleted($courrier);

        Livewire::test(CourrierList::class)
            ->set('motifSuppression', 'Doublon enregistré par erreur')
            ->call('supprimerCourrier', $courrier->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted($courrier);
        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'auteur_id' => $admin->id,
            'action' => 'suppression',
            'commentaire' => 'Doublon enregistré par erreur',
        ]);
        $this->get(route('courriers.show', $courrier->id))->assertNotFound();
    }

    public function test_un_courrier_archive_ne_peut_pas_etre_supprime(): void
    {
        Storage::fake('s3');
        $courrier = $this->courrier(['statut' => 'archive']);
        $this->actingAs(User::factory()->create(['profil_id' => Profil::where('nom', 'Administrateur')->value('id')]));

        Livewire::test(CourrierList::class)
            ->set('motifSuppression', 'Tentative sur archive')
            ->call('supprimerCourrier', $courrier->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted($courrier);
        $this->assertSame(0, CourrierHistorique::where('action', 'suppression')->count());
    }

    public function test_sans_le_privilege_supprimer_est_refuse(): void
    {
        Storage::fake('s3');
        $courrier = $this->courrier();
        $this->actingAs($this->avecPrivileges(['courriers.voir_tout', 'courriers.rechercher']));

        Livewire::test(CourrierList::class)
            ->set('motifSuppression', 'Tentative non autorisée')
            ->call('supprimerCourrier', $courrier->id)
            ->assertForbidden();

        $this->assertNotSoftDeleted($courrier);
    }
}
