<?php

namespace App\Jobs;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Parametre;
use App\Models\User;
use App\Notifications\CourrierEnRetardNotification;
use App\Services\NotificationPreferenceService;
use App\Services\WorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

// Module 7 — alertes et relances automatiques (déclenché par le Scheduler,
// voir bootstrap/app.php et DECISIONS.md "SLA et alertes") :
//   - "bientôt en retard" : une fois par date limite, quand il reste au plus
//     Parametre::actuel()->sla_seuil_risque_jours jours ;
//   - "en retard" : dès le dépassement, puis relance tant que le courrier
//     reste actif, à l'intervalle de chaque destinataire
//     (User::frequenceRelanceSlaHeures(), sinon Parametre::actuel()->
//     sla_relance_jours de l'administrateur). Délais éditables depuis "Paramètres système" (2026-09-23,
//     remplace config/gec.php), pas de redéploiement nécessaire pour les changer.
// Règle n°1 : Job planifié, jamais une vérification au chargement d'une page.
class SendMailAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(): void
    {
        $parametres = Parametre::actuel();
        $aujourdhui = today();

        $this->traiter(
            Courrier::query()->enRetard(),
            enRetard: true,
        );

        $this->traiter(
            Courrier::query()
                ->whereIn('statut', WorkflowService::statutsActifs())
                ->whereNull('alerte_risque_le')
                ->whereDate('date_limite', '>=', $aujourdhui)
                ->whereDate('date_limite', '<=', $aujourdhui->copy()->addDays($parametres->sla_seuil_risque_jours)),
            enRetard: false,
        );

        // 2e palier — escalade (2026-10-05, Module 7, "l'escalade vers le
        // responsable hiérarchique doit être configurable selon le niveau
        // de retard") : une seule fois par date limite (alerte_escalade_le),
        // jamais répétée comme la relance "en retard" ci-dessus — sinon le
        // niveau supérieur recevrait autant de relances que le collaborateur
        // initial, ce qui n'est pas le but d'une escalade ponctuelle.
        // sla_escalade_jours nullable = fonctionnalité désactivée (défaut).
        if ($parametres->sla_escalade_jours !== null) {
            $this->traiterEscalade(
                Courrier::query()
                    ->enRetard()
                    ->whereNull('alerte_escalade_le')
                    ->whereDate('date_limite', '<=', $aujourdhui->copy()->subDays($parametres->sla_escalade_jours)),
            );
        }
    }

    private function traiter($requete, bool $enRetard): void
    {
        $requete
            ->with(['service.responsable', 'affectationCourante.collaborateur', 'destinataireTransfert'])
            ->chunkById(100, function ($courriers) use ($enRetard) {
                foreach ($courriers as $courrier) {
                    try {
                        $this->alerter($courrier, $enRetard);
                    } catch (Throwable $e) {
                        // Un courrier en échec (SMTP indisponible...) ne doit
                        // jamais bloquer les autres ; non marqué comme alerté,
                        // il sera repris au prochain passage du Scheduler.
                        report($e);
                        Log::error('Échec de l\'alerte SLA', [
                            'courrier_id' => $courrier->id,
                            'numero_reference' => $courrier->numero_reference,
                            'en_retard' => $enRetard,
                            'erreur' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function alerter(Courrier $courrier, bool $enRetard): void
    {
        $destinataires = $this->destinataires($courrier, $enRetard);

        if ($enRetard) {
            $destinataires = $this->destinatairesRelanceDue($courrier, $destinataires);
        }

        if ($destinataires->isNotEmpty()) {
            Notification::send($destinataires, new CourrierEnRetardNotification($courrier, $enRetard));

            if ($enRetard) {
                $this->marquerRelancesEnvoyees($courrier, $destinataires);
            }

            $courrier->forceFill([$enRetard ? 'alerte_retard_le' : 'alerte_risque_le' => now()])->saveQuietly();

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => null,
                'action' => $enRetard ? 'alerte_retard' : 'alerte_risque',
                'commentaire' => 'Alerte SLA envoyée à '.$destinataires->pluck('name')->implode(', '),
            ]);

            return;
        }

        // Une seule trace pour un courrier sans destinataire autorisé ; le
        // marqueur n'empêche pas un futur destinataire activé de recevoir sa
        // relance, car l'anti-doublon est désormais suivi par utilisateur.
        if ($enRetard && $courrier->alerte_retard_le !== null) {
            return;
        }

        // saveQuietly : ne pas redéclencher le recalcul de date limite, qui
        // réarme les alertes.
        $courrier->forceFill([$enRetard ? 'alerte_retard_le' : 'alerte_risque_le' => now()])->saveQuietly();

        // Règle n°5 — l'alerte est une action du système sur le courrier.
        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => null,
            'action' => $enRetard ? 'alerte_retard' : 'alerte_risque',
            'commentaire' => 'Alerte SLA : aucun destinataire autorisé trouvé.',
        ]);
    }

    private function destinatairesRelanceDue(Courrier $courrier, Collection $candidats): Collection
    {
        $preference = app(NotificationPreferenceService::class);
        $destinataires = $candidats
            ->filter(fn (User $user) => $preference->peutNotifier($user, 'alertes_sla'))
            ->values();

        if ($destinataires->isEmpty()) {
            return $destinataires;
        }

        $derniereRelanceParUtilisateur = DB::table('courrier_alerte_utilisateurs')
            ->where('courrier_id', $courrier->id)
            ->whereIn('user_id', $destinataires->pluck('id'))
            ->get()
            ->keyBy('user_id');
        $dateLimite = $courrier->date_limite->toDateString();

        return $destinataires->filter(function (User $user) use ($derniereRelanceParUtilisateur, $dateLimite) {
            $derniereRelance = $derniereRelanceParUtilisateur->get($user->id);

            // Intervalle en heures (2026-11-11), compté depuis le DÉBUT de
            // l'heure du dernier envoi : le job passe chaque heure à quelques
            // secondes près — envoyé à 10:00:03, une relance de 24 h n'était
            // pas due à 10:00:01 le lendemain et glissait d'une heure à
            // chaque envoi.
            return $derniereRelance === null
                || $derniereRelance->date_limite !== $dateLimite
                || Carbon::parse($derniereRelance->notifie_le)
                    ->startOfHour()
                    ->addHours($user->frequenceRelanceSlaHeures())
                    ->lte(now());
        })->values();
    }

    private function marquerRelancesEnvoyees(Courrier $courrier, Collection $destinataires): void
    {
        $dateLimite = $courrier->date_limite->toDateString();
        $notifieLe = now();

        foreach ($destinataires as $destinataire) {
            DB::table('courrier_alerte_utilisateurs')->updateOrInsert(
                ['courrier_id' => $courrier->id, 'user_id' => $destinataire->id],
                [
                    'date_limite' => $dateLimite,
                    'notifie_le' => $notifieLe,
                    'created_at' => $notifieLe,
                    'updated_at' => $notifieLe,
                ],
            );
        }
    }

    // "Les bonnes personnes" (PRD §1.6) : qui doit agir maintenant selon
    // l'étape du circuit, plus le responsable du service en cas de retard.
    // Filtré par CourrierPolicy::view() — jamais d'alerte (et donc de lien)
    // vers quelqu'un qui n'a pas le droit de voir ce courrier.
    private function destinataires(Courrier $courrier, bool $enRetard): Collection
    {
        $collaborateur = $courrier->affectationCourante?->collaborateur;
        $responsable = $courrier->service?->responsable;

        $candidats = collect([
            $collaborateur,
            ($enRetard || ! $collaborateur) ? $responsable : null,
            $courrier->statut === 'en_attente_de_transfert' ? $this->createur($courrier) : null,
        ]);

        return $candidats
            ->filter()
            ->unique('id')
            ->filter(fn (User $user) => $user->can('view', $courrier))
            ->values();
    }

    private function createur(Courrier $courrier): ?User
    {
        return $courrier->historiques()->where('action', 'creation')->first()?->auteur;
    }

    private function traiterEscalade($requete): void
    {
        $requete
            ->with(['service.responsable', 'affectationCourante.collaborateur', 'destinataireTransfert'])
            ->chunkById(100, function ($courriers) {
                foreach ($courriers as $courrier) {
                    try {
                        $this->alerterEscalade($courrier);
                    } catch (Throwable $e) {
                        report($e);
                        Log::error('Échec de l\'alerte SLA (escalade)', [
                            'courrier_id' => $courrier->id,
                            'numero_reference' => $courrier->numero_reference,
                            'erreur' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    private function alerterEscalade(Courrier $courrier): void
    {
        $destinataires = $this->destinatairesEscalade($courrier);

        if ($destinataires->isNotEmpty()) {
            Notification::send($destinataires, new CourrierEnRetardNotification($courrier, enRetard: true, escalade: true));
        }

        $courrier->forceFill(['alerte_escalade_le' => now()])->saveQuietly();

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => null,
            'action' => 'alerte_escalade',
            'commentaire' => match (true) {
                $destinataires->isNotEmpty() => 'Alerte SLA (escalade) envoyée à '.$destinataires->pluck('name')->implode(', '),
                $this->responsableTraiteLuiMeme($courrier) => 'Alerte SLA (escalade) : le responsable traite lui-même ce courrier et aucun niveau supérieur n\'est relié dans l\'Organisation.',
                default => 'Alerte SLA (escalade) : aucun destinataire autorisé trouvé.',
            },
        ]);
    }

    // Destinataires du palier "en retard" + le responsable du NIVEAU
    // HIÉRARCHIQUE SUPÉRIEUR à celui du service, si résolvable.
    private function destinatairesEscalade(Courrier $courrier): Collection
    {
        $destinataires = $this->destinataires($courrier, enRetard: true);

        // 2026-11-10 (« if it is the responsible doing the traitement some of
        // the workflow process should not send notif ») — le responsable qui
        // traite lui-même le courrier reçoit déjà ses relances « en retard » ;
        // l'escalade prévient sa hiérarchie, elle ne lui est pas adressée.
        if ($this->responsableTraiteLuiMeme($courrier)) {
            $destinataires = $destinataires
                ->reject(fn (User $user) => $user->id === $courrier->service->responsable_id)
                ->values();
        }

        $superieur = $this->responsableNiveauSuperieur($courrier);

        if ($superieur !== null && $superieur->can('view', $courrier)) {
            $destinataires = $destinataires->push($superieur)->unique('id')->values();
        }

        return $destinataires;
    }

    private function responsableTraiteLuiMeme(Courrier $courrier): bool
    {
        $responsableId = $courrier->service?->responsable_id;

        return $responsableId !== null && $courrier->affectationCourante?->user_id === $responsableId;
    }

    // Remonte l'organigramme (App\Models\OrganizationUnit, voir "Organisation"
    // v2) depuis le nœud ponté au service du courrier, jusqu'au premier
    // ancêtre dont le responsable diffère de celui du service lui-même.
    // Retourne null sans erreur si le service n'est pas encore ponté à
    // l'organigramme (la majorité des services à ce jour, voir
    // OrganizationUnit::departementLabelParServiceId()) — l'escalade se
    // comporte alors comme les paliers précédents (collaborateur + responsable
    // de service seulement) plutôt que d'échouer ou d'inventer un destinataire.
    private function responsableNiveauSuperieur(Courrier $courrier): ?User
    {
        if ($courrier->service_id === null) {
            return null;
        }

        $noeud = OrganizationUnit::where('service_id', $courrier->service_id)->first();

        if ($noeud === null) {
            return null;
        }

        $responsableService = $courrier->service?->responsable_id;
        $courant = $noeud;
        $profondeur = 0;

        while ($courant?->parent_id !== null && $profondeur < 10) {
            $courant = OrganizationUnit::find($courant->parent_id);
            $profondeur++;

            if ($courant?->responsible_user_id !== null && $courant->responsible_user_id !== $responsableService) {
                return $courant->responsable;
            }
        }

        return null;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du job SendMailAlertJob', [
            'erreur' => $exception?->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
