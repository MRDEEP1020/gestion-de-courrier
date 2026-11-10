<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\CourrierList;
use App\Livewire\Backend\CourriersEnregistres;
use App\Livewire\Backend\ShowCourrier;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CourrierNotification;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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
    // 2026-10-29 (« remove responsable from that list of collaborateur ») —
    // le point (1) passe désormais par « Me l'affecter et commencer » (voir
    // le test suivant) : la liste ne montre plus que les collaborateurs, et
    // l'identifiant du responsable posté à la main est refusé (Règle n°6).
    public function test_le_responsable_nest_plus_dans_la_liste_des_collaborateurs(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        Privilege::where('cle', 'courriers.traiter_affecte')->firstOrFail()->users()->attach($responsable->id);
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $collaborateur = User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => 'Collaborateur'])->id,
            'service_id' => $service->id,
        ]);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        $test = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id]);

        $this->assertSame([$collaborateur->id], $test->get('collaborateursDuService')->pluck('id')->all());

        $test->set('collaborateurSelectionne', $responsable->id)
            ->call('affecter')
            ->assertHasErrors('collaborateurSelectionne');

        $this->assertSame('enregistre', $courrier->fresh()->statut);
    }

    // Filet de sécurité (Règle n°6) — sans le privilège, le responsable ne
    // peut pas s'affecter le courrier : ni « Me l'affecter et commencer »,
    // ni son identifiant posté à la main. courriers.traiter_affecte est un
    // défaut du profil depuis le 2026-10-13 (voir PrivilegeSeeder) — retiré
    // explicitement ici pour tester vraiment le cas "sans le privilège".
    public function test_un_responsable_sans_le_privilege_ne_peut_pas_saffecter_le_courrier(): void
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
            ->assertDontSee(__('Me l\'affecter et commencer'))
            ->set('collaborateurSelectionne', $responsable->id)
            ->call('affecter')
            ->assertHasErrors('collaborateurSelectionne');

        $this->assertSame('enregistre', $courrier->fresh()->statut);
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
        // delais") — délai préempli au SLA par défaut. 2026-10-29 (« Un seul
        // bouton Affecter ») : plus de bouton ni de modale à part, c'est le
        // choix « Me l'affecter et commencer » de la modale « Affecter ».
        $test = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('peutTraiterMoiMeme', true)
            ->assertSee(__('Me l\'affecter et commencer'))
            ->assertDontSee(__('Traiter moi-même'));

        $this->assertNotNull($test->get('delaiValeur'));
        $this->assertSame('jours', $test->get('delaiUnite'));

        $test->set('modeAffectation', 'moi')->call('affecter')->assertHasNoErrors();

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
    // pour aller jusqu'au bout du cycle, pas seulement du premier.
    // 2026-10-29 (« retire Réaffecter, Rejeter ce courrier et Soumettre
    // pour validation quand c'est responsable et directeur », précisé :
    // quand il traite lui-même) — le chef qui s'est affecté le courrier ne
    // voit plus que « Valider directement » (CourrierPolicy::traiteLuiMeme()) ;
    // les trois actions retirées sont aussi refusées côté serveur.
    public function test_un_responsable_qui_traite_lui_meme_ne_fait_que_valider_directement(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('traiterMoiMeme')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('en_traitement', $courrier->statut);
        $this->assertFalse($responsable->can('soumettreValidation', $courrier));
        $this->assertFalse($responsable->can('reaffecter', $courrier));
        $this->assertFalse($responsable->can('rejeter', $courrier));
        $this->assertTrue($responsable->can('mettreEnAttente', $courrier));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Valider directement'))
            ->assertSee(__('Mettre en attente'))
            ->assertDontSee(__('Soumettre pour validation'))
            ->assertDontSee(__('Réaffecter'))
            ->assertDontSee(__('Rejeter'))
            // 2026-10-30 — « Responsable » au lieu de « Collaborateur assigné ».
            ->assertDontSee(__('Collaborateur assigné'))
            ->assertSeeInOrder([__('Responsable'), $responsable->name]);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('soumettrePourValidation')
            ->assertForbidden();
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('motifRejet', 'Pas pour notre service')
            ->call('rejeter')
            ->assertForbidden();

        $this->assertSame('en_traitement', $courrier->fresh()->statut);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('validerDirectement')
            ->assertHasNoErrors();

        $this->assertSame('traite', $courrier->fresh()->statut);
    }

    // 2026-10-30 (« if it is the responsible doing the traitement some of the
    // workflow process should not send notif ») — jamais notifié de ses
    // propres actions : ni « affecté », ni « soumis pour validation », ni
    // « validé » quand il s'affecte, traite et valide lui-même.
    public function test_le_responsable_qui_traite_lui_meme_nest_pas_notifie_de_ses_propres_actions(): void
    {
        Notification::fake();
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('modeAffectation', 'moi')
            ->call('affecter')
            ->assertHasNoErrors();
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('validerDirectement')
            ->assertHasNoErrors();

        $this->assertSame('traite', $courrier->fresh()->statut);
        Notification::assertNotSentTo($responsable, CourrierNotification::class);
    }

    // 2026-10-30 (« since it is the responsable doing the traitement … is
    // this circuit good representing it ») — parcours à 5 étapes quand le
    // chef traite lui-même : « Prise en charge par le responsable », pas de
    // « Réponse » ; 6 étapes inchangées pour un collaborateur.
    public function test_le_parcours_sadapte_quand_le_responsable_traite_lui_meme(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'RSP']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $courrier = $this->courrier($service);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('modeAffectation', 'moi')
            ->call('affecter')
            ->assertHasNoErrors();

        $etapes = collect(Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->get('etapesParcours'));

        $this->assertSame(['Création', 'Transfert', 'Prise en charge', 'Traitement', 'Clôture'], $etapes->pluck('libelle')->all());
        $this->assertSame('par le responsable', $etapes->firstWhere('statut', 'affecte')['precision']);
        $this->assertTrue($etapes->firstWhere('statut', 'en_traitement')['courante']);

        [$autreResponsable, , $courrierCollaborateur] = $this->courrierEnTraitementChezUnCollaborateur();
        $this->actingAs($autreResponsable);
        $etapesCollaborateur = collect(Livewire::test(ShowCourrier::class, ['courrierId' => $courrierCollaborateur->id])->get('etapesParcours'));

        $this->assertSame(['Création', 'Transfert', 'Affectation', 'Traitement', 'Réponse', 'Clôture'], $etapesCollaborateur->pluck('libelle')->all());
    }

    // Cas nominal inchangé : quand un collaborateur traite le courrier, le
    // responsable garde « Réaffecter » et « Rejeter ».
    public function test_le_responsable_garde_reaffecter_et_rejeter_quand_un_collaborateur_traite(): void
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $responsable = User::find($responsable->id);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service)->fresh();
        $workflow = app(WorkflowService::class);
        $workflow->affecter($courrier, $collaborateur, $responsable);
        $workflow->demarrerTraitement($courrier->fresh(), $collaborateur);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Réaffecter'))
            ->assertSee(__('Rejeter'))
            ->assertDontSee(__('Valider directement'))
            ->assertSeeInOrder([__('Collaborateur assigné'), $collaborateur->name]);
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

    // Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service
    // et acceptation") — l'accueil transfère au service par la cascade
    // Organisation (plus de validation DGA). Courrier créé par $agent, en
    // attente de transfert, sans service.
    private function courrierATransfererPar(User $agent, array $attributs = []): Courrier
    {
        $courrier = $this->courrier(Service::factory()->create(['code' => 'INI']), array_merge(['statut' => 'en_attente_de_transfert', 'service_id' => null], $attributs));
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        return $courrier;
    }

    private function serviceAvecResponsable(string $code): Service
    {
        return Service::factory()->create(['code' => $code, 'responsable_id' => $this->utilisateur('Responsable de service')->id]);
    }

    // 2026-09-23, clarification explicite de l'utilisateur (cas réel
    // rencontré : "Direction des Sinistres" créée en base SANS Site
    // au-dessus) : un Département RACINE (parent_id null) reste
    // sélectionnable pour transférer, sans qu'un Site soit choisi.
    public function test_lagent_transfere_via_un_departement_racine_sans_site(): void
    {
        $agent = $this->utilisateur('Agent');
        $serviceRetenu = $this->serviceAvecResponsable('AUT');
        $departementRacine = OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_DEPARTMENT,
            'parent_id' => null,
            'service_id' => $serviceRetenu->id,
        ]);
        $courrier = $this->courrierATransfererPar($agent);
        $this->actingAs($agent);

        $composant = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('siteSelectionneId', null)
            ->call('transferer')
            ->assertHasErrors('uniteSelectionneeId');

        $this->assertTrue(collect($composant->get('departementsDisponibles'))->pluck('id')->contains($departementRacine->id));

        $composant
            ->set('departementSelectionneId', $departementRacine->id)
            ->call('transferer')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('en_cours_de_transfert', $courrier->statut);
        $this->assertSame($serviceRetenu->id, $courrier->service_id);
    }

    // Présélection RÉELLE de la proposition de classement dans la cascade
    // (pas seulement la prétention d'un commentaire), comme pour l'affectation.
    public function test_le_service_propose_est_preselectionne_pour_le_transfert(): void
    {
        $agent = $this->utilisateur('Agent');
        $servicePropose = $this->serviceAvecResponsable('AUT');
        $departement = $this->departementPonte($servicePropose);
        $courrier = $this->courrierATransfererPar($agent, ['service_propose_id' => $servicePropose->id]);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSet('siteSelectionneId', $departement->parent_id)
            ->assertSet('departementSelectionneId', $departement->id)
            ->call('transferer')
            ->assertHasNoErrors();

        $this->assertSame($servicePropose->id, $courrier->refresh()->service_id);
    }

    // 2026-09-24 — les courriers transférés restent dans les listes de la
    // DGA (recherche, courriers enregistrés) mais PAS dans sa file "à
    // traiter" : ils ne l'attendent plus.
    public function test_la_dga_retrouve_ses_courriers_transferes_hors_de_sa_file_a_traiter(): void
    {
        $dga = $this->utilisateur('DG');
        $autreDga = $this->utilisateur('DG');
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
        $dga = $this->utilisateur('DG');
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

    // Bug réel constaté le 2026-09-23 : courrier envoyé vers "Sinistre Santé"
    // (unité d'un AUTRE département, restée en mémoire) alors que l'écran
    // affichait "Departement Informatique".
    public function test_une_unite_perimee_dun_autre_departement_est_refusee(): void
    {
        $agent = $this->utilisateur('Agent');
        $serviceSinistres = $this->serviceAvecResponsable('SIN');
        $serviceSante = $this->serviceAvecResponsable('SAN');
        $serviceInfo = $this->serviceAvecResponsable('INF');
        $sinistres = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => null, 'service_id' => $serviceSinistres->id]);
        $sante = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_SERVICE, 'parent_id' => $sinistres->id, 'service_id' => $serviceSante->id]);
        $info = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'parent_id' => null, 'service_id' => $serviceInfo->id]);
        $courrier = $this->courrierATransfererPar($agent);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('departementSelectionneId', $info->id)
            ->set('uniteSelectionneeId', $sante->id)
            ->call('transferer')
            ->assertHasErrors('uniteSelectionneeId')
            ->assertSet('uniteSelectionneeId', null);

        $this->assertSame('en_attente_de_transfert', $courrier->refresh()->statut);
        $this->assertNull($courrier->service_id);
    }

    public function test_sans_departement_choisi_le_message_le_dit_clairement(): void
    {
        $agent = $this->utilisateur('Agent');
        $this->departementPonte($this->serviceAvecResponsable('TST'));
        $courrier = $this->courrierATransfererPar($agent);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('transferer')
            ->assertHasErrors('uniteSelectionneeId')
            ->assertSee(__('Choisissez une direction, un département ou un service.'));
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
        $dga = $this->utilisateur('DG');
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
        $dga = $this->utilisateur('DG');
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

    // 2026-10-23 — le transfert au service prévient son responsable, avec
    // l'agent comme auteur réel de l'action (remplace la notification de la
    // validation DGA, retirée).
    public function test_transferer_notifie_le_responsable_du_service_retenu(): void
    {
        Notification::fake();
        $agent = $this->utilisateur('Agent');
        $responsableRetenu = $this->utilisateur('Responsable de service');
        $serviceRetenu = Service::factory()->create(['code' => 'AUT', 'responsable_id' => $responsableRetenu->id]);
        $departement = $this->departementPonte($serviceRetenu);
        $courrier = $this->courrierATransfererPar($agent);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('siteSelectionneId', $departement->parent_id)
            ->set('departementSelectionneId', $departement->id)
            ->call('transferer')
            ->assertHasNoErrors();

        Notification::assertSentTo($responsableRetenu, CourrierNotification::class, fn ($n) => $n->action === 'transfert' && $n->envoyePar?->id === $agent->id);
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
        $dga = $this->utilisateur('DG');
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
        $dga = $this->utilisateur('DG');
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

        // 2026-10-30 — jamais notifié de sa propre action (voir
        // WorkflowService::notifier()) : le retour est marqué par un autre
        // administrateur pour que l'émetteur soit prévenu.
        $autreAdmin = $this->utilisateur('Administrateur');
        $this->actingAs($autreAdmin);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('marquerDechargeRendue')
            ->assertHasNoErrors();

        Notification::assertSentTo($admin, CourrierNotification::class, fn ($n) => $n->action === 'decharge_rendue');
        Notification::assertNotSentTo($autreAdmin, CourrierNotification::class);
    }

    // 2026-10-30 (Module 7, « on doit pouvoir les relancer manuellement
    // aussi ») — courrier en traitement chez un collaborateur, suivi par son
    // responsable.
    private function courrierEnTraitementChezUnCollaborateur(): array
    {
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['responsable_id' => $responsable->id, 'code' => 'TST']);
        $responsable->update(['service_id' => $service->id]);
        $collaborateur = $this->utilisateur('Collaborateur', $service);
        $courrier = $this->courrier($service)->fresh();
        $workflow = app(WorkflowService::class);
        $workflow->affecter($courrier, $collaborateur, $responsable);
        $workflow->demarrerTraitement($courrier->fresh(), $collaborateur);

        return [User::find($responsable->id), $collaborateur, $courrier->fresh()];
    }

    public function test_le_responsable_relance_le_collaborateur_affecte(): void
    {
        Notification::fake();
        [$responsable, $collaborateur, $courrier] = $this->courrierEnTraitementChezUnCollaborateur();
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Relancer'))
            ->set('messageRelance', 'Merci de traiter avant vendredi')
            ->call('relancer')
            ->assertHasNoErrors();

        Notification::assertSentTo($collaborateur, CourrierNotification::class, fn ($n) => $n->action === 'relance'
            && $n->commentaire === 'Merci de traiter avant vendredi'
            && $n->envoyePar?->id === $responsable->id);
        $this->assertSame('en_traitement', $courrier->fresh()->statut, 'une relance ne change pas le statut');
        $this->assertDatabaseHas('courrier_historiques', [
            'courrier_id' => $courrier->id,
            'auteur_id' => $responsable->id,
            'action' => 'relance',
            'commentaire' => "Relance envoyée à {$collaborateur->name} — Merci de traiter avant vendredi",
        ]);
    }

    // Règle n°1/7 — la notification de relance (mail compris) part en file
    // d'attente, jamais envoyée dans la requête.
    public function test_la_relance_est_envoyee_en_file_dattente(): void
    {
        [$responsable, , $courrier] = $this->courrierEnTraitementChezUnCollaborateur();
        Queue::fake();
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('relancer')
            ->assertHasNoErrors();

        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof CourrierNotification
            && $job->notification->action === 'relance');
    }

    // Règle n°6/7 — droits refusés : le collaborateur affecté (ni se relancer
    // soi-même, ni le privilège), un responsable d'un autre service, et un
    // courrier pas encore affecté.
    public function test_la_relance_est_refusee_hors_de_ses_droits(): void
    {
        Notification::fake();
        [$responsable, $collaborateur, $courrier] = $this->courrierEnTraitementChezUnCollaborateur();
        $autreResponsable = $this->utilisateur('Responsable de service');
        $autreResponsable->update(['service_id' => Service::factory()->create(['responsable_id' => $autreResponsable->id, 'code' => 'AUT'])->id]);

        $this->assertFalse(User::find($collaborateur->id)->can('relancer', $courrier));
        $this->assertFalse(User::find($autreResponsable->id)->can('relancer', $courrier));

        $this->actingAs($collaborateur);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertDontSee(__('Relancer'))
            ->call('relancer')
            ->assertForbidden();

        $nonAffecte = $this->courrier($courrier->service)->fresh();
        $this->assertFalse($responsable->can('relancer', $nonAffecte));

        Notification::assertNotSentTo($collaborateur, CourrierNotification::class, fn ($n) => $n->action === 'relance');
        $this->assertSame(0, CourrierHistorique::where('action', 'relance')->count());
    }
}
