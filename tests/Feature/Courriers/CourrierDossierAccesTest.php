<?php

namespace Tests\Feature\Courriers;

use App\Models\Courrier;
use App\Models\DossierClassement;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Module 3/9 — "les trois se cumulent" (DECISIONS.md 2026-09-16) : niveau de
// confidentialité, périmètre par privilège, ET accès par dossier de
// classement doivent TOUS passer pour un courrier rangé dans un dossier.
// Un courrier NON classé n'est jamais affecté par le troisième mécanisme.
class CourrierDossierAccesTest extends TestCase
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

    private function utilisateur(string $profil, int $niveau = 5): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $profil])->id,
            'niveau_confidentialite' => $niveau,
        ]);
    }

    private function accorderVoirTout(User $user): void
    {
        Privilege::where('cle', 'courriers.voir_tout')->firstOrFail()->users()->attach($user->id);
    }

    public function test_un_courrier_non_classe_nest_jamais_restreint_par_le_gate_dossier(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service); // dossier_classement_id = null

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);

        $this->assertTrue($utilisateur->can('view', $courrier));
    }

    // Cas critique : voir_tout (qui suffirait normalement) ne suffit plus si
    // le courrier est dans un dossier auquel l'utilisateur n'a pas accès.
    public function test_voir_tout_ne_suffit_plus_pour_un_courrier_classe_sans_acces_au_dossier(): void
    {
        $service = Service::factory()->create();
        $createur = $this->utilisateur('Responsable de service');
        $dossier = DossierClassement::create(['nom' => 'Dossier privé', 'cree_par_id' => $createur->id]);
        $courrier = $this->courrier($service, ['dossier_classement_id' => $dossier->id]);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);

        $this->assertTrue($utilisateur->hasPrivilege('courriers.voir_tout'));
        $this->assertFalse($utilisateur->can('view', $courrier));
    }

    // L'accès au dossier n'est qu'UN des trois mécanismes cumulatifs — il
    // faut aussi un privilège de périmètre "courriers.*" (ici voir_tout,
    // comme pour toutes les combinaisons ci-dessous) : être créateur du
    // dossier ne dispense jamais de ce second gate.
    public function test_le_createur_du_dossier_voit_ses_courriers(): void
    {
        $service = Service::factory()->create();
        $createur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($createur);
        $dossier = DossierClassement::create(['nom' => 'Dossier privé', 'cree_par_id' => $createur->id]);
        $courrier = $this->courrier($service, ['dossier_classement_id' => $dossier->id]);

        $this->assertTrue($createur->can('view', $courrier));
    }

    public function test_le_responsable_du_dossier_voit_ses_courriers(): void
    {
        $service = Service::factory()->create();
        $createur = $this->utilisateur('Responsable de service');
        $responsable = $this->utilisateur('Collaborateur');
        $this->accorderVoirTout($responsable);
        $dossier = DossierClassement::create(['nom' => 'Dossier', 'cree_par_id' => $createur->id, 'responsable_id' => $responsable->id]);
        $courrier = $this->courrier($service, ['dossier_classement_id' => $dossier->id]);

        $this->assertTrue($responsable->can('view', $courrier));
    }

    public function test_un_utilisateur_partage_voit_le_courrier_du_dossier(): void
    {
        $service = Service::factory()->create();
        $createur = $this->utilisateur('Responsable de service');
        $dossier = DossierClassement::create(['nom' => 'Dossier', 'cree_par_id' => $createur->id]);
        $courrier = $this->courrier($service, ['dossier_classement_id' => $dossier->id]);

        $beneficiaire = $this->utilisateur('Collaborateur');
        $this->accorderVoirTout($beneficiaire);
        $dossier->utilisateursAutorises()->attach($beneficiaire->id);

        $this->assertTrue($beneficiaire->can('view', $courrier));
    }

    public function test_gerer_tout_court_circuite_le_gate_dossier(): void
    {
        $service = Service::factory()->create();
        $createur = $this->utilisateur('Responsable de service');
        $dossier = DossierClassement::create(['nom' => 'Dossier privé', 'cree_par_id' => $createur->id]);
        $courrier = $this->courrier($service, ['dossier_classement_id' => $dossier->id]);

        $admin = $this->utilisateur('Administrateur');

        $this->assertTrue($admin->can('view', $courrier));
    }

    // Périmètre au niveau requête — même règle que la Policy, vérifiée via
    // Courrier::scopeVisiblePar() plutôt qu'un filtrage en mémoire après coup.
    public function test_visible_par_exclut_les_courriers_dun_dossier_non_partage(): void
    {
        $service = Service::factory()->create();
        $createur = $this->utilisateur('Responsable de service');
        $dossier = DossierClassement::create(['nom' => 'Dossier privé', 'cree_par_id' => $createur->id]);
        $courrier = $this->courrier($service, ['dossier_classement_id' => $dossier->id]);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);

        $visibles = Courrier::query()->visiblePar($utilisateur)->pluck('id');

        $this->assertFalse($visibles->contains($courrier->id));
    }

    public function test_visible_par_inclut_les_courriers_non_classes(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service);

        // Administrateur : aucune des restrictions par profil de
        // visiblePar() (Responsable de service/Agent/Collaborateur/DGA) ne
        // s'applique — isole proprement le comportement testé ici (le
        // troisième gate, dossier) du second (périmètre par profil).
        $utilisateur = $this->utilisateur('Administrateur');

        $visibles = Courrier::query()->visiblePar($utilisateur)->pluck('id');

        $this->assertTrue($visibles->contains($courrier->id));
    }

    // Module 9 — "consultables mais ne sont plus modifiables" : archivé
    // reste ouvrable (view) mais toute ability mutante est refusée.
    public function test_un_courrier_archive_reste_consultable(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['statut' => 'archive']);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);

        $this->assertTrue($utilisateur->can('view', $courrier));
    }

    public function test_un_courrier_archive_nest_plus_modifiable(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['statut' => 'archive']);

        $utilisateur = $this->utilisateur('Administrateur');

        $this->assertFalse($utilisateur->can('update', $courrier));
        $this->assertFalse($utilisateur->can('affecter', $courrier));
        $this->assertFalse($utilisateur->can('traiter', $courrier));
        $this->assertFalse($utilisateur->can('valider', $courrier));
        $this->assertFalse($utilisateur->can('transferer', $courrier));
        $this->assertFalse($utilisateur->can('validerService', $courrier));
    }

    // Module 3/9 — classer() délègue entièrement à view() (2026-09-22,
    // "les trois" points d'entrée pour ranger un courrier dans un dossier) :
    // mêmes gates (confidentialité, accès dossier, périmètre par privilège).
    public function test_classer_suit_exactement_les_memes_regles_que_view(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->assertFalse($utilisateur->can('classer', $courrier));

        $this->accorderVoirTout($utilisateur);

        // hasPrivilege() est mémoïsé par instance (voir notes internes) — relire une instance fraîche après avoir
        // changé les privilèges, ->refresh() ne suffit pas.
        $utilisateurFrais = User::find($utilisateur->id);
        $this->assertTrue($utilisateurFrais->can('classer', $courrier));
    }

    // Contrairement à update()/affecter()/etc., classer() n'est PAS bloqué
    // par statut === 'archive' — filer un courrier archivé dans un dossier
    // reste utile (Module 9, retrouvabilité), ce n'est pas modifier son contenu.
    public function test_classer_reste_autorise_sur_un_courrier_archive(): void
    {
        $service = Service::factory()->create();
        $courrier = $this->courrier($service, ['statut' => 'archive']);

        $utilisateur = $this->utilisateur('Administrateur');

        $this->assertTrue($utilisateur->can('classer', $courrier));
    }

    public function test_classer_reste_bloque_par_un_acces_dossier_insuffisant(): void
    {
        $service = Service::factory()->create();
        $createur = $this->utilisateur('Responsable de service');
        $dossier = DossierClassement::create(['nom' => 'Dossier privé', 'cree_par_id' => $createur->id]);
        $courrier = $this->courrier($service, ['dossier_classement_id' => $dossier->id]);

        $utilisateur = $this->utilisateur('Responsable de service');
        $this->accorderVoirTout($utilisateur);

        $this->assertFalse($utilisateur->can('classer', $courrier));
    }
}
