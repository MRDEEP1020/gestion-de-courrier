<?php

namespace App\Livewire\Backend;

use App\Jobs\RefreshStatistiquesJob;
use App\Models\Courrier;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

// Module 10 étendu — « Dashboard statistiques » façon Power BI (2026-10-30,
// voir DECISIONS.md) : flux et délais, circuit et ancienneté, répartition,
// charge par personne, détail par service ; un clic sur une barre filtre
// toute la page (puces retirables, gardées dans l'URL). Rien n'est agrégé
// lourdement ici (Règle n°1) : RefreshStatistiquesJob reconstruit la table de
// faits statistiques_courriers, cette page n'y fait que des totaux indexés,
// toujours bornés au périmètre de services et au niveau de confidentialité
// de l'utilisateur (Règle n°6).
#[Title('Dashboard statistiques')]
class Statistiques extends Component
{
    public const PERIODES = ['ce_mois', '3_mois', '12_mois', 'cette_annee', 'annee_precedente'];

    // Dimensions filtrables au clic → propriété publique (liste blanche :
    // jamais un nom de colonne venu du navigateur, Règle n°6).
    public const FILTRES = [
        'service' => 'serviceId',
        'mois' => 'mois',
        'type' => 'type',
        'priorite' => 'priorite',
        'canal' => 'canal',
        'expediteur' => 'expediteur',
        'etape' => 'etape',
        'anciennete' => 'anciennete',
        'collaborateur' => 'collaborateurId',
    ];

    // Valeur filtrable « Non précisé » (champ vide en base).
    public const VIDE = '__vide__';

    // Étapes du circuit, dans l'ordre du circuit (pas par taille).
    public const ETAPES = [
        'a_transferer' => 'À transférer',
        'attente_acceptation' => 'En attente d\'acceptation',
        'remise_original' => 'Remise de l\'original',
        'signature_reception' => 'Signature de la réception',
        'a_affecter' => 'À affecter',
        'a_demarrer' => 'Affecté, à démarrer',
        'en_traitement' => 'En traitement',
        'en_validation' => 'En validation',
        'attente_information' => 'En attente d\'information',
        'signature_dg' => 'Signature DG',
        'tresorerie' => 'Trésorerie',
        'remise_confidentiel' => 'Pli confidentiel à remettre',
    ];

    // Tranches d'ancienneté des courriers en cours (jours depuis la réception).
    public const TRANCHES = [
        '0_2' => [0, 2, '0 à 2 j'],
        '3_7' => [3, 7, '3 à 7 j'],
        '8_15' => [8, 15, '8 à 15 j'],
        '16_30' => [16, 30, '16 à 30 j'],
        '31_plus' => [31, null, 'Plus de 30 j'],
    ];

    public const CANAUX = ['depot_physique' => 'Dépôt physique', 'email' => 'Email', 'poste' => 'Poste', 'fax' => 'Fax'];

    public const PRIORITES = ['urgente' => 'Urgente', 'haute' => 'Haute', 'normale' => 'Normale', 'basse' => 'Basse'];

    #[Url]
    public string $periode = '12_mois';

    // Filtres : chaînes (« vide » = pas de filtre), Règle 19 des pièges Livewire.
    #[Url]
    public string $serviceId = '';

    #[Url]
    public string $mois = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $priorite = '';

    #[Url]
    public string $canal = '';

    #[Url]
    public string $expediteur = '';

    #[Url]
    public string $etape = '';

    #[Url]
    public string $anciennete = '';

    #[Url]
    public string $collaborateurId = '';

    public function mount(): void
    {
        $this->authorize('voirStatistiques', Courrier::class);

        if (! in_array($this->periode, self::PERIODES, true)) {
            $this->periode = '12_mois';
        }
    }

    // ===== Filtres au clic =====

    // Un second clic sur la même barre retire le filtre (comme Power BI).
    public function filtrer(string $dimension, string $valeur): void
    {
        if (! isset(self::FILTRES[$dimension])) {
            return;
        }

        $propriete = self::FILTRES[$dimension];
        $this->{$propriete} = $this->{$propriete} === $valeur ? '' : $valeur;
    }

    public function retirerFiltre(string $dimension): void
    {
        if (isset(self::FILTRES[$dimension])) {
            $this->{self::FILTRES[$dimension]} = '';
        }
    }

    public function effacerFiltres(): void
    {
        $this->reset(array_values(self::FILTRES));
    }

