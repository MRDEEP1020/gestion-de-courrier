<?php

namespace Tests\Feature\Courriers;

use App\Models\Courrier;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Confidentialité numérique hiérarchique" (2026-09-21, voir DECISIONS.md) —
// le niveau MAXIMUM de l'utilisateur (User::niveau_confidentialite, 1-5) est
// comparé numériquement au niveau du courrier (Courrier::confidentialite,
// entier 1-5), CUMULATIF avec le système de privilèges existant
// (CourrierPolicy::view()) — jamais un remplacement.
class CourrierConfidentialiteTest extends TestCase
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

    // Le cas critique : un privilège qui accorderait normalement l'accès
    // (voir_tout) ne suffit plus si le niveau de confidentialité de
    // l'utilisateur est insuffisant — "se cumule avec, pas remplace".
    public function test_un_privilege_voir_tout_ne_suffit_plus_si_le_niveau_est_insuffisant(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['confidentialite' => 3]);

        $utilisateur = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Responsable de service'])->id,
            'niveau_confidentialite' => 1,
        ]);
        Privilege::where('cle', 'courriers.voir_tout')->firstOrFail()->users()->attach($utilisateur->id);

        $this->assertTrue($utilisateur->hasPrivilege('courriers.voir_tout'));
        $this->assertFalse($utilisateur->can('view', $courrier));
    }

    public function test_un_niveau_de_confidentialite_suffisant_laisse_passer(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['confidentialite' => 3]);

        $utilisateur = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Responsable de service'])->id,
            'niveau_confidentialite' => 3,
        ]);
        Privilege::where('cle', 'courriers.voir_tout')->firstOrFail()->users()->attach($utilisateur->id);

        $this->assertTrue($utilisateur->can('view', $courrier));
    }

    // Garde-fou anti-verrouillage (voir User::niveauConfidentialiteEffectif())
    // — un Administrateur reste illimité même avec un niveau stocké bas,
    // cohérent avec "admin should have all the privileges" (PrivilegeSeeder).
    public function test_un_administrateur_voit_tout_meme_avec_un_niveau_stocke_normal(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['confidentialite' => 3]);

        $admin = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Administrateur'])->id,
            'niveau_confidentialite' => 1,
        ]);

        $this->assertTrue($admin->can('view', $courrier));
    }

    // 2026-09-21 — correction explicite de l'utilisateur : contrairement à
    // Administrateur (garde-fou anti-verrouillage volontaire), la DGA n'a
    // PAS de bypass caché dans le code — "the dga profile will have ...
    // niveau elevated" : c'est une donnée réelle (niveau_confidentialite)
    // que l'administrateur configure, pas une exception basée sur le nom
    // du profil. Une DGA jamais configurée reste donc bloquée, exactement
    // comme n'importe quel autre profil.
    public function test_une_dga_non_configuree_reste_bloquee_comme_nimporte_qui(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['statut' => 'en_cours_de_transfert', 'confidentialite' => 3]);

        $dga = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id,
            'niveau_confidentialite' => 1,
        ]);

        $this->assertFalse($dga->can('view', $courrier));
    }

    // Une DGA correctement CONFIGURÉE (niveau réellement élevé en base, pas
    // un bypass caché) peut consulter n'importe quel courrier — nécessaire
    // pour qu'elle puisse ouvrir un courrier et en CONFIRMER/CORRIGER le
    // niveau (WorkflowService::validerService()).
    public function test_une_dga_correctement_configuree_voit_tout(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['statut' => 'en_cours_de_transfert', 'confidentialite' => 3]);

        $dga = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id,
            'niveau_confidentialite' => 3,
        ]);

        $this->assertTrue($dga->can('view', $courrier));
    }

    // Le garde-fou couvre TOUTE ability qui reçoit un $courrier précis, pas
    // seulement view() — un utilisateur ne doit pas pouvoir MODIFIER un
    // courrier qu'il n'a de toute façon pas le droit de voir (EditForm
    // autorise directement 'update', jamais 'view' en premier).
    public function test_modifier_tout_ne_suffit_pas_non_plus_si_le_niveau_est_insuffisant(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['confidentialite' => 2]);

        $utilisateur = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Agent'])->id,
            'niveau_confidentialite' => 1,
        ]);
        Privilege::where('cle', 'courriers.modifier_tout')->firstOrFail()->users()->attach($utilisateur->id);

        $this->assertFalse($utilisateur->can('update', $courrier));
    }

    // Doit bloquer même un accès par lien direct, pas seulement filtrer les
    // listes (voir DECISIONS.md) — vérifié ici via ShowCourrier plutôt que
    // le seul appel Policy, pour prouver que la page réelle refuse aussi.
    public function test_laccess_direct_a_la_fiche_est_bloque_pas_seulement_les_listes(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['confidentialite' => 2]);

        $utilisateur = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Responsable de service'])->id,
            'niveau_confidentialite' => 1,
        ]);
        Privilege::where('cle', 'courriers.voir_tout')->firstOrFail()->users()->attach($utilisateur->id);

        $this->actingAs($utilisateur)
            ->get(route('courriers.show', $courrier->id))
            ->assertForbidden();
    }
}
