<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Profile;
use App\Models\OrganizationUnit;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-10-26 — « Mon profil » sur le modèle de la fiche utilisateur de
// l'administrateur ; seule la photo est modifiable par l'utilisateur
// (nom, prénom, email et téléphone : administrateur seulement).
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/settings/profile')->assertOk();
    }

    public function test_lutilisateur_change_et_retire_sa_photo(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create();
        $this->actingAs($user);

        // Enregistrée dès le choix du fichier (Profile::updatedPhoto()).
        $composant = Livewire::test(Profile::class)
            ->set('photo', UploadedFile::fake()->image('moi.png', 300, 300))
            ->assertHasNoErrors()
            ->assertSet('photo', null);

        $chemin = $user->refresh()->photo_path;
        $this->assertNotNull($chemin);
        Storage::disk('s3')->assertExists($chemin);

        $composant->call('retirerPhoto');

        $this->assertNull($user->refresh()->photo_path);
        Storage::disk('s3')->assertMissing($chemin);
    }

    public function test_un_fichier_qui_nest_pas_une_image_est_refuse(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('photo', UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))
            ->assertHasErrors('photo');

        $this->assertNull($user->refresh()->photo_path);
    }

    // Règle n°6 — coordonnées, entité, profil, poste et niveau sont affichés
    // mais jamais modifiables ici : le composant n'expose aucune de ces
    // valeurs, rien ne peut être forcé depuis le client.
    public function test_tout_sauf_la_photo_est_en_lecture_seule(): void
    {
        $service = Service::factory()->create();
        $entite = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'name' => 'Département Informatique', 'parent_id' => null, 'service_id' => $service->id]);
        $user = User::factory()->create([
            'name' => 'Kamga Jean Paul',
            'telephone' => '+237 699 00 00 00',
            'profil_id' => Profil::firstOrCreate(['nom' => 'Collaborateur'])->id,
            'poste' => 'Développeur',
            'niveau_confidentialite' => 2,
            'service_id' => $service->id,
        ]);
        $user->organizationUnits()->attach($entite->id, ['is_primary' => true]);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->assertSee('Géré par l&#039;administrateur', false)
            ->assertSee('Kamga')
            ->assertSee('Jean Paul')
            ->assertSee('+237 699 00 00 00')
            ->assertSee('Département Informatique')
            ->assertSee('Développeur')
            ->assertSee('Niveau 2');

        foreach (['name', 'nom', 'prenom', 'email', 'telephone', 'poste', 'niveau_confidentialite', 'profil_id', 'service_id', 'actif'] as $propriete) {
            $this->assertFalse(property_exists(Profile::class, $propriete), "« {$propriete} » ne doit pas être modifiable depuis Mon profil.");
        }
        $this->assertFalse(method_exists(Profile::class, 'updateProfileInformation'));
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        // Vers la page de connexion, comme la déconnexion (2026-11-04).
        $response
            ->assertHasNoErrors()
            ->assertRedirect('/login');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('settings.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}