    public function updatedPeriode(): void
    {
        // Un mois choisi hors de la nouvelle période n'aurait plus de sens.
        if ($this->mois !== '' && ! in_array($this->mois, $this->moisDeLaPeriode(), true)) {
            $this->mois = '';
        }
    }

    // ===== Périmètre (Règle n°6) =====

    // Même règle que Courrier::scopeVisiblePar() pour les services :
    // « Voir tous les courriers » → tous (null, plis sans service compris) ;
    // « Voir les courriers de son service » → services dont il est responsable
    // et ceux des unités placées sous l'entité qu'il dirige ; sinon aucun.
    #[Computed]
    public function perimetre(): ?array
    {
        $user = Auth::user();

        if ($user->hasPrivilege('courriers.voir_tout')) {
            return null;
        }

        if (! $user->hasPrivilege('courriers.voir_service')) {
            return [];
        }

        return Service::where('responsable_id', $user->id)->pluck('id')
            ->merge($user->idsServicesSupervises())
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
    }

    #[Computed]
    public function servicesDisponibles()
    {
        return Service::query()
            ->when($this->perimetre !== null, fn ($q) => $q->whereIn('id', $this->perimetre))
            ->orderBy('nom')
            ->get(['id', 'nom']);
    }

    // Date du dernier calcul ; null = jamais calculé (calcul demandé une
    // fois toutes les 10 minutes, jamais fait ici).
    #[Computed]
    public function calculeLe(): ?Carbon
    {
        $le = Cache::get(RefreshStatistiquesJob::CLE_CACHE);

        if ($le === null) {
            if (Cache::add(RefreshStatistiquesJob::CLE_CACHE.'.demande', true, 600)) {
                RefreshStatistiquesJob::dispatch();
            }

            return null;
        }

        return Carbon::parse($le);
    }

    // ===== Requêtes =====

    // Faits du périmètre, avec tous les filtres actifs sauf ceux de $sauf
    // (un graphique ignore son propre filtre pour garder toutes ses barres
    // et mettre la sélection en évidence). Le mois n'est jamais appliqué
    // ici : chaque indicateur choisit sa colonne de mois (réception ou clôture).
    private function base(array $sauf = []): Builder
    {
        $requete = DB::table('statistiques_courriers')
            ->where('confidentialite', '<=', Auth::user()->niveauConfidentialiteEffectif());

        if ($this->perimetre !== null) {
            $requete->whereIn('service_id', $this->perimetre === [] ? [-1] : $this->perimetre);
        }

        $colonnes = ['type' => 'type_document', 'priorite' => 'priorite', 'canal' => 'mode_reception', 'expediteur' => 'expediteur', 'etape' => 'etape'];

        foreach (self::FILTRES as $dimension => $propriete) {
            $valeur = $this->{$propriete};

            if ($valeur === '' || $dimension === 'mois' || in_array($dimension, $sauf, true)) {
                continue;
            }

            match ($dimension) {
                'service' => $this->perimetre === null || in_array((int) $valeur, $this->perimetre, true)
                    ? $requete->where('service_id', (int) $valeur)
                    : null,
                'collaborateur' => $requete->where('collaborateur_id', (int) $valeur),
                'anciennete' => isset(self::TRANCHES[$valeur])
                    ? $requete->where('en_cours', 1)->where('anciennete_jours', '>=', self::TRANCHES[$valeur][0])
                        ->when(self::TRANCHES[$valeur][1] !== null, fn ($q) => $q->where('anciennete_jours', '<=', self::TRANCHES[$valeur][1]))
                    : null,
                default => $requete->where($colonnes[$dimension], $valeur === self::VIDE ? '' : $valeur),
            };
        }

        return $requete;
    }

    // Mois couverts par la période, du plus ancien au plus récent.
    public function moisDeLaPeriode(): array
    {
        [$debut, $fin] = match ($this->periode) {
            'ce_mois' => [now()->startOfMonth(), now()->startOfMonth()],
            '3_mois' => [now()->startOfMonth()->subMonths(2), now()->startOfMonth()],
            'cette_annee' => [now()->startOfYear(), now()->startOfMonth()],
            'annee_precedente' => [now()->subYear()->startOfYear(), now()->subYear()->startOfYear()->addMonths(11)],
            default => [now()->startOfMonth()->subMonths(11), now()->startOfMonth()],
        };

        $mois = [];

        // Dates immuables dans cette application (CarbonImmutable) :
        // addMonth() renvoie une nouvelle date, réassignée à chaque tour.
        for ($courant = $debut; $courant->lte($fin); $courant = $courant->addMonth()) {
            $mois[] = $courant->format('Y-m');
        }

        return $mois;
    }

