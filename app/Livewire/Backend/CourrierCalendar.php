<?php

namespace App\Livewire\Backend;

use App\Models\Courrier;
use App\Services\SlaCalculatorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

// Vue "Calendrier" des échéances SLA (2026-10-05, demande utilisateur,
// capture Outlook fournie comme référence visuelle) : pas un module du PRD,
// un complément au Module 5 (suivi SLA). `date_limite` est une colonne DATE
// (pas d'heure, voir migration 2026_09_23_140000) — les courriers sont donc
// affichés en puces "toute la journée" sous l'en-tête de chaque colonne,
// jamais dans une grille horaire (qui resterait en permanence vide).
#[Title('Calendrier des échéances')]
class CourrierCalendar extends Component
{
    private const JOURS_FR = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];

    private const MOIS_FR = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    private const PUCES_PAR_JOUR_MAX = 4;

    // Grille horaire : hauteur d'une ligne (1h) et hauteur de la carte d'un
    // événement à heure fixe — doivent rester cohérentes avec les classes
    // Tailwind h-16 (ligne) / h-12 (carte) de courrierCalendar.blade.php.
    private const HAUTEUR_LIGNE_HEURE_PX = 64;

    private const HAUTEUR_CARTE_EVENEMENT_PX = 48;

    // Propriétés primitives uniquement (Règle n°2) : jamais de modèle/collection
    // en propriété publique. Le jour "ancre" pilote la semaine affichée ; le
    // mois affiché du mini-calendrier est indépendant (on peut le feuilleter
    // sans déplacer la semaine courante).
    public string $ancreDate = '';

    public string $moisAffiche = '';

    public function mount(): void
    {
        $this->authorize('calendrier', Courrier::class);

        $this->ancreDate = today()->toDateString();
        $this->moisAffiche = today()->startOfMonth()->toDateString();
    }

    public function semainePrecedente(): void
    {
        $this->ancreDate = Carbon::parse($this->ancreDate)->subWeek()->toDateString();
        $this->synchroniserMoisAffiche();
    }

    public function semaineSuivante(): void
    {
        $this->ancreDate = Carbon::parse($this->ancreDate)->addWeek()->toDateString();
        $this->synchroniserMoisAffiche();
    }

    public function allerAujourdhui(): void
    {
        $this->ancreDate = today()->toDateString();
        $this->moisAffiche = today()->startOfMonth()->toDateString();
    }

    public function moisPrecedent(): void
    {
        $this->moisAffiche = Carbon::parse($this->moisAffiche)->subMonthNoOverflow()->toDateString();
    }

    public function moisSuivant(): void
    {
        $this->moisAffiche = Carbon::parse($this->moisAffiche)->addMonthNoOverflow()->toDateString();
    }

    public function selectionnerJour(string $date): void
    {
        $this->ancreDate = Carbon::parse($date)->toDateString();
        $this->moisAffiche = Carbon::parse($date)->startOfMonth()->toDateString();
    }

    private function synchroniserMoisAffiche(): void
    {
        $mois = Carbon::parse($this->ancreDate)->startOfMonth()->toDateString();

        if ($mois !== $this->moisAffiche) {
            $this->moisAffiche = $mois;
        }
    }

    // Semaine de travail Lun→Ven contenant $ancreDate (même convention que la
    // capture fournie par l'utilisateur).
    #[Computed]
    public function joursSemaine(): array
    {
        $debut = Carbon::parse($this->ancreDate)->startOfWeek(Carbon::MONDAY);

        return collect(range(0, 4))->map(function (int $i) use ($debut) {
            $jour = $debut->copy()->addDays($i);

            return [
                'date' => $jour->toDateString(),
                'numero' => $jour->format('d'),
                'libelle' => self::JOURS_FR[$jour->dayOfWeekIso],
                'estAujourdhui' => $jour->isToday(),
            ];
        })->all();
    }

    #[Computed]
    public function libellePeriode(): string
    {
        $jours = $this->joursSemaine();
        $debut = Carbon::parse($jours[0]['date']);
        $fin = Carbon::parse($jours[4]['date']);

        if ($debut->isSameMonth($fin)) {
            return sprintf('%02d-%02d %s %d', $debut->day, $fin->day, self::MOIS_FR[$debut->month], $debut->year);
        }

        if ($debut->isSameYear($fin)) {
            return sprintf('%02d %s - %02d %s %d', $debut->day, self::MOIS_FR[$debut->month], $fin->day, self::MOIS_FR[$fin->month], $debut->year);
        }

        return sprintf('%02d %s %d - %02d %s %d', $debut->day, self::MOIS_FR[$debut->month], $debut->year, $fin->day, self::MOIS_FR[$fin->month], $fin->year);
    }

    #[Computed]
    public function libelleMoisMini(): string
    {
        $mois = Carbon::parse($this->moisAffiche);

        return self::MOIS_FR[$mois->month].' '.$mois->year;
    }

    // Grille 6 semaines × 7 jours du mini-calendrier, Lun→Dim, jours hors-mois
    // inclus (grisés côté vue).
    #[Computed]
    public function joursMoisMini(): array
    {
        $debutMois = Carbon::parse($this->moisAffiche)->startOfMonth();
        $debutGrille = $debutMois->copy()->startOfWeek(Carbon::MONDAY);
        $datesSemaineAffichee = array_column($this->joursSemaine(), 'date');

        return collect(range(0, 41))
            ->map(function (int $i) use ($debutGrille, $debutMois, $datesSemaineAffichee) {
                $jour = $debutGrille->copy()->addDays($i);

                return [
                    'date' => $jour->toDateString(),
                    'numero' => $jour->day,
                    'horsMois' => ! $jour->isSameMonth($debutMois),
                    'estAujourdhui' => $jour->isToday(),
                    'estDansSemaineAffichee' => in_array($jour->toDateString(), $datesSemaineAffichee, true),
                ];
            })
            ->chunk(7)
            ->values()
            ->all();
    }

    // Une seule requête bornée à la semaine affichée, périmètre identique au
    // Dashboard (Courrier::scopeVisiblePar()) — pas de ::all(), eager loading
    // du service (Règle n°3).
    #[Computed]
    public function courriersParJour(): array
    {
        $jours = $this->joursSemaine();

        $courriers = Courrier::query()
            ->visiblePar(Auth::user())
            ->whereBetween('date_limite', [$jours[0]['date'], $jours[4]['date']])
            ->with(['service', 'affectationCourante.collaborateur', 'affectationCourante.affectePar'])
            ->orderBy('date_limite')
            ->get(['id', 'numero_reference', 'objet', 'expediteur_nom', 'expediteur_organisation', 'statut', 'date_limite', 'chrono_fin_le', 'service_id']);

        $parJour = $courriers->groupBy(fn (Courrier $courrier) => $courrier->date_limite->toDateString());
        $calculateur = new SlaCalculatorService;

        $resultat = [];

        foreach ($jours as $jour) {
            $duJour = $parJour->get($jour['date'], collect());

            // Module 5 (délai précis fixé par le responsable, voir
            // WorkflowService::fixerDelai()) — un courrier avec chrono_fin_le
            // a une VRAIE heure, positionné dans la grille horaire ; les
            // autres (date_limite seule, sans heure) restent dans le bandeau
            // "toute la journée" comme avant (2026-10-05, "ADD TIME TOO").
            [$avecHeure, $sansHeure] = $duJour->partition(fn (Courrier $courrier) => $courrier->chrono_fin_le !== null);

            $resultat[$jour['date']] = [
                'puces' => $sansHeure->take(self::PUCES_PAR_JOUR_MAX)->map(fn (Courrier $courrier) => [
                    'id' => $courrier->id,
                    'numero_reference' => $courrier->numero_reference,
                    'objet' => $courrier->objet,
                    'expediteur' => $courrier->expediteur_organisation ?: $courrier->expediteur_nom,
                    'affecte_nom' => $courrier->affectationCourante?->collaborateur?->name,
                    'affecte_initiales' => $courrier->affectationCourante?->collaborateur?->initials(),
                    'affecte_par_nom' => $courrier->affectationCourante?->affectePar?->name,
                    'affecte_par_initiales' => $courrier->affectationCourante?->affectePar?->initials(),
                    'statut_sla' => $calculateur->calculerStatutDelai($courrier),
                ])->all(),
                'surplus' => max(0, $sansHeure->count() - self::PUCES_PAR_JOUR_MAX),
                'minutees' => $avecHeure->map(fn (Courrier $courrier) => [
                    'id' => $courrier->id,
                    'numero_reference' => $courrier->numero_reference,
                    'objet' => $courrier->objet,
                    'expediteur' => $courrier->expediteur_organisation ?: $courrier->expediteur_nom,
                    'affecte_nom' => $courrier->affectationCourante?->collaborateur?->name,
                    'affecte_initiales' => $courrier->affectationCourante?->collaborateur?->initials(),
                    'heure' => (int) $courrier->chrono_fin_le->format('H'),
                    // Décalage vertical dans la ligne de l'heure, PLAFONNÉ pour
                    // que la carte (hauteur fixe) reste toujours entièrement
                    // dans sa propre ligne — sans ce plafond, un événement en
                    // fin d'heure (ex. 14:47) débordait visiblement sur la
                    // ligne suivante (2026-10-05, "IT IS IN BETWEEN THE 14H
                    // AND 15H TIMELINE WHY").
                    'decalage_minute_px' => min(
                        (int) $courrier->chrono_fin_le->format('i') / 60 * self::HAUTEUR_LIGNE_HEURE_PX,
                        self::HAUTEUR_LIGNE_HEURE_PX - self::HAUTEUR_CARTE_EVENEMENT_PX
                    ),
                    'heure_libelle' => $courrier->chrono_fin_le->format('H:i'),
                    'statut_sla' => $calculateur->calculerStatutDelai($courrier),
                ])->values()->all(),
            ];
        }

        return $resultat;
    }

    public function render()
    {
        return view('frontend::courrierCalendar');
    }
}
