<?php

namespace App\Jobs;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\OrganizationUnit;
use App\Models\Parametre;
use App\Models\User;
use App\Notifications\CourrierEnRetardNotification;
use App\Services\WorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

// Module 7 — alertes et relances automatiques (déclenché par le Scheduler,
// voir bootstrap/app.php et DECISIONS.md "SLA et alertes") :
//   - "bientôt en retard" : une fois par date limite, quand il reste au plus
//     Parametre::actuel()->sla_seuil_risque_jours jours ;
//   - "en retard" : dès le dépassement, puis relance au plus tous les
//     Parametre::actuel()->sla_relance_jours jours tant que le courrier
//     reste actif. Délais éditables depuis "Paramètres système" (2026-09-23,
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
        $relance = now()->subDays($parametres->sla_relance_jours);

        $this->traiter(
            Courrier::query()
                ->enRetard()
                ->where(fn ($q) => $q->whereNull('alerte_retard_le')->orWhere('alerte_retard_le', '<=', $relance)),
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

        if ($destinataires->isNotEmpty()) {
            Notification::send($destinataires, new CourrierEnRetardNotification($courrier, $enRetard));
        }

        // saveQuietly : ne pas redéclencher le recalcul de date limite (qui
        // remettrait ces marqueurs à null) — seule la trace d'envoi change.
        $courrier->forceFill([$enRetard ? 'alerte_retard_le' : 'alerte_risque_le' => now()])->saveQuietly();

        // Règle n°5 — l'alerte est une action du système sur le courrier.
        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => null,
            'action' => $enRetard ? 'alerte_retard' : 'alerte_risque',
            'commentaire' => $destinataires->isEmpty()
                ? 'Alerte SLA : aucun destinataire autorisé trouvé.'
                : 'Alerte SLA envoyée à '.$destinataires->pluck('name')->implode(', '),
        ]);
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
            $courrier->statut === 'en_cours_de_transfert' ? $courrier->destinataireTransfert : null,
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
            'commentaire' => $destinataires->isEmpty()
                ? 'Alerte SLA (escalade) : aucun destinataire autorisé trouvé.'
                : 'Alerte SLA (escalade) envoyée à '.$destinataires->pluck('name')->implode(', '),
        ]);
    }

    // Destinataires du palier "en retard" + le responsable du NIVEAU
    // HIÉRARCHIQUE SUPÉRIEUR à celui du service, si résolvable.
    private function destinatairesEscalade(Courrier $courrier): Collection
    {
        $destinataires = $this->destinataires($courrier, enRetard: true);

        $superieur = $this->responsableNiveauSuperieur($courrier);

        if ($superieur !== null && $superieur->can('view', $courrier)) {
            $destinataires = $destinataires->push($superieur)->unique('id')->values();
        }

        return $destinataires;
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