    // Période effective des indicateurs : le mois cliqué s'il y en a un.
    private function moisRetenus(): array
    {
        return $this->mois !== '' ? [$this->mois] : $this->moisDeLaPeriode();
    }

    #[Computed]
    public function indicateurs(): array
    {
        $mois = $this->moisRetenus();

        $receptions = $this->base()->whereIn('mois_reception', $mois)
            ->selectRaw("sum(case when sens = 'entrant' then 1 else 0 end) as recus, sum(case when sens = 'sortant' then 1 else 0 end) as envoyes")
            ->first();
        $clotures = $this->base()->whereIn('mois_cloture', $mois)
            ->selectRaw('count(*) as n, sum(jours_traitement) as jours, sum(evaluable) as evalues, sum(dans_les_delais) as dans')
            ->first();
        $enCours = $this->base()->where('en_cours', 1)
            ->selectRaw('count(*) as n, sum(en_retard) as retard')
            ->first();

        return [
            'recus' => (int) $receptions->recus,
            'envoyes' => (int) $receptions->envoyes,
            'traites' => (int) $clotures->n,
            'delaiMoyen' => $clotures->n > 0 ? round($clotures->jours / $clotures->n, 1) : null,
            'dansLesDelais' => $clotures->evalues > 0 ? (int) round(100 * $clotures->dans / $clotures->evalues) : null,
            'enCours' => (int) $enCours->n,
            'enRetard' => (int) $enCours->retard,
        ];
    }

    // Flux mensuels des courriers ENTRANTS : reçus, clôturés, stock en cours
    // en fin de mois, % clôturés dans les délais (le graphique garde tous les
    // mois de la période, le mois cliqué est mis en évidence).
    #[Computed]
    public function flux(): array
    {
        $periode = $this->moisDeLaPeriode();

        $recus = $this->base(['mois'])->where('sens', 'entrant')->whereIn('mois_reception', $periode)
            ->groupBy('mois_reception')->selectRaw('mois_reception as m, count(*) as n')->pluck('n', 'm');
        $clotures = $this->base(['mois'])->where('sens', 'entrant')->whereIn('mois_cloture', $periode)
            ->groupBy('mois_cloture')->selectRaw('mois_cloture as m, count(*) as n, sum(evaluable) as evalues, sum(dans_les_delais) as dans')
            ->get()->keyBy('m');

        // Stock en fin de mois : reçu avant la fin du mois, pas encore sorti
        // (clôturé ou rejeté). Étape/ancienneté (instantanés du jour) ne s'y
        // appliquent pas.
        $selections = [];
        $liaisons = [];

        foreach ($periode as $i => $m) {
            $fin = min(Carbon::createFromFormat('!Y-m', $m)->endOfMonth()->toDateString(), today()->toDateString());
            $selections[] = "sum(case when date_reception <= ? and (date_sortie is null or date_sortie > ?) then 1 else 0 end) as m{$i}";
            array_push($liaisons, $fin, $fin);
        }

        $stock = (array) $this->base(['mois', 'etape', 'anciennete'])->where('sens', 'entrant')
            ->selectRaw(implode(', ', $selections), $liaisons)->first();

        return array_map(function (string $m, int $i) use ($recus, $clotures, $stock, $periode) {
            $date = Carbon::createFromFormat('!Y-m', $m);
            $cloture = $clotures->get($m);

            return [
                'cle' => $m,
                'libelle' => $date->translatedFormat('F Y'),
                // Axe : mois abrégé, l'année en janvier et sur le premier mois affiché.
                'court' => $date->translatedFormat('M').($date->month === 1 || $i === 0 ? ' '.$date->format('y') : ''),
                'recus' => (int) ($recus[$m] ?? 0),
                'clotures' => (int) ($cloture->n ?? 0),
                'stock' => (int) ($stock["m{$i}"] ?? 0),
                'dansLesDelais' => ($cloture->evalues ?? 0) > 0 ? (int) round(100 * $cloture->dans / $cloture->evalues) : null,
            ];
        }, $periode, array_keys($periode));
    }

    #[Computed]
    public function parEtape(): array
    {
        $comptes = $this->base(['etape'])->where('en_cours', 1)
            ->groupBy('etape')->selectRaw('etape, count(*) as n, sum(en_retard) as retard')->get()->keyBy('etape');

        $lignes = [];

        foreach (self::ETAPES as $cle => $libelle) {
            if (($comptes[$cle]->n ?? 0) > 0) {
                // 'retard' : part en retard de la barre, dessinée en rouge (2026-10-30).
                $lignes[] = ['cle' => $cle, 'libelle' => __($libelle), 'valeur' => (int) $comptes[$cle]->n, 'retard' => (int) $comptes[$cle]->retard];
            }
        }

        return $lignes;
    }

