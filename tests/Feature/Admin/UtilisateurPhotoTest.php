<?php

namespace Tests\Feature\Admin;

use App\Models\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// 2026-10-26 (retour utilisateur : « l'image profil ne fonctionne pas ») — la
// photo est servie par l'application (UtilisateurPhotoController), jamais
// par un lien direct vers le bucket privé du stockage.
class UtilisateurPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateurAvecPhoto(): User
    {
        $user = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id]);
        Storage::disk('s3')->putFileAs('avatars/'.$user->id, UploadedFile::fake()->image('photo.png', 200, 200), 'photo.png');
        $user->update(['photo_path' => 'avatars/'.$user->id.'/photo.png']);

        return $user;
    }

    public function test_lurl_de_la_photo_passe_par_lapplication(): void
    {
        Storage::fake('s3');
        $user = $this->utilisateurAvecPhoto();

        $this->assertStringStartsWith(route('utilisateurs.photo', $user), $user->photoUrl());
        $this->assertNull(User::factory()->create()->photoUrl());
    }

    public function test_un_utilisateur_connecte_voit_la_photo_dun_collegue(): void
    {
        Storage::fake('s3');
        $user = $this->utilisateurAvecPhoto();
        $this->actingAs(User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'Collaborateur'])->id]));

        $this->get($user->photoUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_un_visiteur_non_connecte_ne_voit_pas_la_photo(): void
    {
        Storage::fake('s3');
        $user = $this->utilisateurAvecPhoto();

        $this->get($user->photoUrl())->assertRedirect(route('login'));
    }

    public function test_un_utilisateur_sans_photo_renvoie_404(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('utilisateurs.photo', $user))->assertNotFound();
    }
}
