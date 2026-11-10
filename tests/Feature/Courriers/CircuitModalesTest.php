<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-10-28 (« all button in the circuit de traitement should have a modal
// … well design ») — chaque action de l'onglet Circuit ouvre une modale
// <x-circuit.modale> ; refermée par ShowCourrier::executer() après l'action,
// laissée ouverte quand un champ obligatoire manque (erreur affichée dedans).
class CircuitModalesTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil, ?Service $service = null): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id,
            'service_id' => $service?->id,
        ]);
    }

    /** @return array{0: User, 1: User, 2: User, 3: Courrier} agent, responsable, collaborateur, courrier accepté par le service */
    private function courrierAccepte(): array
    {
        Notification::fake();
        $agent = $this->utilisateur('Agent');
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['code' => 'TRS', 'responsable_id' => $responsable->id]);
        $responsable->update(['service_id' => $service->id]);
        $collaborateur = $this->utilisateur('Collaborateur', $service);

        $courrier = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000077',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Demande de remboursement',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'statut' => 'en_attente_de_transfert',
        ]);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        $workflow = app(WorkflowService::class);
        $workflow->transfererAuService($courrier, $service, $agent);
        $workflow->accepterTransfert($courrier->refresh(), User::find($responsable->id));

        return [$agent, User::find($responsable->id), $collaborateur, $courrier->refresh()];
    }

    public function test_le_transfert_passe_par_une_modale(): void
    {
        $agent = $this->utilisateur('Agent');
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000078',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Objet',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'statut' => 'en_attente_de_transfert',
        ]);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSeeHtml('data-modal="transferer-modal"')
            ->assertSee(__('Ce qui va se passer'))
            ->assertSee(__('Le responsable du service est prévenu et doit l\'accepter ou le refuser.'));
    }

    public function test_les_actions_du_responsable_ouvrent_chacune_leur_modale(): void
    {
        [$agent, $responsable, $collaborateur, $courrier] = $this->courrierAccepte();
        $workflow = app(WorkflowService::class);
        $workflow->affecter($courrier, $collaborateur, $responsable);
        $workflow->demarrerTraitement($courrier->refresh(), $collaborateur);
        $workflow->soumettrePourValidation($courrier->refresh(), $collaborateur);
        $this->actingAs($responsable);

        $html = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->html();

        foreach (['valider-modal', 'renvoyer-modal', 'rejeter-modal', 'attente-modal'] as $modale) {
            $this->assertStringContainsString('data-modal="'.$modale.'"', $html, "Modale {$modale} absente.");
        }
        $this->assertStringNotContainsString('wire:confirm', $html, 'plus aucune boîte de confirmation du navigateur');
    }

    public function test_la_modale_se_referme_apres_laction(): void
    {
        [$agent, $responsable, $collaborateur, $courrier] = $this->courrierAccepte();
        $workflow = app(WorkflowService::class);
        $workflow->affecter($courrier, $collaborateur, $responsable);
        $workflow->demarrerTraitement($courrier->refresh(), $collaborateur);
        $workflow->soumettrePourValidation($courrier->refresh(), $collaborateur);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->set('commentaireCirculation', 'Réponse envoyée au client.')
            ->call('valider')
            ->assertDispatched('modal-close');

        $this->assertSame('traite', $courrier->refresh()->statut);
    }

    public function test_un_motif_manquant_laisse_la_modale_ouverte_avec_lerreur(): void
    {
        [, $responsable, , $courrier] = $this->courrierAccepte();
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSeeHtml('data-modal="rejeter-modal"')
            ->call('rejeter')
            ->assertHasErrors('motifRejet')
            ->assertNotDispatched('modal-close');

        $this->assertSame('enregistre', $courrier->refresh()->statut);
    }

    // 2026-10-29 (« it should appear only when a condition is met not all of
    // it like that », ordre choisi : remise → signature → affectation) — une
    // étape à la fois, chacune dans sa modale, refusée côté serveur avant son
    // tour.
    public function test_remise_puis_signature_puis_affectation_une_etape_a_la_fois(): void
    {
        [$agent, $responsable, , $courrier] = $this->courrierAccepte();

        // 1) Seul l'accueil a une action : la remise de l'original.
        $this->actingAs($responsable);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('En attente de l\'original papier'))
            ->assertDontSeeHtml('data-modal="signer-reception-modal"')
            ->assertDontSeeHtml('data-modal="affecter-modal"')
            ->assertDontSee(__('Autres actions'))
            ->call('affecter')
            ->assertForbidden();

        $this->actingAs($agent);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSeeHtml('data-modal="remise-original-modal"')
            ->call('marquerOriginalRemis')
            ->assertDispatched('modal-close');
        $this->assertNotNull($courrier->refresh()->dernierTransfert->original_remis_le);

        // 2) Le service signe la réception — toujours pas d'affectation.
        $this->actingAs($responsable);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Signez la réception de l\'original pour pouvoir affecter le courrier.'))
            ->assertSeeHtml('data-modal="signer-reception-modal"')
            ->assertDontSeeHtml('data-modal="affecter-modal"')
            ->call('signerReceptionOriginal')
            ->assertDispatched('modal-close');

        // 3) Puis seulement « Affecter » (un seul bouton).
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSeeHtml('data-modal="affecter-modal"')
            ->assertSee(__('Le collaborateur choisi est prévenu et devient responsable du traitement.'))
            ->assertDontSee(__('Traiter moi-même'))
            ->call('affecter')
            ->assertHasNoErrors();

        $this->assertSame('affecte', $courrier->refresh()->statut);
    }

    // La signature de la réception attend la remise par l'accueil.
    public function test_la_signature_de_la_reception_attend_la_remise(): void
    {
        [, $responsable, , $courrier] = $this->courrierAccepte();

        $this->assertFalse($responsable->can('signerReceptionOriginal', $courrier));
        $this->assertFalse($responsable->can('affecterPremiere', $courrier));
        $this->assertFalse($responsable->can('traiterMoiMeme', $courrier));
    }

    // « remove the telecharge and imprimer button from there » — le
    // bordereau s'imprime depuis l'onglet « Général ».
    public function test_le_bordereau_simprime_depuis_longlet_general(): void
    {
        [$agent, , , $courrier] = $this->courrierAccepte();
        $this->actingAs($agent);

        $html = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Imprimer le bordereau'))
            ->html();

        $debutGeneral = strpos($html, 'x-show="onglet === \'general\'"');
        $lienBordereau = strpos($html, e(route('courriers.bordereau', $courrier->id)));
        $this->assertNotFalse($lienBordereau);
        $this->assertGreaterThan($debutGeneral, $lienBordereau, 'lien du bordereau dans l\'onglet Général, plus dans l\'en-tête');
    }

    // 2026-10-30, bug réel (capture : « Choisissez un collaborateur du
    // service. » alors que le menu affichait « Collaborateur Trésorerie ») —
    // signer la réception sur la fiche remettait la présélection à vide ;
    // « Affecter » juste après échouait sans que le menu ait été touché.
    public function test_affecter_juste_apres_avoir_signe_la_reception_garde_la_preselection(): void
    {
        [$agent, $responsable, $collaborateur, $courrier] = $this->courrierAccepte();
        app(WorkflowService::class)->marquerOriginalRemis($courrier, $agent);
        $this->actingAs($responsable);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('signerReceptionOriginal')
            ->assertHasNoErrors()
            ->assertSet('collaborateurSelectionne', $collaborateur->id)
            ->assertNotSet('delaiValeur', null)
            ->call('affecter')
            ->assertHasNoErrors();

        $courrier->refresh();
        $this->assertSame('affecte', $courrier->statut);
        $this->assertSame($collaborateur->id, $courrier->affectationCourante->user_id);
    }

    // 2026-10-30 (capture du collaborateur, choix « bouton direct, pas de
    // menu ») — une seule action secondaire (« Mettre en attente » pour le
    // collaborateur affecté) : bouton direct, pas de menu « Autres actions ».
    public function test_une_seule_action_secondaire_saffiche_en_bouton_direct(): void
    {
        [$agent, $responsable, $collaborateur, $courrier] = $this->courrierAccepte();
        $workflow = app(WorkflowService::class);
        $workflow->marquerOriginalRemis($courrier, $agent);
        $workflow->signerReceptionOriginal($courrier->refresh(), $responsable);
        $workflow->affecter($courrier->refresh(), $collaborateur, $responsable);
        $this->actingAs(User::find($collaborateur->id));

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Démarrer le traitement'))
            ->assertSee(__('Mettre en attente'))
            ->assertDontSee(__('Autres actions'));

        // Le responsable, lui, a plusieurs actions secondaires : menu conservé.
        $this->actingAs($responsable);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Autres actions'));
    }

    // 2026-10-29 (« le bouton Marquer original doit être marqué avant de
    // traiter le courrier ») — filet de sécurité : même affecté par un autre
    // chemin que l'écran, le courrier ne se traite pas avant la remise.
    public function test_le_traitement_attend_la_remise_de_loriginal(): void
    {
        [$agent, $responsable, $collaborateur, $courrier] = $this->courrierAccepte();
        app(WorkflowService::class)->affecter($courrier, $collaborateur, $responsable);
        $courrier->refresh();
        $collaborateur = User::find($collaborateur->id);

        $this->assertFalse($collaborateur->can('traiter', $courrier));
        $this->actingAs($collaborateur);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertDontSee(__('Démarrer le traitement'));
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->call('demarrerTraitement')
            ->assertForbidden();
        $this->assertSame('affecte', $courrier->refresh()->statut);

        app(WorkflowService::class)->marquerOriginalRemis($courrier, $agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Démarrer le traitement'))
            ->call('demarrerTraitement')
            ->assertHasNoErrors();
        $this->assertSame('en_traitement', $courrier->refresh()->statut);
    }

    // 2026-10-29 (« i wanted the circuit to appear beside those buttons rather
    // than in the tab since they are hidden there ») — boutons dans l'en-tête,
    // modales hors des onglets, plus rien d'actionnable dans l'onglet Circuit.
    public function test_les_actions_de_letape_sont_dans_len_tete_et_plus_dans_longlet(): void
    {
        [, $responsable, $collaborateur, $courrier] = $this->courrierAccepte();
        $workflow = app(WorkflowService::class);
        $workflow->affecter($courrier, $collaborateur, $responsable);
        $workflow->demarrerTraitement($courrier->refresh(), $collaborateur);
        $workflow->soumettrePourValidation($courrier->refresh(), $collaborateur);
        $this->actingAs($responsable);

        $html = Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])->html();

        $boutonsEnTete = strpos($html, __('Autres actions'));
        $premiereModale = strpos($html, __('Ce qui va se passer'));
        $debutOnglet = strpos($html, 'x-show="onglet === \'circuit\'"');
        $this->assertNotFalse($boutonsEnTete, 'menu « Autres actions » dans l\'en-tête');
        $this->assertNotFalse($debutOnglet);
        $this->assertLessThan($premiereModale, $boutonsEnTete, 'boutons avant les modales');
        $this->assertLessThan($debutOnglet, $premiereModale, 'modales avant (donc hors de) l\'onglet');

        $finOnglet = strpos($html, 'x-show="onglet === \'', $debutOnglet + 10) ?: strlen($html);
        $onglet = substr($html, $debutOnglet, $finOnglet - $debutOnglet);
        $this->assertStringNotContainsString('data-modal="', $onglet, 'plus de modale dans l\'onglet');
        $this->assertStringNotContainsString(__('Valider'), $onglet);
        $this->assertStringContainsString(e(__('Les actions de l\'étape en cours sont en haut de la page, à côté de « Retour à la liste ».')), $onglet);

        // 2026-10-29 (« rename the circuit de traitement since it now
        // displays only the décharge ») — l'onglet s'appelle « Décharge ».
        $this->assertStringNotContainsString(__('Circuit de traitement'), $html);
        $this->assertMatchesRegularExpression('/onglet = \'circuit\'".*?'.preg_quote(__('Décharge'), '/').'/s', $html);
    }

    public function test_la_demande_dacceptation_et_le_refus_saffichent_sous_len_tete(): void
    {
        Notification::fake();
        $agent = $this->utilisateur('Agent');
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['code' => 'SIN', 'nom' => 'Service Sinistres', 'responsable_id' => $responsable->id]);
        $responsable->update(['service_id' => $service->id]);
        $courrier = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000079',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Objet',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'statut' => 'en_attente_de_transfert',
        ]);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);
        $workflow = app(WorkflowService::class);
        $workflow->transfererAuService($courrier, $service, $agent);

        $this->actingAs(User::find($responsable->id));
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Courrier transféré à votre service'))
            ->assertSee(__('Accepter le courrier'));

        $workflow->refuserTransfert($courrier->refresh(), User::find($responsable->id), 'Concerne la production');

        $this->actingAs($agent);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Refusé par le service :service', ['service' => 'Service Sinistres']))
            ->assertSee('Concerne la production')
            ->assertSee(__('Transférer au service'));
    }
}
