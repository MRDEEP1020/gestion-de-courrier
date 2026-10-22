<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendMailAlertJob;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Parametre;
use App\Models\Privilege;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Notifications\CourrierEnRetardNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Module 5/7 — alertes SLA et relances automatiques (2026-09-23, voir
// DECISIONS.md "SLA et alertes").
class SendMailAlertJobTest extends TestCase
{
    use RefreshDatabase;

    private User $responsable;

    private User $collaborateur;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responsable = User::factory()->create(['profil_id' => Profil::where('nom', 'Responsable de service')->value('id')]);
        $this->service = Service::factory()->create(['responsable_id' => $this->responsable->id]);
        $this->collaborateur = User::factory()->create([
            'profil_id' => Profil::where('nom', 'Collaborateur')->value('id'),
            'service_id' => $this->service->id,
        ]);
    }

    // Courrier affecté au collaborateur, avec une date limite à J+$joursRestants
    // (échéance explicite, prioritaire sur tout délai calculé).
    private function courrierAffecte(int $joursRestants, array $attributs = []): Courrier
    {
        $courrier = Courrier::create(array_merge([
            'numero_reference' => 'GEC-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now()->subDays(20),
            'echeance' => today()->addDays($joursRestants),
            'objet' => 'Réclamation client',
            'type_document' => 'Réclamation',
            'mode_reception' => 'email',
            'service_id' => $this->service->id,
            'statut' => 'affecte',
        ], $attributs));

        Affectation::create(['courrier_id' => $courrier->id, 'user_id' => $this->collaborateur->id, 'affecte_par_id' => $this->responsable->id]);

        return $courrier;
    }

    private function executer(): void
    {
        app()->call([new SendMailAlertJob, 'handle']);
    }

    // Règle n°7 — le job se dispatche bien sur la queue.
    public function test_le_job_se_dispatche_sur_la_queue(): void
    {
        Queue::fake();

        SendMailAlertJob::dispatch();

        Queue::assertPushed(SendMailAlertJob::class);
    }

    public function test_le_job_est_enregistre_sur_le_scheduler(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('alertes-sla', Artisan::output());
    }

    public function test_un_courrier_en_retard_alerte_le_collaborateur_et_le_responsable(): void
    {
        Notification::fake();
        $courrier = $this->courrierAffecte(-1);

        $this->executer();

        Notification::assertSentTo([$this->collaborateur, $this->responsable], CourrierEnRetardNotification::class,
            fn ($n) => $n->enRetard && $n->courrier->is($courrier));
        $this->assertNotNull($courrier->fresh()->alerte_retard_le);
        $this->assertDatabaseHas('courrier_historiques', ['courrier_id' => $courrier->id, 'action' => 'alerte_retard', 'auteur_id' => null]);
    }

    public function test_un_courrier_bientot_en_retard_alerte_seulement_le_collaborateur(): void
    {
        Notification::fake();
        $courrier = $this->courrierAffecte(1);

        $this->executer();

        Notification::assertSentTo($this->collaborateur, CourrierEnRetardNotification::class, fn ($n) => ! $n->enRetard);
        Notification::assertNotSentTo($this->responsable, CourrierEnRetardNotification::class);
        $this->assertNotNull($courrier->fresh()->alerte_risque_le);
    }

    public function test_un_courrier_dans_les_temps_nest_pas_alerte(): void
    {
        Notification::fake();
        $this->courrierAffecte(10);

        $this->executer();

        Notification::assertNothingSent();
    }

    public function test_un_courrier_cloture_nest_jamais_alerte(): void
    {
        Notification::fake();
        $this->courrierAffecte(-5, ['statut' => 'traite']);

        $this->executer();

        Notification::assertNothingSent();
    }

    public function test_pas_de_doublon_puis_relance_apres_le_delai(): void
    {
        Notification::fake();
        $courrier = $this->courrierAffecte(-1);

        $this->executer();
        $this->executer();
        Notification::assertSentToTimes($this->collaborateur, CourrierEnRetardNotification::class, 1);

        $this->travel(Parametre::actuel()->sla_relance_jours)->days();
        $this->executer();
        Notification::assertSentToTimes($this->collaborateur, CourrierEnRetardNotification::class, 2);
        $this->assertSame(2, CourrierHistorique::where('courrier_id', $courrier->id)->where('action', 'alerte_retard')->count());
    }

    // Une nouvelle échéance ré-arme les alertes (délai prolongé).
    public function test_prolonger_lecheance_rearme_les_alertes(): void
    {
        Notification::fake();
        $courrier = $this->courrierAffecte(-1);
        $this->executer();

        $courrier->update(['echeance' => today()->addDay()]);
        $this->assertNull($courrier->fresh()->alerte_retard_le);

        $this->executer();
        Notification::assertSentTo($this->collaborateur, CourrierEnRetardNotification::class, fn ($n) => ! $n->enRetard);
    }

    // Règle n°6 — jamais d'alerte vers qui ne peut pas voir le courrier.
    public function test_un_courrier_trop_confidentiel_nest_pas_envoye_a_un_niveau_insuffisant(): void
    {
        Notification::fake();
        $courrier = $this->courrierAffecte(-1, ['confidentialite' => 5]);

        $this->executer();

        Notification::assertNothingSent();
        // Tracé quand même (Règle n°5), et marqué pour ne pas boucler.
        $this->assertDatabaseHas('courrier_historiques', ['courrier_id' => $courrier->id, 'action' => 'alerte_retard']);
        $this->assertNotNull($courrier->fresh()->alerte_retard_le);
    }

    public function test_lemail_dun_courrier_confidentiel_ne_contient_pas_lobjet(): void
    {
        $this->responsable->update(['niveau_confidentialite' => 5]);
        $courrier = $this->courrierAffecte(-1, ['confidentialite' => 3, 'objet' => 'OBJET-SECRET']);

        $mail = (new CourrierEnRetardNotification($courrier, true))->toMail($this->responsable);

        $this->assertStringNotContainsString('OBJET-SECRET', implode(' ', $mail->introLines));
        $this->assertStringContainsString($courrier->numero_reference, $mail->subject);
    }

    // ===== Escalade (2026-10-05, Module 7, "escalade... configurable selon
    // le niveau de retard") =====

    public function test_escalade_desactivee_par_defaut_ne_notifie_personne_de_plus(): void
    {
        Notification::fake();
        $this->courrierAffecte(-30);

        $this->executer();

        Notification::assertNotSentTo($this->responsable, CourrierEnRetardNotification::class, fn ($n) => $n->escalade);
    }

    public function test_escalade_pas_encore_notifiee_avant_le_seuil_configure(): void
    {
        Notification::fake();
        Parametre::actuel()->update(['sla_escalade_jours' => 5]);
        Parametre::invaliderCache();
        $courrier = $this->courrierAffecte(-2);

        $this->executer();

        Notification::assertNotSentTo([$this->collaborateur, $this->responsable], CourrierEnRetardNotification::class, fn ($n) => $n->escalade);
        $this->assertNull($courrier->fresh()->alerte_escalade_le);
    }

    public function test_escalade_notifiee_apres_le_seuil_configure(): void
    {
        Notification::fake();
        Parametre::actuel()->update(['sla_escalade_jours' => 3]);
        Parametre::invaliderCache();
        $courrier = $this->courrierAffecte(-5);

        $this->executer();

        Notification::assertSentTo([$this->collaborateur, $this->responsable], CourrierEnRetardNotification::class, fn ($n) => $n->escalade && $n->courrier->is($courrier));
        $this->assertNotNull($courrier->fresh()->alerte_escalade_le);
        $this->assertDatabaseHas('courrier_historiques', ['courrier_id' => $courrier->id, 'action' => 'alerte_escalade']);
    }

    public function test_escalade_ne_se_repete_pas(): void
    {
        Notification::fake();
        Parametre::actuel()->update(['sla_escalade_jours' => 3]);
        Parametre::invaliderCache();
        $this->courrierAffecte(-5);

        $this->executer();
        $this->executer();

        $this->assertCount(1, Notification::sent($this->collaborateur, CourrierEnRetardNotification::class, fn ($n) => $n->escalade));
    }

    public function test_escalade_notifie_en_plus_le_responsable_du_niveau_superieur_de_lorganigramme(): void
    {
        Notification::fake();
        Parametre::actuel()->update(['sla_escalade_jours' => 3]);
        Parametre::invaliderCache();

        // Règle n°6 — l'escalade reste soumise à CourrierPolicy::view() comme
        // tout destinataire d'alerte : un simple "Responsable de service"
        // d'un AUTRE service n'y suffit pas (courriers.voir_service n'accorde
        // que SON PROPRE service) ; le niveau hiérarchique supérieur a donc
        // besoin d'une visibilité plus large, ici courriers.voir_tout.
        $directeur = User::factory()->create(['profil_id' => Profil::where('nom', 'Responsable de service')->value('id')]);
        Privilege::where('cle', 'courriers.voir_tout')->firstOrFail()->users()->attach($directeur->id);
        $departement = OrganizationUnit::factory()->create(['type' => OrganizationUnit::TYPE_DEPARTMENT, 'responsible_user_id' => $directeur->id]);
        $noeudService = OrganizationUnit::factory()->create([
            'type' => OrganizationUnit::TYPE_SERVICE,
            'parent_id' => $departement->id,
            'service_id' => $this->service->id,
            'responsible_user_id' => $this->responsable->id,
        ]);

        $courrier = $this->courrierAffecte(-5);

        $this->executer();

        Notification::assertSentTo($directeur, CourrierEnRetardNotification::class, fn ($n) => $n->escalade && $n->courrier->is($courrier));
    }

    // Service sans pont vers l'organigramme (la majorité à ce jour, voir
    // OrganizationUnit::departementLabelParServiceId()) : l'escalade reste
    // silencieuse sur le destinataire supplémentaire au lieu de planter.
    public function test_escalade_sans_pont_organigramme_nenvoie_pas_de_destinataire_supplementaire(): void
    {
        Notification::fake();
        Parametre::actuel()->update(['sla_escalade_jours' => 3]);
        Parametre::invaliderCache();
        $courrier = $this->courrierAffecte(-5);

        $this->executer();

        Notification::assertSentTo([$this->collaborateur, $this->responsable], CourrierEnRetardNotification::class, fn ($n) => $n->escalade && $n->courrier->is($courrier));
        $this->assertNotNull($courrier->fresh()->alerte_escalade_le);
    }
}
