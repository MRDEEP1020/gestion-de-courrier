<?php

namespace Tests\Feature;

use App\Models\Courrier;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 2026-09-22, demande explicite de l'utilisateur ("rather than showing that
// page we should show him like what happening and error or something") —
// la page 403 brute de Laravel (aucune navigation, aucune explication) est
// remplacée par resources/views/errors/403.blade.php, habillée comme le
// reste de l'app (sidebar/en-tête réels) avec un message compréhensible et
// un bouton de retour, plutôt que de laisser l'utilisateur bloqué sur une
// page vide sans savoir pourquoi ni comment repartir.
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

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

    // Cas réel diagnostiqué avec l'utilisateur : un courrier classé dans un
    // dossier auquel le DGA n'a pas accès (voir CourrierPolicy::
    // accesDossierSuffisant()) — même mécanisme de refus que la
    // confidentialité/le périmètre, la page affichée doit être la même dans
    // tous les cas (générique côté cause exacte, jamais fabriquée).
    public function test_un_refus_dacces_affiche_la_page_403_personnalisee_pas_la_page_brute_de_laravel(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['confidentialite' => 2]);

        $utilisateur = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Responsable de service'])->id,
            'niveau_confidentialite' => 1,
        ]);
        Privilege::where('cle', 'courriers.voir_tout')->firstOrFail()->users()->attach($utilisateur->id);

        $reponse = $this->actingAs($utilisateur)->get(route('courriers.show', $courrier->id));

        $reponse->assertForbidden();
        $reponse->assertSee('Vous n\'avez pas accès à cette page');
        $reponse->assertSee('Retour au tableau de bord');
        // La sidebar réelle de l'app doit être présente (habillage complet,
        // pas une page isolée sans navigation) — un lien vers le tableau de
        // bord existe forcément dans la sidebar pour tout utilisateur connecté.
        $reponse->assertSee('Tableau de bord');
    }
}
