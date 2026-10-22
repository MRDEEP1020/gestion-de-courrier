<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\CourrierList;
use App\Livewire\Backend\CourriersEnregistres;
use App\Livewire\Backend\ShowCourrier;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\DelegationDga;
use App\Models\OrganizationUnit;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CourrierNotification;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class CircuitCourrierTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil, ?Service $service = null): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id,
            'service_id' => $service?->id,
        ]);
    }

    // Module "Organisation" v2 (2026-09-22) — la cascade Site/Département de
    // ShowCourrier résout un VRAI service_id via ce pont (voir
    // OrganizationUnit::service_id) ; ces tests créent un Département ponté
    // directement (spec §1 : un Département peut être la destination finale
    // sans Service en dessous), suffisant pour ce qui est testé ici.
    private function departementPonte(Service $service): OrganizationUnit
    {
        $site = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SITE]);

        return OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'parent_id' => $site->id,
            'service_id' => $service->id,
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

    // 2026-10-13, bug réel (retour utilisateur, "it is not working" après
    // avoir accordé courriers.traiter_affecte à "Responsable de service") —
    // deux conditions devaient être réunies et aucun test ne les couvrait
    // ensemble : (1) le responsable doit apparaître dans la liste des
    // collaborateurs sélectionnables pour pouvoir s'affecter le courrier à
    // lui-même (ShowCourrier::collaborateursDuService(), n'incluait avant
    // que le profil "Collaborateur") ; (2) avec le privilège accordé, il
    // peut ensuite réellement démarrer le traitement.
    public function test_un_responsable_avec_le_privilege_peut_sauto_affecter_et_traiter(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        Privilege::where('cle', 'courriers.traiter_affecte')->firstOrFail()->users()->attach($responsable->id);
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        $test = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id]);

        $this->assertTrue(
            $test->get('collaborateursDuService')->pluck('id')->contains($responsable->id),
            'Le responsable devrait apparaître dans la liste des collaborateurs sélectionnables.'
        );

        $test->set('collaborateurSelectionne', $responsable->id)
            ->call('affecter')
            ->assertHasNoErrors()
            ->call('demarrerTraitement')
            ->assertHasNoErrors();

        $this->assertSame('en_traitement', $courrier->fresh()->statut);
    }

    // Filet de sécurité (Règle n°6) — sans le privilège, même auto-affecté,
    // le responsable reste bloqué côté serveur, pas seulement caché dans la
    // vue. courriers.traiter_affecte est un défaut du profil depuis le
    // 2026-10-13 (voir PrivilegeSeeder) — retiré explicitement ici pour
    // tester vraiment le cas "sans le privilège".
    public function test_un_responsable_sans_le_privilege_ne_peut_pas_traiter_meme_sil_sest_affecte(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        Privilege::where('cle', 'courriers.traiter_affecte')->firstOrFail()->profils()->detach($responsable->profil_id);
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('collaborateurSelectionne', $responsable->id)
            ->call('affecter')
            ->assertHasNoErrors()
            ->call('demarrerTraitement')
            ->assertForbidden();

        $this->assertSame('affecte', $courrier->fresh()->statut);
    }

    // 2026-10-13, demande explicite de l'utilisateur ("isnt the another way
    // to that without selecting his name") — raccourci "Traiter moi-même"
    // (CourrierPolicy::traiterMoiMeme()) : un clic, pas de recherche de son
    // propre nom dans le menu "Affecter à".
    public function test_traiter_moi_meme_auto_affecte_et_demarre_le_traitement_en_un_clic(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        Privilege::where('cle', 'courriers.traiter_affecte')->firstOrFail()->users()->attach($responsable->id);
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        // 2026-10-13, retour utilisateur ("je ne peut pa mettre un
        // delais") — passe désormais par ouvrirTraiterMoiMeme() (ouvre une
        // modale avec son propre délai, préempli au SLA par défaut) avant
        // de confirmer, plutôt qu'une action directe sans aucun champ délai.
        $test = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('peutTraiterMoiMeme', true)
            ->call('ouvrirTraiterMoiMeme');

        $this->assertNotNull($test->get('delaiValeur'));
        $this->assertSame('jours', $test->get('delaiUnite'));

        $test->call('traiterMoiMeme')->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('en_traitement', $courrier->statut);
        $this->assertSame($responsable->id, $courrier->affectationCourante->user_id);
        $this->assertNotNull($courrier->chrono_fin_le, 'Le délai préempli à l\'ouverture de la modale devrait avoir été appliqué.');

        // 2026-10-14, retour utilisateur ("the button traite moi meme
        // should hide when we have already use it") — une fois le courrier
        // déjà auto-affecté/en traitement, le raccourci ne doit plus
        // apparaître (WorkflowService::affecter() ne transitionne que
        // depuis 'enregistre', un second clic échouerait silencieusement).
        $this->assertFalse($responsable->can('traiterMoiMeme', $courrier));
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('peutTraiterMoiMeme', false);
    }

    // 2026-10-13, retour utilisateur avec capture d'écran de l'onglet
    // "Circuit de traitement" ("it is not the[re]") — après "Traiter
    // moi-même", "Soumettre pour validation" restait absent :
    // courriers.soumettre_validation ne couvrait par défaut QUE le profil
    // "Collaborateur", jamais "Responsable de service" malgré
    // courriers.traiter_affecte déjà accordé — un responsable qui se traite
    // lui-même un courrier a besoin des DEUX privilèges "côté collaborateur"
    // pour aller jusqu'au bout du cycle, pas seulement du premier. Couvre
    // le cycle complet : démarrer → soumettre → (se) valider.
    public function test_un_responsable_peut_aller_jusquau_bout_du_cycle_sur_son_propre_courrier(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        $test = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('traiterMoiMeme')
            ->assertHasNoErrors();

        $this->assertSame('en_traitement', $courrier->fresh()->statut);

        $this->assertTrue($test->get('droits')['soumettreValidation']);

        $test->call('soumettrePourValidation')
            ->assertHasNoErrors();

        $this->assertSame('en_validation', $courrier->fresh()->statut);

        $test->call('valider')->assertHasNoErrors();

        $this->assertSame('traite', $courrier->fresh()->statut);
    }

    // 2026-10-14, retour utilisateur ("why can he validate directly") —
    // quand le traiteur ET le validateur sont la même personne (ici : un
    // responsable qui s'est auto-affecté via traiterMoiMeme()), le
    // raccourci validerDirectement() fait passer en_traitement → traite en
    // un seul appel au lieu de soumettrePourValidation() PUIS valider().
    public function test_valider_directement_fait_passer_de_en_traitement_a_traite_en_un_appel(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        $test = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('traiterMoiMeme')
            ->assertHasNoErrors();

        $this->assertSame('en_traitement', $courrier->fresh()->statut);
        $this->assertTrue($test->get('droits')['validerDirectement']);

        $test->call('validerDirectement')->assertHasNoErrors();

        $this->assertSame('traite', $courrier->fresh()->statut);
    }

    // Un collaborateur (pas responsable) qui traite SON propre courrier
    // affecté n'est jamais, lui, le validateur — validerDirectement()
    // doit rester false, le circuit à 2 étapes (soumettre puis le
    // responsable valide) doit rester inchangé pour ce cas normal.
    public function test_valider_directement_refuse_pour_un_collaborateur_qui_nest_pas_le_validateur(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service)->fresh();
        $this->actingAs($collaborateur);

        $workflow = app(WorkflowService::class);
        $workflow->affecter($courrier, $collaborateur, $responsable);
        $workflow->demarrerTraitement($courrier->fresh(), $collaborateur);

        $test = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id]);

        $this->assertFalse($test->get('droits')['validerDirectement']);
        $this->assertTrue($test->get('droits')['soumettreValidation']);

        $test->call('validerDirectement')->assertForbidden();

        $this->assertSame('en_traitement', $courrier->fresh()->statut);
    }

    // Règle n°6 — sans le privilège, l'action reste refusée côté serveur
    // (403), le courrier n'est même pas affecté (tout ou rien).
    // courriers.traiter_affecte est un défaut du profil depuis le 2026-10-13
    // (voir PrivilegeSeeder) — retiré explicitement ici pour tester
    // vraiment le cas "sans le privilège".
    public function test_traiter_moi_meme_refuse_sans_le_privilege(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        Privilege::where('cle', 'courriers.traiter_affecte')->firstOrFail()->profils()->detach($responsable->profil_id);
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('peutTraiterMoiMeme', false)
            ->call('traiterMoiMeme')
            ->assertForbidden();

        $this->assertSame('enregistre', $courrier->fresh()->statut);
        $this->assertNull($courrier->fresh()->affectationCourante);
    }

    public function test_le_responsable_affecte_un_collaborateur_de_son_service(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Pas encore affecté')
            ->set('collaborateurSelectionne', $collaborateur->id)
            ->call('affecter')
            ->assertHasNoErrors()
            ->assertSee($collaborateur->name);

        $courrier->refresh();

        $this->assertSame('affecte', $courrier->statut);
        $this->assertSame($collaborateur->id, $courrier->affectationCourante->user_id);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'affectation')->count());
    }

    // Module 1/4 — validation DGA/ADJ du service, demande explicite de
    // l'utilisateur (2026-09-08) : voir DECISIONS.md "Circuit courrier
    // entrant : validation DGA/ADJ".

    public function test_une_dga_valide_le_service_dun_courrier(): void
    {
        $dga = $this->utilisateur('DGA');
        $serviceInitial = Service::factory()->create(['code' => 'TST']);
        $serviceRetenu = Service::factory()->create(['code' => 'AUT']);
        $departement = $this->departementPonte($serviceRetenu);
        // 'en_cours_de_transfert' (2026-09-15, voir DECISIONS.md,
        // synchronisation SRS-GEC.pdf) : validerService() n'est plus
        // atteignable qu'après que la réceptionniste ait cliqué "Transférer".
        $courrier = $this->courrier($serviceInitial, ['statut' => 'en_cours_de_transfert']);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->call('validerService')
            ->assertHasNoErrors();

        $courrier->refresh();

        $this->assertSame('enregistre', $courrier->statut);
        $this->assertSame($serviceRetenu->id, $courrier->service_id);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'service_valide_dga')->count());
    }

    // 2026-09-23, clarification explicite de l'utilisateur (cas réel
    // rencontré : "Direction des Sinistres" créée en base SANS Site
    // au-dessus, faute de nom de site réel confirmé — "leave the site
    // unassigned/null for now") : un Département RACINE (parent_id null)
    // doit rester sélectionnable/utilisable pour valider un service, sans
    // qu'un Site soit choisi au préalable.
    public function test_une_dga_valide_le_service_via_un_departement_racine_sans_site(): void
    {
        $dga = $this->utilisateur('DGA');
        $serviceInitial = Service::factory()->create(['code' => 'TST']);
        $serviceRetenu = Service::factory()->create(['code' => 'AUT']);
        $departementRacine = OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'parent_id' => null,
            'service_id' => $serviceRetenu->id,
        ]);
        $courrier = $this->courrier($serviceInitial, ['statut' => 'en_cours_de_transfert']);
        $this->actingAs($dga);

        $composant = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('siteSelectionneId', null)
            ->call('validerService')
            ->assertHasErrors('uniteSelectionneeId');

        $this->assertTrue(collect($composant->get('departementsDisponibles'))->pluck('id')->contains($departementRacine->id));

        $composant
            ->set('departementSelectionneId', $departementRacine->id)
            ->call('validerService')
            ->assertHasNoErrors();

        $this->assertSame($serviceRetenu->id, $courrier->refresh()->service_id);
    }

    // 2026-09-21 — demande explicite de l'utilisateur : la DGA confirme/
    // corrige le niveau de confidentialité RÉEL en même temps que le
    // service (voir WorkflowService::validerService()).
    public function test_le_niveau_de_confidentialite_de_la_receptionniste_est_presilectionne_et_modifiable_par_la_dga(): void
    {
        // niveau_confidentialite explicite (2026-09-21, correction de
        // l'utilisateur — "the dga profile will have ... niveau elevated") :
        // un compte DGA correctement configuré a un niveau élevé, ce n'est
        // plus une exception cachée du code (voir User::niveauConfidentialiteEffectif()).
        $dga = User::factory()->create(['profil_id' => Profil::firstOrCreate(['nom' => 'DGA'])->id, 'niveau_confidentialite' => 3]);
        $service = Service::factory()->create(['code' => 'TST']);
        $departement = $this->departementPonte($service);
        $courrier = $this->courrier($service, ['statut' => 'en_cours_de_transfert', 'confidentialite' => 2]);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('confidentialiteSelectionnee', 2)
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->set('confidentialiteSelectionnee', 3)
            ->call('validerService')
            ->assertHasNoErrors();

        $this->assertSame(3, $courrier->refresh()->confidentialite);
    }

    public function test_le_service_propose_est_preselectionne_pour_la_validation_dga(): void
    {
        // Même principe que le_moins_charge_est_preselectionne... ci-dessous
        // pour l'affectation : présélection RÉELLE, pas seulement la
        // prétention d'un commentaire. Module "Organisation" v2 — la
        // présélection reconstruit la cascade Site/Département depuis le
        // nœud ponté au service proposé (voir
        // ShowCourrier::preselectionnerCascadeDepuisService()).
        $dga = $this->utilisateur('DGA');
        $serviceInitial = Service::factory()->create(['code' => 'TST']);
        $serviceHumainePropose = Service::factory()->create(['code' => 'AUT']);
        $departement = $this->departementPonte($serviceHumainePropose);
        $courrier = $this->courrier($serviceInitial, [
            'statut' => 'en_cours_de_transfert',
            'service_propose_id' => $serviceHumainePropose->id,
        ]);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('siteSelectionneId', $departement->parent_id)
            ->assertSet('departementSelectionneId', $departement->id)
            ->call('validerService')
            ->assertHasNoErrors();

        $this->assertSame($serviceHumainePropose->id, $courrier->refresh()->service_id);
    }

    // Bug réel constaté le 2026-09-23 : après une validation RÉUSSIE, la DGA
    // perdait le droit de consulter le courrier et le re-rendu affichait une
    // page 403. Depuis le 2026-09-24 ("dga doesn't see all the courier he
    // transfered"), elle GARDE la consultation (privilège voir_transferes) :
    // elle reste sur la fiche, sans plus aucune action possible dessus.
    public function test_apres_validation_la_dga_garde_la_consultation_sans_pouvoir_agir(): void
    {
        $dga = $this->utilisateur('DGA');
        $service = Service::factory()->create(['code' => 'TST']);
        $departement = $this->departementPonte($service);
        $courrier = $this->courrier($service, ['statut' => 'en_cours_de_transfert', 'service_id' => null]);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->call('validerService')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('peutValiderService', false)
            ->assertSet('peutAffecter', false);

        $this->assertSame($service->id, $courrier->refresh()->service_id);
        $this->get(route('courriers.show', $courrier->id))->assertOk();
    }

    // 2026-09-24 — les courriers transférés restent dans les listes de la
    // DGA (recherche, courriers enregistrés) mais PAS dans sa file "à
    // traiter" : ils ne l'attendent plus.
    public function test_la_dga_retrouve_ses_courriers_transferes_hors_de_sa_file_a_traiter(): void
    {
        $dga = $this->utilisateur('DGA');
        $autreDga = $this->utilisateur('DGA');
        $service = Service::factory()->create(['code' => 'TST']);
        $transfere = $this->courrier($service, ['statut' => 'affecte', 'destinataire_transfert_id' => $dga->id, 'numero_reference' => 'GEC-2026-000201']);
        $ancienSansDestinataire = $this->courrier($service, ['statut' => 'enregistre', 'numero_reference' => 'GEC-2026-000202']);
        CourrierHistorique::create(['courrier_id' => $ancienSansDestinataire->id, 'auteur_id' => $dga->id, 'action' => 'service_valide_dga']);
        $dUneAutreDga = $this->courrier($service, ['statut' => 'affecte', 'destinataire_transfert_id' => $autreDga->id, 'numero_reference' => 'GEC-2026-000203']);

        $this->assertEqualsCanonicalizing(
            [$transfere->id, $ancienSansDestinataire->id],
            Courrier::query()->visiblePar($dga)->pluck('id')->all(),
        );
        $this->assertSame([], Courrier::query()->visiblePar($dga, false)->pluck('id')->all());

        $this->actingAs($dga);
        $this->get(route('courriers.show', $transfere->id))->assertOk();
        $this->get(route('courriers.show', $dUneAutreDga->id))->assertForbidden();
        Livewire::withQueryParams(['statut' => 'en_cours_de_transfert'])->test(CourrierList::class)->assertDontSee('GEC-2026-000201');
        // withQueryParams() reste actif pour les Livewire::test() suivants :
        // on le remet explicitement à vide pour la liste complète.
        Livewire::withQueryParams([])->test(CourrierList::class)->assertSee('GEC-2026-000201')->assertSee('GEC-2026-000202');
    }

    // 2026-09-24 ("after he transfer he has to see the courier update /
    // follow without working on it") : onglet "Suivi" de Courriers
    // enregistrés — le courrier transféré reste suivi à chaque étape, avec
    // son statut et la personne affectée ; l'onglet "Transféré" seul le
    // perdait dès l'affectation.
    public function test_la_dga_suit_ses_courriers_transferes_a_chaque_etape(): void
    {
        $dga = $this->utilisateur('DGA');
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['code' => 'TST', 'responsable_id' => $responsable->id]);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'en_traitement', 'destinataire_transfert_id' => $dga->id, 'numero_reference' => 'GEC-2026-000301']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id, 'affecte_par_id' => $responsable->id]);
        $this->actingAs($dga);

        Livewire::test(CourriersEnregistres::class, ['onglet' => 'enregistre'])
            ->assertDontSee('GEC-2026-000301');

        Livewire::withQueryParams(['onglet' => 'suivi'])
            ->test(CourriersEnregistres::class)
            ->assertSee('GEC-2026-000301')
            ->assertSee(Courrier::libelleStatut('en_traitement'))
            ->assertSee($collaborateur->name);

        // Lecture seule : aucune action de circuit.
        $this->assertFalse($dga->can('traiter', $courrier->fresh()));
        $this->assertFalse($dga->can('valider', $courrier->fresh()));
        $this->assertFalse($dga->can('affecter', $courrier->fresh()));
    }

    // Bug réel constaté le 2026-09-23 : courrier validé vers "Sinistre Santé"
    // (unité d'un AUTRE département, restée en mémoire) alors que l'écran
    // affichait "Departement Informatique".
    public function test_une_unite_perimee_dun_autre_departement_est_refusee(): void
    {
        $dga = $this->utilisateur('DGA');
        $serviceSinistres = Service::factory()->create(['code' => 'SIN']);
        $serviceSante = Service::factory()->create(['code' => 'SAN']);
        $serviceInfo = Service::factory()->create(['code' => 'INF']);
        $sinistres = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => null, 'service_id' => $serviceSinistres->id]);
        $sante = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SERVICE, 'parent_id' => $sinistres->id, 'service_id' => $serviceSante->id]);
        $info = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => null, 'service_id' => $serviceInfo->id]);
        $courrier = $this->courrier($serviceInfo, ['statut' => 'en_cours_de_transfert', 'service_id' => null]);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('departementSelectionneId', $info->id)
            ->set('uniteSelectionneeId', $sante->id)
            ->call('validerService')
            ->assertHasErrors('uniteSelectionneeId')
            ->assertSet('uniteSelectionneeId', null);

        $this->assertSame('en_cours_de_transfert', $courrier->refresh()->statut);
        $this->assertNull($courrier->service_id);
    }

    public function test_sans_departement_choisi_le_message_le_dit_clairement(): void
    {
        $dga = $this->utilisateur('DGA');
        $service = Service::factory()->create(['code' => 'TST']);
        $this->departementPonte($service);
        $courrier = $this->courrier($service, ['statut' => 'en_cours_de_transfert', 'service_id' => null]);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('validerService')
            ->assertHasErrors('uniteSelectionneeId')
            ->assertSee(__('Choisissez un département (et, si besoin, un service).'));
    }

    public function test_un_responsable_de_service_ne_peut_pas_valider_le_service(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_cours_de_transfert']);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('validerService')
            ->assertForbidden();

        $this->assertSame('en_cours_de_transfert', $courrier->refresh()->statut);
    }

    // 2026-10-06 (entretien terrain réceptionniste, voir DECISIONS.md
    // "Délégation DGA/ADJ absents") : absence simultanée DGA + Adjoint DGA
    // → un délégataire (ex. RH) sans le privilège courriers.dga_valider_service
    // peut valider le service à la place du DGA qui lui a délégué cette
    // capacité — jamais au-delà (voir les 2 tests de refus ci-dessous).
    public function test_un_delegataire_actif_valide_le_service_a_la_place_du_dga_absent(): void
    {
        $dga = $this->utilisateur('DGA');
        $rh = $this->utilisateur('Collaborateur');
        $serviceInitial = Service::factory()->create(['code' => 'TST']);
        $serviceRetenu = Service::factory()->create(['code' => 'AUT']);
        $departement = $this->departementPonte($serviceRetenu);
        $courrier = $this->courrier($serviceInitial, [
            'statut' => 'en_cours_de_transfert',
            'destinataire_transfert_id' => $dga->id,
        ]);

        DelegationDga::create([
            'delegant_id' => $dga->id,
            'delegataire_id' => $rh->id,
            'debut_le' => now()->subHour(),
            'actif' => true,
            'active_par_id' => $dga->id,
        ]);

        $this->actingAs($rh);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->call('validerService')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('enregistre', $courrier->statut);
        $this->assertSame($serviceRetenu->id, $courrier->service_id);
    }

    public function test_un_utilisateur_sans_delegation_active_ne_peut_pas_valider_a_la_place_du_dga(): void
    {
        $dga = $this->utilisateur('DGA');
        $rh = $this->utilisateur('Collaborateur');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, [
            'statut' => 'en_cours_de_transfert',
            'destinataire_transfert_id' => $dga->id,
        ]);
        $this->actingAs($rh);

        // Sans délégation, RH ne peut même pas voir la fiche (statut
        // en_cours_de_transfert adressé à un autre DGA) — mount() refuse
        // directement, avant qu'il y ait quoi que ce soit à appeler.
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertForbidden();

        $this->assertSame('en_cours_de_transfert', $courrier->refresh()->statut);
    }

    // Nominatif : une délégation ne couvre QUE le DGA qui l'a accordée, pas
    // n'importe quel courrier adressé à un autre DGA.
    public function test_une_delegation_ne_couvre_pas_un_courrier_adresse_a_un_autre_dga(): void
    {
        $dgaDelegant = $this->utilisateur('DGA');
        $autreDga = $this->utilisateur('DGA');
        $rh = $this->utilisateur('Collaborateur');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, [
            'statut' => 'en_cours_de_transfert',
            'destinataire_transfert_id' => $autreDga->id,
        ]);

        DelegationDga::create([
            'delegant_id' => $dgaDelegant->id,
            'delegataire_id' => $rh->id,
            'debut_le' => now()->subHour(),
            'actif' => true,
            'active_par_id' => $dgaDelegant->id,
        ]);

        $this->actingAs($rh);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertForbidden();
    }

    // Une délégation DÉSACTIVÉE (DGA de retour) ne doit plus donner aucun accès.
    public function test_une_delegation_desactivee_ne_donne_plus_aucun_acces(): void
    {
        $dga = $this->utilisateur('DGA');
        $rh = $this->utilisateur('Collaborateur');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, [
            'statut' => 'en_cours_de_transfert',
            'destinataire_transfert_id' => $dga->id,
        ]);

        DelegationDga::create([
            'delegant_id' => $dga->id,
            'delegataire_id' => $rh->id,
            'debut_le' => now()->subDay(),
            'actif' => false,
            'active_par_id' => $dga->id,
            'desactive_par_id' => $dga->id,
            'desactive_le' => now()->subHour(),
        ]);

        $this->actingAs($rh);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertForbidden();
    }

    // Module 1/4 — la réceptionniste transfère explicitement le courrier au
    // DGA/ADJ DGA (2026-09-15, voir DECISIONS.md, synchronisation SRS-GEC.pdf).

    public function test_lagent_createur_peut_transferer_son_courrier_au_dga(): void
    {
        $agent = $this->utilisateur('Agent');
        $dga = $this->utilisateur('DGA');
        $agent->destinatairesTransfert()->attach($dga->id);
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('destinataireTransfertChoisi', $dga->id)
            ->call('transferer')
            ->assertHasNoErrors();

        $courrier->refresh();

        $this->assertSame('en_cours_de_transfert', $courrier->statut);
        $this->assertSame($dga->id, $courrier->destinataire_transfert_id);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'transfert')->count());
    }

    // Module 1/4 — mise à jour (2026-09-15, voir DECISIONS.md "Destinataires
    // de transfert") : un destinataire qui n'est pas dans SA liste autorisée
    // est refusé, même s'il existe bel et bien en base (Règle n°6 — jamais
    // fait confiance à un ID posté).
    public function test_un_destinataire_non_autorise_est_refuse(): void
    {
        $agent = $this->utilisateur('Agent');
        $dga = $this->utilisateur('DGA');
        // Pas de attach() ici : $dga n'est PAS dans la liste autorisée de $agent.
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('destinataireTransfertChoisi', $dga->id)
            ->call('transferer')
            ->assertHasErrors('destinataireTransfertChoisi');

        $this->assertSame('en_attente_de_transfert', $courrier->refresh()->statut);
    }

    public function test_un_autre_agent_ne_peut_pas_transferer_le_courrier_dun_collegue(): void
    {
        $createur = $this->utilisateur('Agent');
        $autreAgent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_attente_de_transfert', 'service_id' => null]);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $createur->id, 'action' => 'creation']);
        $this->actingAs($autreAgent);

        // Bloqué dès mount() (view : ni créateur, ni voir_tout/voir_dga
        // applicable) — pas seulement au clic sur "Transférer" : cet autre
        // agent n'a même pas accès à la fiche.
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->assertForbidden();

        $this->assertSame('en_attente_de_transfert', $courrier->refresh()->statut);
    }

    public function test_le_moins_charge_est_preselectionne_pour_laffectation_sans_rien_choisir(): void
    {
        // Bug réel constaté par l'utilisateur le 2026-09-04 : le commentaire
        // de collaborateursDuService() prétend "le premier de la liste est
        // présélectionné dans la vue", mais rien ne le faisait réellement —
        // cliquer "Affecter" sans toucher au menu déroulant échouait
        // toujours avec "Choisissez un collaborateur du service.". Les
        // autres tests de ce fichier contournaient le bug en fixant
        // collaborateurSelectionne à la main avant d'appeler affecter() —
        // celui-ci reproduit le vrai scénario, sans y toucher.
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $moinsCharge = $this->utilisateur('Collaborateur', $service);
        $plusCharge = $this->utilisateur('Collaborateur', $service);
        // Deux courriers déjà en cours pour $plusCharge, aucun pour $moinsCharge.
        foreach (range(1, 2) as $_) {
            $enCours = $this->courrier($service, ['statut' => 'en_traitement']);
            Affectation::create(['courrier_id' => $enCours->id, 'user_id' => $plusCharge->id]);
        }
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('collaborateurSelectionne', $moinsCharge->id)
            ->call('affecter')
            ->assertHasNoErrors()
            ->assertSee($moinsCharge->name);

        $this->assertSame($moinsCharge->id, $courrier->refresh()->affectationCourante->user_id);
    }

    public function test_le_collaborateur_affecte_demarre_le_traitement_et_soumet_pour_validation(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'affecte']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id, 'affecte_par_id' => $responsable->id]);
        $this->actingAs($collaborateur);

        $composant = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('demarrerTraitement')
            ->assertHasNoErrors();

        $this->assertSame('en_traitement', $courrier->refresh()->statut);

        $composant->set('commentaireCirculation', 'Réponse envoyée par courrier.')
            ->call('soumettrePourValidation')
            ->assertHasNoErrors();

        $this->assertSame('en_validation', $courrier->refresh()->statut);
    }

    public function test_le_responsable_valide_le_courrier_soumis(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'en_validation']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id]);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('valider')
            ->assertHasNoErrors();

        $this->assertSame('traite', $courrier->refresh()->statut);
    }

    // 2026-10-09 — extension du circuit générique (Signature DG / Trésorerie,
    // entretien terrain Sinistres, voir DECISIONS.md) : opt-in par privilège,
    // jamais un circuit différent par type/département dans le code.
    public function test_un_responsable_sans_le_privilege_ne_peut_pas_envoyer_en_signature_dg(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_validation']);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertDontSee('Envoyer en signature DG')
            ->call('envoyerSignatureDg')
            ->assertForbidden();

        $this->assertSame('en_validation', $courrier->refresh()->statut);
    }

    public function test_un_responsable_avec_le_privilege_envoie_en_signature_dg(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $responsable->privilegesDirectes()->attach(Privilege::where('cle', 'courriers.envoyer_signature_dg')->value('id'));
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_validation']);
        $this->actingAs(User::find($responsable->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Envoyer en signature DG')
            ->call('envoyerSignatureDg')
            ->assertHasNoErrors();

        $this->assertSame('en_attente_signature_dg', $courrier->refresh()->statut);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'envoye_signature_dg')->count());
    }

    public function test_la_dga_signe_et_cloture_directement(): void
    {
        $dga = $this->utilisateur('DGA');
        $service = Service::factory()->create(['code' => 'TST']);
        // destinataire_transfert_id : une fois le service validé, la DGA
        // garde la consultation du courrier (voir CourrierPolicy::view(),
        // Courrier::estTransferePar()) — un courrier créé artificiellement
        // en 'en_attente_signature_dg' sans être jamais passé par le VRAI
        // transfert/validation n'est sinon pas consultable par cette DGA
        // (403 à l'ouverture de la fiche, piège rencontré en construisant
        // ce test : Livewire::test()->instance() retombait de façon
        // trompeuse sur un AUTRE composant imbriqué de la page — NotificationBell —
        // au lieu de ShowCourrier, le vrai symptôme étant ce 403 au montage).
        $courrier = $this->courrier($service, ['statut' => 'en_attente_signature_dg', 'destinataire_transfert_id' => $dga->id]);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('signerEtCloturer')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('traite', $courrier->statut);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'signe_dg_cloture')->count());
    }

    public function test_la_dga_signe_et_transmet_a_la_tresorerie_qui_cloture(): void
    {
        $dga = $this->utilisateur('DGA');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_attente_signature_dg', 'destinataire_transfert_id' => $dga->id]);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('signerEtEnvoyerTresorerie')
            ->assertHasNoErrors();

        $this->assertSame('en_attente_tresorerie', $courrier->refresh()->statut);

        // courriers.voir_tout : aucun privilège de périmètre dédié n'existe
        // encore pour un rôle "trésorerie" (hors scope de cette demande,
        // voir réponse utilisateur) — juste de quoi ouvrir la fiche pour
        // isoler le test de l'ability courriers.traiter_tresorerie elle-même.
        $tresorier = User::factory()->create(['profil_id' => null]);
        $tresorier->privilegesDirectes()->attach(Privilege::whereIn('cle', ['courriers.traiter_tresorerie', 'courriers.voir_tout'])->pluck('id'));
        $this->actingAs(User::find($tresorier->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('traiterParTresorerie')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('traite', $courrier->statut);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'signe_dg_tresorerie')->count());
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'traite_tresorerie')->count());
    }

    public function test_un_utilisateur_sans_le_privilege_dga_signer_ne_peut_pas_signer(): void
    {
        $agent = $this->utilisateur('Agent');
        $agent->privilegesDirectes()->attach(Privilege::where('cle', 'courriers.voir_tout')->value('id'));
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'en_attente_signature_dg']);
        $this->actingAs(User::find($agent->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('signerEtCloturer')
            ->assertForbidden();

        $this->assertSame('en_attente_signature_dg', $courrier->refresh()->statut);
    }

    public function test_le_responsable_renvoie_pour_correction_avec_motif_obligatoire(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'en_validation']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id]);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('renvoyerPourCorrection')
            ->assertHasErrors(['motifRenvoi']);

        $this->assertSame('en_validation', $courrier->refresh()->statut, 'sans motif, aucune transition ne doit avoir lieu');

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('motifRenvoi', 'Il manque la pièce justificative.')
            ->call('renvoyerPourCorrection')
            ->assertHasNoErrors();

        $this->assertSame('en_traitement', $courrier->refresh()->statut);
    }

    public function test_le_motif_du_renvoi_est_visible_en_haut_de_la_fiche(): void
    {
        // Demande explicite de l'utilisateur (2026-09-08) : le collaborateur
        // doit voir tout de suite pourquoi son courrier revient, pas
        // seulement le retrouver dans l'historique en bas de page.
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'en_validation']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id]);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('motifRenvoi', 'Il manque la pièce justificative.')
            ->call('renvoyerPourCorrection');

        $this->actingAs($collaborateur);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee('Renvoyé pour correction')
            ->assertSee('Il manque la pièce justificative.')
            ->assertSee($responsable->name);
    }

    public function test_le_motif_du_renvoi_disparait_une_fois_resoumis(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'en_validation']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id]);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('motifRenvoi', 'Il manque la pièce justificative.')
            ->call('renvoyerPourCorrection');

        $this->actingAs($collaborateur);

        // "Renvoyé pour correction" seul ne suffit plus comme needle
        // (2026-10-08, refonte de l'onglet Historique en frise verticale,
        // voir le journal de développement) : ce libellé devient aussi l'intitulé
        // PERMANENT de l'entrée d'historique correspondante (Règle n°5 —
        // jamais supprimée), présente dans le DOM même repliée sous l'onglet
        // "Historique" caché en Alpine (assertDontSee lit le HTML brut, pas
        // la visibilité CSS). Seule l'ENCART (callout) disparaît réellement
        // une fois resoumis — "Motif indiqué par :nom :" n'existe que dans
        // cet encart, jamais dans la frise.
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('soumettrePourValidation')
            ->assertDontSee('Motif indiqué par');
    }

    public function test_reaffectation_avec_motif_obligatoire(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $premier = $this->utilisateur('Collaborateur', $service);
        $second = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'en_traitement']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $premier->id]);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('collaborateurSelectionne', $second->id)
            ->call('reaffecter')
            ->assertHasErrors(['motifReaffectation']);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('collaborateurSelectionne', $second->id)
            ->set('motifReaffectation', 'Le premier collaborateur est en congé.')
            ->call('reaffecter')
            ->assertHasNoErrors();

        $this->assertSame($second->id, $courrier->refresh()->affectationCourante->user_id);
        $this->assertSame(2, Affectation::where('courrier_id', $courrier->id)->count());
    }

    public function test_mise_en_attente_puis_reprise(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'en_traitement']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id]);
        $this->actingAs($collaborateur);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('motifAttente', 'En attente d\'une pièce complémentaire.')
            ->call('mettreEnAttente')
            ->assertHasNoErrors();

        $this->assertSame('en_attente_information', $courrier->refresh()->statut);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('reprendre')
            ->assertHasNoErrors();

        $this->assertSame('en_traitement', $courrier->refresh()->statut);
    }

    public function test_le_rejet_exige_un_motif(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'enregistre']);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('rejeter')
            ->assertHasErrors(['motifRejet']);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('motifRejet', 'Courrier hors périmètre du service.')
            ->call('rejeter')
            ->assertHasNoErrors();

        $this->assertSame('rejete', $courrier->refresh()->statut);
    }

    public function test_un_agent_createur_ne_peut_effectuer_aucune_action_de_circuit(): void
    {
        $agent = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('collaborateurSelectionne', 999)
            ->call('affecter')
            ->assertForbidden();

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('demarrerTraitement')
            ->assertForbidden();

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('valider')
            ->assertForbidden();
    }

    // ===== 2026-10-09 — notifications du circuit (audit "list me all") =====
    // Échantillon représentatif plutôt que les 16 actions une par une :
    // chaque mécanisme de résolution de destinataire (paramètre direct,
    // service->responsable, affectationCourante->collaborateur, ancien VS
    // nouveau sur une réaffectation, privilège, auteur d'une étape
    // antérieure via l'historique) est couvert au moins une fois.

    public function test_affecter_notifie_le_collaborateur(): void
    {
        Notification::fake();
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('collaborateurSelectionne', $collaborateur->id)
            ->call('affecter')
            ->assertHasNoErrors();

        // 2026-10-12, demande explicite de l'utilisateur ("make each
        // notification to show who send it") — envoyePar doit être
        // l'auteur réel de l'action, pas le destinataire.
        Notification::assertSentTo($collaborateur, CourrierNotification::class, fn ($n) => $n->action === 'affectation' && $n->envoyePar?->id === $responsable->id);
        Notification::assertNotSentTo($responsable, CourrierNotification::class);
    }

    public function test_valider_service_notifie_le_responsable_du_service_retenu(): void
    {
        Notification::fake();
        $dga = $this->utilisateur('DGA');
        $serviceInitial = Service::factory()->create(['code' => 'TST']);
        $responsableRetenu = $this->utilisateur('Responsable de service');
        $serviceRetenu = Service::factory()->create(['code' => 'AUT', 'responsable_id' => $responsableRetenu->id]);
        $departement = $this->departementPonte($serviceRetenu);
        $courrier = $this->courrier($serviceInitial, ['statut' => 'en_cours_de_transfert']);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->call('validerService')
            ->assertHasNoErrors();

        Notification::assertSentTo($responsableRetenu, CourrierNotification::class, fn ($n) => $n->action === 'service_valide_dga');
    }

    public function test_reaffecter_notifie_lancien_et_le_nouveau_collaborateur(): void
    {
        Notification::fake();
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $ancien = $this->utilisateur('Collaborateur', $service);
        $nouveau = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'affecte']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $ancien->id]);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('collaborateurSelectionne', $nouveau->id)
            ->set('motifReaffectation', 'Congés')
            ->call('reaffecter')
            ->assertHasNoErrors();

        Notification::assertSentTo($nouveau, CourrierNotification::class, fn ($n) => $n->action === 'reaffectation_nouveau');
        Notification::assertSentTo($ancien, CourrierNotification::class, fn ($n) => $n->action === 'reaffectation_ancien');
    }

    public function test_soumettre_pour_validation_notifie_le_responsable(): void
    {
        Notification::fake();
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'en_traitement']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id]);
        $this->actingAs($collaborateur);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('soumettrePourValidation')
            ->assertHasNoErrors();

        Notification::assertSentTo($responsable, CourrierNotification::class, fn ($n) => $n->action === 'soumis_validation');
    }

    public function test_rejeter_notifie_le_collaborateur_affecte(): void
    {
        Notification::fake();
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service, ['statut' => 'en_validation']);
        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $collaborateur->id]);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('motifRejet', 'Hors périmètre')
            ->call('rejeter')
            ->assertHasNoErrors();

        Notification::assertSentTo($collaborateur, CourrierNotification::class, fn ($n) => $n->action === 'rejet');
    }

    public function test_envoyer_signature_dg_notifie_les_utilisateurs_avec_le_privilege_dga_signer(): void
    {
        Notification::fake();
        $responsable = $this->utilisateur('Responsable de service');
        $responsable->privilegesDirectes()->attach(Privilege::where('cle', 'courriers.envoyer_signature_dg')->value('id'));
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $dga = $this->utilisateur('DGA');
        $courrier = $this->courrier($service, ['statut' => 'en_validation']);
        $this->actingAs(User::find($responsable->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('envoyerSignatureDg')
            ->assertHasNoErrors();

        Notification::assertSentTo($dga, CourrierNotification::class, fn ($n) => $n->action === 'envoye_signature_dg');
    }

    public function test_signer_et_cloturer_notifie_le_responsable_qui_avait_envoye_en_signature(): void
    {
        Notification::fake();
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $dga = $this->utilisateur('DGA');
        $courrier = $this->courrier($service, ['statut' => 'en_attente_signature_dg', 'destinataire_transfert_id' => $dga->id]);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $responsable->id, 'action' => 'envoye_signature_dg']);
        $this->actingAs($dga);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('signerEtCloturer')
            ->assertHasNoErrors();

        Notification::assertSentTo($responsable, CourrierNotification::class, fn ($n) => $n->action === 'signe_dg_cloture');
    }

    public function test_emettre_et_rendre_une_decharge_notifient_lemprunteur_et_lemetteur(): void
    {
        Notification::fake();
        $admin = $this->utilisateur('Administrateur');
        $emprunteur = $this->utilisateur('Agent');
        $service = Service::factory()->create(['code' => 'TST']);
        $courrier = $this->courrier($service, ['statut' => 'archive']);
        $this->actingAs($admin);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('ouvrirDecharge')
            ->set('dechargeEmprunteurId', $emprunteur->id)
            ->call('emettreDecharge')
            ->assertHasNoErrors();

        Notification::assertSentTo($emprunteur, CourrierNotification::class, fn ($n) => $n->action === 'decharge_emise');

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('marquerDechargeRendue')
            ->assertHasNoErrors();

        Notification::assertSentTo($admin, CourrierNotification::class, fn ($n) => $n->action === 'decharge_rendue');
    }
}