    #[Computed]
    public function parAnciennete(): array
    {
        $selections = [];

        foreach (self::TRANCHES as $cle => [$min, $max]) {
            $condition = $max === null ? "anciennete_jours >= {$min}" : "anciennete_jours between {$min} and {$max}";
            $selections[] = "sum(case when {$condition} then 1 else 0 end) as t{$cle}";
            // 2026-10-30 — dont en retard (série rouge à côté de « En cours »).
            $selections[] = "sum(case when {$condition} and en_retard = 1 then 1 else 0 end) as r{$cle}";
        }

        $comptes = (array) $this->base(['anciennete'])->where('en_cours', 1)->selectRaw(implode(', ', $selections))->first();

        return collect(self::TRANCHES)->map(fn ($tranche, $cle) => [
            'cle' => $cle,
            'libelle' => __($tranche[2]),
            'court' => __($tranche[2]),
            'valeurs' => ['enCours' => (int) ($comptes["t{$cle}"] ?? 0), 'retard' => (int) ($comptes["r{$cle}"] ?? 0)],
        ])->values()->all();
    }

    // Courriers ENTRANTS reçus sur la période, regroupés par une dimension.
    private function repartition(string $dimension, string $colonne, int $limite, callable $libelle): array
    {
        return $this->base([$dimension])
            ->where('sens', 'entrant')->whereIn('mois_reception', $this->moisRetenus())
            ->groupBy($colonne)->selectRaw("{$colonne} as cle, count(*) as n")
            ->orderByDesc('n')->limit($limite)->get()
            ->map(fn ($l) => [
                'cle' => $l->cle === '' ? self::VIDE : (string) $l->cle,
                'libelle' => $l->cle === '' ? __('Non précisé') : $libelle($l->cle),
                'valeur' => (int) $l->n,
            ])->all();
    }

    #[Computed]
    public function parService(): array
    {
        $noms = $this->nomsServices();

        return $this->repartition('service', 'service_id', 8, fn ($id) => $noms[(int) $id] ?? __('Service supprimé'));
    }

    #[Computed]
    public function parType(): array
    {
        return $this->repartition('type', 'type_document', 8, fn ($v) => $v);
    }

    #[Computed]
    public function parPriorite(): array
    {
        return $this->repartition('priorite', 'priorite', 5, fn ($v) => __(self::PRIORITES[$v] ?? ucfirst($v)));
    }

    #[Computed]
    public function parCanal(): array
    {
        return $this->repartition('canal', 'mode_reception', 6, fn ($v) => __(self::CANAUX[$v] ?? ucfirst(str_replace('_', ' ', $v))));
    }

    #[Computed]
    public function parExpediteur(): array
    {
        return $this->repartition('expediteur', 'expediteur', 10, fn ($v) => $v);
    }

    // Charge par personne : en cours et en retard aujourd'hui, clôturés et
    // délai moyen sur la période.
    #[Computed]
    public function chargeParPersonne(): array
    {
        $enCours = $this->base(['collaborateur'])->where('en_cours', 1)->whereNotNull('collaborateur_id')
            ->groupBy('collaborateur_id')->selectRaw('collaborateur_id as id, count(*) as n, sum(en_retard) as retard')
            ->get()->keyBy('id');
        $clotures = $this->base(['collaborateur'])->whereIn('mois_cloture', $this->moisRetenus())->whereNotNull('collaborateur_id')
            ->groupBy('collaborateur_id')->selectRaw('collaborateur_id as id, count(*) as n, sum(jours_traitement) as jours')
            ->get()->keyBy('id');

        $ids = $enCours->keys()->merge($clotures->keys())->unique();
        $noms = User::whereIn('id', $ids)->pluck('name', 'id');

        return $ids->map(fn ($id) => [
            'id' => (int) $id,
            'nom' => $noms[$id] ?? __('Utilisateur supprimé'),
            'enCours' => (int) ($enCours[$id]->n ?? 0),
            'enRetard' => (int) ($enCours[$id]->retard ?? 0),
            'clotures' => (int) ($clotures[$id]->n ?? 0),
            'delaiMoyen' => ($clotures[$id]->n ?? 0) > 0 ? round($clotures[$id]->jours / $clotures[$id]->n, 1) : null,
        ])->sortByDesc(fn ($l) => [$l['enCours'], $l['clotures']])->values()->all();
    }

