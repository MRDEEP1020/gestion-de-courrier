<?php

namespace App\Livewire\Backend;

use App\Jobs\RefreshDashboardStatsJob;
use App\Models\Courrier;
use App\Models\Service;
use App\Services\WorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// Module 10 — tableau de bord (specifications-modules-GEC.md, maquette GPT
// fournie par l'utilisateur le 2026-09-16) : UNE seule mise en page pour
// tous les profils — le contenu (cartes, actions rapides, tableau) est
// filtré par privilège dans la vue (même principe que la sidebar, @can),
// pas un tableau de bord différent codé en dur par profil.
#[Title('Tableau de bord')]
class Dashboard extends Component
{
    // dashboard.voir (2026-09-23, menus pilotés par privilège). Page
    // d'atterrissage après connexion (Fortify "home") : sans le privilège,
    // redirection vers les paramètres du compte (toujours accessibles)
    // plutôt qu'un 403 dès la connexion.
    public function mount(): void
    {
        if (! Auth::user()->hasPrivilege('dashboard.voir')) {
            $this->redirectRoute('profile.edit', navigate: true);
        }
    }

    // Même périmètre que CourrierPolicy::view() : ce que l'utilisateur
    // pourrait ouvrir individuellement, appliqué ici au niveau requête pour
    // les statistiques et la liste (Règle n°3 — pas de ::all(), pas de N+1).
    // 2026-09-23 — Courrier::scopeVisiblePar() au lieu d'une copie locale :
    // la copie ignorait le niveau de confidentialité, l'accès dossier et le
    // périmètre organisationnel (courriers confidentiels listés ici alors
    // que leur fiche était refusée).
    private function courriersVisibles(): Builder
    {
        return Courrier::query()->visiblePar(Auth::user());
    }

    // "Chaque action / lecture = un privilège" (2026-09-23) : chaque
    // carte/KPI du tableau de bord a désormais SA PROPRE clé — demande
    // explicite de l'utilisateur ("on tableau de board all kpi and card
    // there should be permission"), remplace l'ancienne dashboard.statistiques
    // partagée par les 5 cartes. Chaque computed retourne `null` sans son
    // privilège (revérifié ici, pas seulement masqué dans la vue — même
    // principe que delaiMoyen()/derniersCourriers() ci-dessous), la vue
    // teste `!== null` pour afficher ou non la carte.
    #[Computed]
    public function courrierEntrantAujourdhui(): ?array
    {
        return Auth::user()->hasPrivilege('dashboard.courrier_entrant')
            ? $this->compterAujourdhuiEtHier('entrant')
            : null;
    }

    #[Computed]
    public function courrierSortantAujourdhui(): ?array
    {
        return Auth::user()->hasPrivilege('dashboard.courrier_sortant')
            ? $this->compterAujourdhuiEtHier('sortant')
            : null;
    }

    // Delta en valeur absolue (pas en %) — plus simple et robuste qu'un
    // pourcentage (évite une division par zéro quand "hier" vaut 0, cas
    // fréquent sur un petit volume pilote).
    private function compterAujourdhuiEtHier(string $sens): array
    {
        $aujourdhui = $this->courriersVisibles()->where('sens', $sens)->whereDate('date_mouvement', today())->count();
        $hier = $this->courriersVisibles()->where('sens', $sens)->whereDate('date_mouvement', today()->subDay())->count();

        return ['total' => $aujourdhui, 'delta' => $aujourdhui - $hier];
    }

    #[Computed]
    public function enAttenteDeTraitement(): ?int
    {
        if (! Auth::user()->hasPrivilege('dashboard.en_attente')) {
            return null;
        }

        return $this->courriersVisibles()->whereIn('statut', WorkflowService::statutsActifs())->count();
    }

    #[Computed]
    public function courriersUrgents(): ?int
    {
        if (! Auth::user()->hasPrivilege('dashboard.urgents')) {
            return null;
        }

        return $this->courriersVisibles()
            ->whereIn('statut', WorkflowService::statutsActifs())
            ->where('priorite', 'urgente')
            ->count();
    }

    // Module 5/10 — "retards" (PRD §3.10) : courriers actifs dont la date
    // limite SLA est dépassée (Courrier::scopeEnRetard(), colonne indexée).
    #[Computed]
    public function courriersEnRetard(): ?int
    {
        if (! Auth::user()->hasPrivilege('dashboard.en_retard')) {
            return null;
        }

        return $this->courriersVisibles()->enRetard()->count();
    }

    // Module 10 (2026-10-09, demande explicite de l'utilisateur) — courriers
    // encore au tout premier statut du circuit (Courrier::scopeNonTransfere()),
    // jamais affectés/transférés depuis leur enregistrement.
    #[Computed]
    public function courriersNonTransferes(): ?int
    {
        if (! Auth::user()->hasPrivilege('dashboard.non_transferes')) {
            return null;
        }

        return $this->courriersVisibles()->nonTransfere()->count();
    }

    // Cartes "Notifications"/"Calendrier" du panneau latéral (2026-09-23) —
    // gouvernées comme toutes les autres.
    #[Computed]
    public function peutVoirNotifications(): bool
    {
        return Auth::user()->hasPrivilege('dashboard.notifications');
    }

