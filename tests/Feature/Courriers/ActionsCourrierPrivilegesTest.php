<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\CourrierList;
use App\Models\Courrier;
use App\Models\CourrierBrouillon;
use App\Models\CourrierHistorique;
use App\Models\PieceJointe;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Actions sur un courrier séparées de la consultation (2026-09-23) :
// courriers.telecharger, courriers.imprimer_bordereau, courriers.supprimer.
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

    public function test_voir_sans_telecharger_ni_imprimer(): void
    {
        Storage::fake('s3');
        $courrier = $this->courrier();
        Storage::disk('s3')->put('pj/annexe.pdf', 'annexe');
        $piece = PieceJointe::create(['courrier_id' => $courrier->id, 'fichier_path' => 'pj/annexe.pdf', 'nom_original' => 'annexe.pdf', 'taille' => 6, 'type_mime' => 'application/pdf']);

        $this->actingAs($this->avecPrivileges(['courriers.voir_tout']));

        $this->get(route('courriers.document.apercu', $courrier))->assertOk();
        $this->get(route('courriers.document', $courrier))->assertForbidden();
        $this->get(route('pieces-jointes.telecharger', $piece))->assertForbidden();
        $this->get(route('courriers.bordereau', $courrier))->assertForbidden();
        $this->get(route('courriers.show', $courrier->id))->assertOk()
            ->assertDontSee(route('courriers.document', $courrier->id))
            ->assertDontSee(route('courriers.bordereau', $courrier->id));
    }

    public function test_avec_les_privileges_telecharger_et_imprimer_fonctionnent(): void
    {
        Storage::fake('s3');
        $courrier = $this->courrier();

        $this->actingAs($this->avecPrivileges(['courriers.voir_tout', 'courriers.telecharger', 'courriers.imprimer_bordereau']));

        $this->get(route('courriers.document', $courrier))->assertOk();
        $this->get(route('courriers.bordereau', $courrier))->assertOk();
    }

    // Le privilège ne contourne jamais la consultation (niveau, périmètre...).
    public function test_telecharger_sans_pouvoir_voir_est_refuse(): void
    {
        Storage::fake('s3');
        $courrier = $this->courrier(['confidentialite' => 5]);

        $this->actingAs($this->avecPrivileges(['courriers.voir_tout', 'courriers.telecharger']));

        $this->get(route('courriers.document', $courrier))->assertForbidden();
    }

    public function test_telecharger_un_brouillon_exige_le_privilege(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('brouillons/scan.pdf', 'scan');
        $proprietaire = $this->avecPrivileges(['courriers.creer']);
        $brouillon = CourrierBrouillon::create(['cree_par_id' => $proprietaire->id, 'fichier_path' => 'brouillons/scan.pdf', 'nom_original' => 'scan.pdf', 'type_mime' => 'application/pdf']);

        $this->actingAs($proprietaire)->get(route('brouillons.telecharger', $brouillon))->assertForbidden();
        $this->actingAs($proprietaire)->get(route('brouillons.apercu', $brouillon))->assertOk();

        $proprietaire->privilegesDirectes()->attach(Privilege::where('cle', 'courriers.telecharger')->value('id'));
        $this->actingAs(User::find($proprietaire->id))->get(route('brouillons.telecharger', $brouillon))->assertOk();
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