    #[Computed]
    public function detailParService(): array
    {
        $mois = $this->moisRetenus();

        $receptions = $this->base(['service'])->whereIn('mois_reception', $mois)->groupBy('service_id')
            ->selectRaw("service_id as id, sum(case when sens = 'entrant' then 1 else 0 end) as recus, sum(case when sens = 'sortant' then 1 else 0 end) as envoyes")
            ->get()->keyBy('id');
        $clotures = $this->base(['service'])->whereIn('mois_cloture', $mois)->groupBy('service_id')
            ->selectRaw('service_id as id, count(*) as n, sum(jours_traitement) as jours, sum(evaluable) as evalues, sum(dans_les_delais) as dans')
            ->get()->keyBy('id');
        $enCours = $this->base(['service'])->where('en_cours', 1)->groupBy('service_id')
            ->selectRaw('service_id as id, count(*) as n, sum(en_retard) as retard')
            ->get()->keyBy('id');

        $noms = $this->nomsServices();

        return $receptions->keys()->merge($clotures->keys())->merge($enCours->keys())->unique()
            ->map(fn ($id) => [
                'id' => (int) $id,
                'service' => $noms[(int) $id] ?? __('Service supprimé'),
                'recus' => (int) ($receptions[$id]->recus ?? 0),
                'envoyes' => (int) ($receptions[$id]->envoyes ?? 0),
                'traites' => (int) ($clotures[$id]->n ?? 0),
                'delaiMoyen' => ($clotures[$id]->n ?? 0) > 0 ? round($clotures[$id]->jours / $clotures[$id]->n, 1) : null,
                'dansLesDelais' => ($clotures[$id]->evalues ?? 0) > 0 ? (int) round(100 * $clotures[$id]->dans / $clotures[$id]->evalues) : null,
                'enCours' => (int) ($enCours[$id]->n ?? 0),
                'enRetard' => (int) ($enCours[$id]->retard ?? 0),
            ])->sortByDesc(fn ($l) => [$l['recus'], $l['enCours']])->values()->all();
    }

    private function nomsServices(): array
    {
        return once(fn () => Service::pluck('nom', 'id')->all() + [0 => __('Plis confidentiels (sans service)')]);
    }

    // Puces des filtres actifs : [dimension, libellé du filtre, valeur lisible].
    #[Computed]
    public function filtresActifs(): array
    {
        $puces = [];

        foreach (self::FILTRES as $dimension => $propriete) {
            $valeur = $this->{$propriete};

            if ($valeur === '') {
                continue;
            }

            [$nomFiltre, $lisible] = match ($dimension) {
                'service' => [__('Service'), $this->nomsServices()[(int) $valeur] ?? $valeur],
                'mois' => [__('Mois'), Carbon::createFromFormat('!Y-m', $valeur)->translatedFormat('F Y')],
                'type' => [__('Type'), $valeur === self::VIDE ? __('Non précisé') : $valeur],
                'priorite' => [__('Priorité'), __(self::PRIORITES[$valeur] ?? ($valeur === self::VIDE ? 'Non précisé' : $valeur))],
                'canal' => [__('Canal'), __(self::CANAUX[$valeur] ?? ($valeur === self::VIDE ? 'Non précisé' : $valeur))],
                'expediteur' => [__('Expéditeur'), $valeur === self::VIDE ? __('Non précisé') : $valeur],
                'etape' => [__('Étape'), __(self::ETAPES[$valeur] ?? $valeur)],
                'anciennete' => [__('Ancienneté'), __(self::TRANCHES[$valeur][2] ?? $valeur)],
                'collaborateur' => [__('Personne'), User::whereKey((int) $valeur)->value('name') ?? $valeur],
            };

            $puces[] = ['dimension' => $dimension, 'nom' => $nomFiltre, 'valeur' => $lisible];
        }

        return $puces;
    }

    public function render()
    {
        return view('frontend::statistiques');
    }

    // Graduation « propre » de l'axe des valeurs (1, 2, 5 × 10^n), au moins 1.
    public static function maximumArrondi(int $valeur): int
    {
        if ($valeur <= 4) {
            return max(1, $valeur);
        }

        $puissance = 10 ** (int) floor(log10($valeur));

        foreach ([1, 2, 5, 10] as $facteur) {
            if ($facteur * $puissance >= $valeur) {
                return $facteur * $puissance;
            }
        }

        return 10 * $puissance;
    }
}
