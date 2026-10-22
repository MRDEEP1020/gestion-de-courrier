<?php

namespace Tests\Feature;

use App\Jobs\RefreshDashboardStatsJob;
use App\Livewire\Backend\Dashboard;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Profil;
use App\Models\Service;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Audit du tableau de bord (Module 10) demandé par l'utilisateur ("go
// through the dashboard page and find for bugs, static information that
// not been taking from the database and also simulate it", 2026-09-24) :
// simule un jeu de courriers réaliste à travers les VRAIS services
// (WorkflowService, RefreshDashboardStatsJob), fait rendre le tableau de
// bord par plusieurs comptes réels, et vérifie les chiffres affichés
// contre ce qui est réellement en base plutôt que de supposer que le code
// est correct. Rapport lisible écrit dans storage/logs/simulation-dashboard.md.
class SimulationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private array $journal = [];

    public function test_le_tableau_de_bord_reflete_reellement_la_base(): void
    {
        try {
            $this->scenario();
        } finally {
            file_put_contents(
                storage_path('logs/simulation-dashboard.md'),
                '# Simulation du tableau de bord — '.now()->format('Y-m-d H:i')."\n\n".implode("\n", $this->journal)."\n",
            );
        }
    }

    private function scenario(): void
    {
        $this->travelTo(now()->setTime(15, 0));

        $admin = $this->compte('Administrateur');
        $responsable = $this->compte('Responsable de service');
        $service = Service::factory()->create(['code' => 'DIS', 'responsable_id' => $responsable->id]);
        $responsable->update(['service_id' => $service->id]);
        $collaborateur = $this->compte('Collaborateur', $service);
        $agent = $this->compte('Agent');
        $dga = $this->compte('DGA');

        $workflow = app(WorkflowService::class);

        $this->section('Jeu de courriers');

        // C1 — entrant aujourd'hui, actif.
        $c1 = $this->courrier($service, ['sens' => 'entrant', 'date_mouvement' => today(), 'statut' => 'affecte']);
        // C2 — entrant HIER, pour tester le delta "aujourd'hui vs hier".
        $c2 = $this->courrier($service, ['sens' => 'entrant', 'date_mouvement' => today()->subDay(), 'statut' => 'affecte']);
        // C3 — sortant aujourd'hui.
        $c3 = $this->courrier($service, ['sens' => 'sortant', 'date_mouvement' => today(), 'statut' => 'affecte']);
        // C4 à C6 — reçus il y a 3 jours (hors du champ "aujourd'hui vs hier"
        // de la carte "Courrier entrant" ci-dessous, pour isoler ce constat
        // à C1/C2 uniquement).
        $ilYA3Jours = today()->subDays(3);
        // C4 — urgent, actif.
        $c4 = $this->courrier($service, ['date_mouvement' => $ilYA3Jours, 'statut' => 'en_traitement', 'priorite' => 'urgente']);
        Affectation::create(['courrier_id' => $c4->id, 'user_id' => $collaborateur->id, 'affecte_par_id' => $responsable->id]);
        // C5 — retard "classique" : date_limite fixée à hier (toute la journée
        // dépassée). Mise à jour SÉPARÉE de la création : Courrier::booted()
        // recalcule automatiquement date_limite dès que echeance/sla_jours/
        // type_document/date_mouvement est "dirty" (donc À LA CRÉATION,
        // systématiquement) — la passer directement à create() serait
        // silencieusement écrasée par ce recalcul SLA.
        $c5 = $this->courrier($service, ['date_mouvement' => $ilYA3Jours, 'statut' => 'affecte']);
        $c5->update(['date_limite' => today()->subDay()]);
        // C6 — délai fixé par le responsable, échéance il y a 3 h (AUJOURD'HUI) :
        // le chronomètre affiché sur la fiche/le tableau de bord doit déjà être
        // "en retard", mais date_limite (recalculée à la journée) reste "aujourd'hui".
        $c6 = $this->courrier($service, ['date_mouvement' => $ilYA3Jours, 'statut' => 'affecte']);
        Affectation::create(['courrier_id' => $c6->id, 'user_id' => $collaborateur->id, 'affecte_par_id' => $responsable->id]);
        $workflow->fixerDelai($c6, -180, $responsable);
        // C7 — clôturé NORMALEMENT (validation_acceptee) : doit compter dans le délai moyen.
        $c7 = $this->courrier($service, ['statut' => 'en_validation', 'date_mouvement' => today()->subDays(2)]);
        $workflow->valider($c7, $responsable);
        // C8 — pli CONFIDENTIEL clôturé par son destinataire (confidentiel_remis) :
        // doit-il aussi compter dans le délai moyen ?
        // date_mouvement délibérément TRÈS différente de C7 (2 jours) : si
        // ce pli confidentiel est bien compté dans le délai moyen, la
        // moyenne globale s'en trouve mesurablement déplacée (pas juste une
        // coïncidence de deux courriers au même délai).
        $c8 = Courrier::create([
            'numero_reference' => 'GEC-2026-CONF-'.random_int(1, 999999),
            'sens' => 'entrant',
            'date_mouvement' => today()->subDays(10),
            'objet' => 'Correspondance confidentielle (non ouverte)',
            'type_document' => 'Correspondance confidentielle',
            'mode_reception' => 'depot_physique',
            'statut' => 'enregistre',
            'confidentialite' => 3,
            'confidentiel_direct' => true,
            'destinataire_transfert_id' => $dga->id,
        ]);
        CourrierHistorique::create(['courrier_id' => $c8->id, 'auteur_id' => $agent->id, 'action' => 'creation']);
        $dga->update(['niveau_confidentialite' => 3]);
        $workflow->cloturerConfidentiel($c8, $dga);

        $this->ok("C1 {$c1->numero_reference} : entrant, aujourd'hui, actif.");
        $this->ok("C2 {$c2->numero_reference} : entrant, hier, actif.");
        $this->ok("C3 {$c3->numero_reference} : sortant, aujourd'hui, actif.");
        $this->ok("C4 {$c4->numero_reference} : urgent, actif, affecté à {$collaborateur->name}.");
        $this->ok("C5 {$c5->numero_reference} : date_limite = hier (retard \"classique\", jour entier dépassé).");
        $this->ok("C6 {$c6->numero_reference} : délai fixé par le responsable, échéance il y a 3 h (aujourd'hui).");
        $this->ok("C7 {$c7->numero_reference} : traité via validation_acceptee (circuit normal).");
        $this->ok("C8 {$c8->numero_reference} : pli confidentiel traité via confidentiel_remis (destinataire : DGA).");

        (new RefreshDashboardStatsJob)->handle();

        $this->section('Constat n°1 (CORRIGÉ) — tri de "Tâches du jour"');
        // Trouvé : Dashboard::tachesDuJour() ajoutait ->orderBy('chrono_fin_le')
        // APRÈS ->limit(5) dans la chaîne — Laravel l'acceptait sans erreur
        // (LIMIT ne dépend pas de la position de orderBy() dans la chaîne
        // d'appels, juste de l'accumulation des clauses), mais chrono_fin_le
        // devenait de fait la clé de tri la MOINS prioritaire, sans effet
        // réel — contrairement à l'intention du commentaire ("triées par
        // ÉCHÉANCE"). Corrigé : déplacé avant limit(), comme tie-break entre
        // date_limite et date_mouvement. Assertion en dur (verrouille le
        // correctif) plutôt qu'un simple constat.
        $source = file_get_contents(app_path('Livewire/Backend/Dashboard.php'));
        $posLimit = strpos($source, '->limit(5)');
        $posChronoOrderBy = strpos($source, "->orderBy('chrono_fin_le')");
        $this->assertNotFalse($posChronoOrderBy);
        $this->assertLessThan($posLimit, $posChronoOrderBy, "orderBy('chrono_fin_le') doit précéder limit(5), sinon il n'a aucun effet.");
        $this->ok('orderBy(chrono_fin_le) précède bien limit(5) — corrigé et verrouillé par ce test.');

        $this->section('Constat n°2 (CORRIGÉ) — "En retard" est désormais cohérent avec le chronomètre');
        // Trouvé : le badge chronomètre de C6 (délai fixé par le
        // responsable, échéance il y a 3 h) affichait "En retard", mais
        // Courrier::scopeEnRetard() — seule source de la carte KPI "En
        // retard" ET de SendMailAlertJob (Module 7) — comparait
        // whereDate('date_limite', '<', today()) : date_limite reste
        // "aujourd'hui" (recalculée à la JOURNÉE près), donc C6 n'était PAS
        // compté avant le lendemain minuit, contredisant son propre
        // chronomètre sur la même page. Corrigé ("fixed all as you see
        // fit", 2026-09-24, voir DECISIONS.md) : scopeEnRetard() compare
        // désormais chrono_fin_le à la minute près quand il est fixé —
        // STRICTEMENT AUCUN changement pour un courrier sans délai fixé
        // (C5, ci-dessous), où l'ancienne comparaison à la journée équivaut
        // déjà exactement au nouveau calcul.
        $this->actingAs($responsable);
        $enRetardKpi = Livewire::test(Dashboard::class)->get('courriersEnRetard');
        $c6EstEnRetardSelonLeChrono = $c6->fresh()->chronoFin()->isPast();
        $c6CompteDansLeKpi = Courrier::query()->enRetard()->whereKey($c6->id)->exists();
        $c5CompteToujoursDansLeKpi = Courrier::query()->enRetard()->whereKey($c5->id)->exists();

        $this->assertTrue($c6EstEnRetardSelonLeChrono, 'C6 doit être en retard selon son chronomètre (échéance il y a 3 h).');
        $this->assertSame($c6EstEnRetardSelonLeChrono, $c6CompteDansLeKpi, 'La carte "En retard" doit désormais compter C6, comme son chronomètre.');
        $this->assertTrue($c5CompteToujoursDansLeKpi, "C5 (retard \"classique\", sans délai fixé par un responsable) doit toujours compter — comportement inchangé.");
        $this->ok("Carte \"En retard\" ({$enRetardKpi}) : compte désormais C5 (retard classique) ET C6 (chronomètre dépassé) — cohérent avec le badge affiché sur chaque fiche.");

        $this->section('Constat n°3 (CORRIGÉ) — "Délai moyen de traitement" ignorait les plis confidentiels clôturés');
        // Trouvé : RefreshDashboardStatsJob::handle() ne lisait QUE l'action
        // 'validation_acceptee' — le pli confidentiel C8, clôturé via
        // WorkflowService::cloturerConfidentiel() (action 'confidentiel_remis'),
        // n'était JAMAIS compté alors qu'il est bien 'traité'. Corrigé :
        // whereIn(['validation_acceptee', 'confidentiel_remis']). C7 (2 j)
        // et C8 (10 j) ont des délais délibérément différents : si les DEUX
        // sont comptés, la moyenne globale (Administrateur, "Voir tous les
        // courriers") est mesurablement 6.0 j — pas une coïncidence.
        $this->actingAs($admin);
        $delaiAdmin = Livewire::test(Dashboard::class)->get('delaiMoyen');

        $this->assertSame(6.0, $delaiAdmin['jours'], 'Le pli confidentiel C8 (10 j) doit être compté aux côtés de C7 (2 j) : moyenne (2+10)/2 = 6.0.');
        $this->ok('Délai moyen (Administrateur, tous services) : '.$delaiAdmin['jours'].' jour(s) — (2+10)/2, les deux voies de clôture comptent désormais.');

        // Rappel du périmètre "par service" (comportement volontaire,
        // inchangé) : un pli confidentiel (service_id=null) n'est visible
        // que pour "Voir tous les courriers" (Administrateur) — jamais pour
        // un Responsable de service, scopé à SES services.
        $this->actingAs($responsable);
        $delaiResponsable = Livewire::test(Dashboard::class)->get('delaiMoyen');
        $this->verifier(
            $delaiResponsable['jours'] === 2.0,
            'Responsable de service : délai moyen = '.$delaiResponsable['jours']." j (C7 seul — C8 n'a pas de service, comportement attendu, pas une omission).",
            'Responsable de service : délai moyen = '.($delaiResponsable['jours'] ?? '—')." j — attendu 2.0 (C7 seul, périmètre de service).",
        );

        $this->section('Constat n°4 — contenu de la carte "Notifications" (Module 7 construit le 2026-10-05)');
        $this->actingAs($responsable);
        $html = Livewire::test(Dashboard::class)->html();
        $this->verifier(
            ! str_contains($html, 'Bientôt disponible'),
            'Carte "Notifications" : n\'affiche plus le placeholder figé — Module 7 (centre de notifications) construit le 2026-10-05, la carte montre désormais un vrai aperçu (ou l\'état vide honnête "Aucune notification pour l\'instant.") au lieu d\'un texte statique.',
            'Carte "Notifications" affiche encore "Bientôt disponible" — Dashboard.php::notificationsRecentes()/dashboard.blade.php à revérifier.',
        );

        $this->section('Vérification — le reste du tableau de bord correspond bien à la base');
        $this->actingAs($admin);
        $composant = Livewire::test(Dashboard::class);

        $entrant = $composant->get('courrierEntrantAujourdhui');
        $this->verifier(
            $entrant['total'] === 1 && $entrant['delta'] === 0,
            "Courrier entrant aujourd'hui : total={$entrant['total']}, delta={$entrant['delta']} (1 aujourd'hui [C1], 1 hier [C2] → delta 0) — correct.",
            "Courrier entrant aujourd'hui : total={$entrant['total']}, delta={$entrant['delta']} — attendu total=1, delta=0.",
        );

        $sortant = $composant->get('courrierSortantAujourdhui');
        $this->verifier(
            $sortant['total'] === 1,
            "Courrier sortant aujourd'hui : total={$sortant['total']} — correct.",
            "Courrier sortant aujourd'hui : total={$sortant['total']} — attendu 1.",
        );

        $urgents = $composant->get('courriersUrgents');
        $this->verifier(
            $urgents === 1,
            "Courriers urgents : {$urgents} — correct (C4 seul, actif + urgente).",
            "Courriers urgents : {$urgents} — attendu 1.",
        );

        $enAttente = $composant->get('enAttenteDeTraitement');
        // C1, C2, C3, C4, C5, C6 sont actifs (7 = tous sauf C7/C8, clôturés).
        $this->verifier(
            $enAttente === 6,
            "En attente de traitement : {$enAttente} — correct (6 courriers actifs sur les 8 simulés).",
            "En attente de traitement : {$enAttente} — attendu 6.",
        );

        $derniers = $composant->get('derniersCourriers');
        $this->verifier(
            $derniers->pluck('numero_reference')->contains($c8->numero_reference),
            'Le pli confidentiel C8 apparaît dans "Derniers courriers enregistrés" pour un Administrateur — cohérent avec son niveau d\'accès.',
            'Le pli confidentiel C8 est absent de "Derniers courriers enregistrés" pour un Administrateur — inattendu.',
        );

        // Un Agent n'a pas de périmètre de service → pas de carte délai moyen.
        $this->actingAs($agent);
        $this->verifier(
            Livewire::test(Dashboard::class)->get('delaiMoyen') === null,
            'Agent : carte "Délai moyen" absente (aucun périmètre de service) — correct.',
            'Agent : carte "Délai moyen" présente alors qu\'un Agent n\'a aucun périmètre de service à moyenner.',
        );
    }

    private function compte(string $profil, ?Service $service = null): User
    {
        return User::factory()->create([
            'profil_id' => Profil::firstOrCreate(['nom' => $profil])->id,
            'service_id' => $service?->id,
        ]);
    }

    private function courrier(?Service $service, array $attributs = []): Courrier
    {
        return Courrier::create(array_merge([
            'numero_reference' => 'GEC-'.now()->year.'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'sens' => 'entrant',
            'date_mouvement' => now(),
            'objet' => 'Courrier de simulation',
            'type_document' => 'Lettre',
            'mode_reception' => 'email',
            'priorite' => 'normale',
            'confidentialite' => 1,
            'statut' => 'enregistre',
            'service_id' => $service?->id,
        ], $attributs));
    }

    private function section(string $titre): void
    {
        $this->journal[] = "\n## {$titre}\n";
    }

    private function ok(string $message): void
    {
        $this->journal[] = "- ✅ {$message}";
    }

    private function verifier(bool $condition, string $succes, string $probleme): void
    {
        $this->journal[] = $condition ? "- ✅ {$succes}" : "- ⚠️ {$probleme}";
    }
}
