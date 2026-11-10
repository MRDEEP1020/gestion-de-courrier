<?php

namespace Tests\Feature\Courriers;

use App\Livewire\Backend\ShowCourrier;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Parametre;
use App\Models\Profil;
use App\Models\Service;
use App\Models\TransfertService;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// 2026-10-27 (« arrange the décharge design as a real décharge ») — décharge
// imprimable (PDF) de la remise de l'original papier au service qui a
// accepté le transfert : mêmes droits que la fiche du courrier.
class DechargeRemiseTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(string $nomProfil, ?Service $service = null): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $nomProfil])->id,
            'service_id' => $service?->id,
        ]);
    }

    /** @return array{0: User, 1: User, 2: Courrier, 3: TransfertService} */
    private function transfertAccepte(): array
    {
        Notification::fake();
        $agent = $this->utilisateur('Agent');
        $responsable = $this->utilisateur('Responsable de service');
        $service = Service::factory()->create(['code' => 'TRS', 'nom' => 'Service Trésorerie', 'responsable_id' => $responsable->id]);
        $responsable->update(['service_id' => $service->id]);

        $courrier = Courrier::create([
            'numero_reference' => 'GEC-'.now()->year.'-TST-000042',
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Offre de services',
            'expediteur_organisation' => 'TBS SARL',
            'type_document' => 'Lettre',
            'mode_reception' => 'depot_physique',
            'statut' => 'en_attente_de_transfert',
        ]);
        CourrierHistorique::create(['courrier_id' => $courrier->id, 'auteur_id' => $agent->id, 'action' => 'creation']);

        $workflow = app(WorkflowService::class);
        $workflow->transfererAuService($courrier, $service, $agent);
        $transfert = $workflow->accepterTransfert($courrier->refresh(), User::find($responsable->id));

        return [$agent, User::find($responsable->id), $courrier->refresh(), $transfert];
    }

    public function test_la_decharge_imprimable_est_un_pdf_pour_qui_voit_le_courrier(): void
    {
        [$agent, $responsable, , $transfert] = $this->transfertAccepte();

        foreach ([$agent, $responsable] as $utilisateur) {
            $this->actingAs($utilisateur)
                ->get(route('transferts.decharge', $transfert))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
        }
    }

    public function test_la_decharge_reprend_les_noms_le_numero_et_la_designation(): void
    {
        [$agent, $responsable, $courrier, $transfert] = $this->transfertAccepte();
        $transfert->load('service', 'transferePar', 'decidePar', 'originalRemisPar');

        $html = view('pdf.decharge-remise', ['transfert' => $transfert, 'courrier' => $courrier->load('piecesJointes')])->render();

        $this->assertStringContainsString('DÉCHARGE N° '.$transfert->numero_decharge, $html);
        $this->assertStringContainsString('Je soussigné(e), <strong>'.e($responsable->name).'</strong>', $html);
        $this->assertStringContainsString('reconnais avoir reçu de <strong>'.e($agent->name).'</strong>', $html);
        $this->assertStringContainsString($courrier->numero_reference, $html);
        $this->assertStringContainsString('TBS SARL', $html);
        $this->assertStringContainsString('Pas encore signée dans GEC', $html);
        $this->assertStringContainsString('pour servir et valoir ce que de droit', $html);
    }

    public function test_un_utilisateur_hors_perimetre_ne_peut_pas_imprimer_la_decharge(): void
    {
        [, , , $transfert] = $this->transfertAccepte();
        $autreService = Service::factory()->create(['code' => 'AUT']);

        $this->actingAs($this->utilisateur('Collaborateur', $autreService))
            ->get(route('transferts.decharge', $transfert))
            ->assertForbidden();
    }

    public function test_pas_de_decharge_tant_que_le_transfert_nest_pas_accepte(): void
    {
        Notification::fake();
        [$agent, , , $transfertAccepte] = $this->transfertAccepte();
        $transfertAccepte->update(['statut' => TransfertService::EN_ATTENTE]);

        $this->actingAs($agent)
            ->get(route('transferts.decharge', $transfertAccepte))
            ->assertNotFound();
    }

    // 2026-10-28 — format réglable dans Paramètres système ; la séquence
    // reste l'id du transfert.
    public function test_le_numero_suit_le_format_choisi_dans_les_parametres(): void
    {
        Parametre::actuel()->update(['decharge_numero_prefixe' => 'DCH', 'decharge_numero_chiffres' => 4]);
        Parametre::invaliderCache();

        [, , , $transfert] = $this->transfertAccepte();

        $this->assertSame(sprintf('DCH-%d-%04d', now()->year, $transfert->id), $transfert->numero_decharge);
    }

    // 2026-10-28 (« la date et heure de la décharge et la possibilité de mettre
    // le nom comme signature au cas où on ne veut pas imprimer »).
    private function htmlDecharge(TransfertService $transfert, Courrier $courrier): string
    {
        $transfert->refresh()->load('service', 'transferePar', 'decidePar', 'originalRemisPar', 'receptionSigneePar');

        return view('pdf.decharge-remise', ['transfert' => $transfert, 'courrier' => $courrier->load('piecesJointes')])->render();
    }

    // 2026-11-09 (« only the person receiving the doc should sign, the agent
    // shouldn't sign ») — l'accueil confirme la remise sans signer ; seul le
    // destinataire signe, et la décharge n'a qu'un cadre de signature.
    public function test_seul_le_destinataire_signe_et_son_nom_figure_sur_la_decharge(): void
    {
        [$agent, $responsable, $courrier, $transfert] = $this->transfertAccepte();

        $this->assertStringNotContainsString('Signé électroniquement dans GEC', $this->htmlDecharge($transfert, $courrier), 'aucune signature avant');

        $this->actingAs($agent);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('Remise à confirmer'))
            ->assertSee(__('Confirmer la remise'))
            ->assertDontSee(__('Signer et confirmer la remise'))
            ->assertDontSee(__('Votre signature'))
            ->call('marquerOriginalRemis')
            ->assertDispatched('modal-close');

        // La remise par l'accueil n'est pas une signature.
        $html = $this->htmlDecharge($transfert, $courrier);
        $this->assertStringNotContainsString('<div class="signature">'.e($agent->name).'</div>', $html);
        $this->assertStringContainsString('Confirmée par '.e($agent->name), $html);
        $this->assertStringContainsString('Remise confirmée dans GEC — sans signature', $html);

        $this->actingAs($responsable);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSeeHtml('data-modal="signer-reception-modal"')
            ->call('signerReceptionOriginal')
            ->assertDispatched('modal-close')
            ->assertSee(__('Signée par le destinataire'));

        $transfert->refresh();
        $this->assertSame($responsable->id, $transfert->reception_signee_par_id);
        $this->assertSame(1, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'reception_original_signee')->count());

        $html = $this->htmlDecharge($transfert, $courrier);
        $this->assertSame(1, substr_count($html, 'class="zone-signature"'), 'un seul cadre de signature');
        $this->assertSame(1, substr_count($html, '<div class="signature">'));
        $this->assertStringContainsString('<div class="signature">'.e($responsable->name).'</div>', $html);
        $this->assertStringNotContainsString('<div class="signature">'.e($agent->name).'</div>', $html);
        $this->assertStringNotContainsString('……… / ……… / …………', $html, 'dates et heures remplies');
    }

    // Séparation des rôles : la personne qui a transféré ou remis l'original
    // ne signe jamais la réception, même responsable du service destinataire.
    public function test_celui_qui_remet_loriginal_ne_signe_jamais_la_reception(): void
    {
        [$agent, $responsable, $courrier, $transfert] = $this->transfertAccepte();
        app(WorkflowService::class)->marquerOriginalRemis($courrier, $responsable);
        $courrier->refresh();

        $this->assertSame($responsable->id, $courrier->dernierTransfert->original_remis_par_id);
        $this->assertFalse($responsable->can('signerReceptionOriginal', $courrier), 'a remis lui-même : ne signe pas');
        $this->assertFalse($agent->can('signerReceptionOriginal', $courrier));
    }

    public function test_seul_le_service_destinataire_signe_la_reception_une_seule_fois(): void
    {
        [$agent, $responsable, $courrier] = $this->transfertAccepte();

        $this->actingAs($agent);
        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertDontSeeHtml('data-modal="signer-reception-modal"')
            ->call('signerReceptionOriginal')
            ->assertForbidden();

        app(WorkflowService::class)->signerReceptionOriginal($courrier, $responsable);
        $this->assertFalse($responsable->fresh()->can('signerReceptionOriginal', $courrier->fresh()), 'une seule signature');
    }

    public function test_la_fiche_presente_la_decharge_avec_son_bouton_dimpression(): void
    {
        [$agent, , $courrier, $transfert] = $this->transfertAccepte();
        $this->actingAs($agent);

        Livewire::test(ShowCourrier::class, ['courrierId' => $courrier->id])
            ->assertSee(__('N° :numero', ['numero' => $transfert->numero_decharge]))
            ->assertSee(__('Original à remettre'))
            ->assertSee(__('Imprimer la décharge'))
            ->assertSeeHtml(e(route('transferts.decharge', $transfert)));
    }
}