    // 2026-10-05 (Module 7, retour utilisateur sur la carte vide "Bientôt
    // disponible.") — le centre de notifications existe désormais
    // (NotificationBell/NotificationsIndex), cette carte peut donc afficher
    // un VRAI aperçu au lieu du texte figé. `dashboard.notifications` ne
    // gouverne QUE la visibilité de la carte elle-même ; la donnée reste
    // toujours celle (et SEULEMENT celle) de l'utilisateur connecté, même
    // principe que la cloche de l'en-tête — aucune policy séparée requise.
    #[Computed]
    public function notificationsRecentes()
    {
        if (! $this->peutVoirNotifications) {
            return null;
        }

        return Auth::user()->notifications()->latest()->take(3)->get();
    }

    // Module 10 — "délai moyen" (PRD §3.10), pré-calculé par
    // RefreshDashboardStatsJob (Règle n°1 : jamais agrégé ici). Périmètre :
    // toute l'entreprise pour courriers.voir_tout, les services dont on est
    // responsable pour courriers.voir_service ; null = carte non affichée
    // (un agent/collaborateur n'a pas de périmètre de service à moyenner).
    #[Computed]
    public function delaiMoyen(): ?array
    {
        $user = Auth::user();

        if (! $user->hasPrivilege('dashboard.delai_moyen')) {
            return null;
        }

        if ($user->hasPrivilege('courriers.voir_tout')) {
            $serviceIds = null;
        } elseif ($user->hasPrivilege('courriers.voir_service')) {
            $serviceIds = Service::where('responsable_id', $user->id)->pluck('id')->all();
        } else {
            return null;
        }

        $stats = Cache::get(RefreshDashboardStatsJob::CLE_CACHE);

        if ($stats === null) {
            // Premier affichage (ou cache vidé) avant le passage du Scheduler :
            // un seul recalcul demandé toutes les 10 minutes, jamais à chaque
            // chargement de page.
            if (Cache::add(RefreshDashboardStatsJob::CLE_CACHE.'.demande', true, 600)) {
                RefreshDashboardStatsJob::dispatch();
            }

            return ['jours' => null, 'en_calcul' => true];
        }

        return ['jours' => RefreshDashboardStatsJob::delaiMoyen($stats, $serviceIds), 'en_calcul' => false];
    }

    #[Computed]
    public function derniersCourriers()
    {
        if (! Auth::user()->hasPrivilege('dashboard.derniers_courriers')) {
            return null;
        }

        return $this->courriersVisibles()
            ->with('service')
            ->latest('date_mouvement')
            ->limit(8)
            ->get();
    }

    // Module 10 — "Tâches du jour" (demande explicite de l'utilisateur,
    // 2026-09-16) : PAS une liste de tâches inventée/manuelle — les courriers
    // encore en circuit dans le périmètre de CET utilisateur, limités à 5.
    // Depuis la suppression de la page "Transferts" (WorkflowQueue,
    // 2026-09-24), c'est ici que vit la notion de "à traiter" :
    // visiblePar($user, false) exclut les courriers qu'une DGA ne fait
    // plus que suivre (voir Courrier::scopeVisiblePar()).
    // Gardé derrière dashboard.taches_du_jour (voir PrivilegeSeeder) :
    // "mainly for collaborateur ... sometimes responsable service" —
    // n'est donc PAS calculé pour un utilisateur qui n'a pas ce privilège.
    #[Computed]
    public function tachesDuJour()
    {
        if (! Auth::user()->hasPrivilege('dashboard.taches_du_jour')) {
            return null;
        }

        // 2026-09-24 ("customize the tâche du jour") : triées par ÉCHÉANCE
        // (date limite SLA, colonne indexée — les retards remontent en
        // premier), puis par l'échéance précise du chronomètre (départage
        // deux courriers dus le même jour — celui dont le délai a été fixé
        // par le responsable pour 9h du matin passe avant celui fixé pour
        // 17h), puis les plus anciennes ; sans date limite en dernier.
        // ATTENTION — un ->orderBy('chrono_fin_le') avait été ajouté APRÈS
        // ->limit(5) (audit du 2026-09-24) : Laravel l'acceptait sans erreur
        // (LIMIT ne dépend pas de l'ordre des appels de orderBy() dans la
        // chaîne) mais le critère devenait le MOINS prioritaire, sans effet
        // réel — toujours placer tout orderBy()/orderByRaw() AVANT limit().
        return $this->courriersATraiter()
            ->with('service')
            ->orderByRaw('date_limite is null')
            ->orderBy('date_limite')
            ->orderByRaw('chrono_fin_le is null')
            ->orderBy('chrono_fin_le')
            ->oldest('date_mouvement')
            ->limit(5)
            ->get(['id', 'numero_reference', 'objet', 'statut', 'priorite', 'date_limite', 'date_mouvement', 'service_id', 'chrono_debut_le', 'chrono_fin_le', 'chrono_arrete_le']);
    }

    // Compteur de l'en-tête "Tâches du jour" : total réel de la file (la
    // carte n'en montre que 5).
    #[Computed]
    public function totalTachesDuJour(): int
    {
        if (! Auth::user()->hasPrivilege('dashboard.taches_du_jour')) {
            return 0;
        }

        return $this->courriersATraiter()->count();
    }

    private function courriersATraiter(): Builder
    {
        return Courrier::query()
            ->visiblePar(Auth::user(), false)
            ->whereIn('statut', WorkflowService::statutsActifs());
    }

    public function render()
    {
        return view('frontend::dashboard');
    }
}
