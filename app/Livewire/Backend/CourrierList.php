<?php

namespace App\Livewire\Backend;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\DossierClassement;
use App\Models\Service;
use App\Services\DossierClassementService;
use App\Services\WorkflowService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Tous les courriers')]
class CourrierList extends Component
{
    use WithPagination;

    // Module 3 — "classement... retrouvable via plusieurs critères
    // (métadonnées)" (specifications-modules-GEC.md). Uniquement des
    // primitifs en propriétés publiques (Règle n°2).
    #[Url]
    public string $numero = '';

    #[Url]
    public string $objet = '';

    #[Url]
    public string $expediteur = '';

    // Module 8 — recherche dans le CONTENU du document scanné (texte_ocr),
    // demande explicite de l'utilisateur (2026-09-08) : retrouver un
    // courrier par un fragment de texte OCR (montant, capital social, nom
    // d'organisation cité...) même quand l'expéditeur/objet exact est
    // oublié — distinct des filtres structurés ci-dessus.
    #[Url]
    public string $contenu = '';

    // Demande explicite de l'utilisateur (2026-09-08) : "type document
    // (catégorie de document (confidentiel etc))" — deux champs distincts.
    // type_document est un texte libre saisi à l'enregistrement (ex.
    // "Lettre", "Facture" — voir CourrierForm), donc un filtre texte comme
    // objet/expéditeur ci-dessus ; confidentialite est une échelle fermée à
    // 5 niveaux NUMÉRIQUES (2026-09-21, "numbers 1,2,3,4,5 etc" — voir
    // CourrierForm::rules()), donc un filtre à choix comme statut/sens
    // ci-dessous. Reste `string` (pas `?int`) : même sentinelle "chaîne
    // vide = pas de filtre" que statut/sens/priorite ci-dessous.
    #[Url]
    public string $typeDocument = '';

    #[Url]
    public string $confidentialite = '';

    #[Url]
    public string $dateDebut = '';

    #[Url]
    public string $dateFin = '';

    #[Url]
    public ?int $serviceId = null;

    #[Url]
    public string $statut = '';

    // Valeur spéciale du filtre statut : "tous les courriers encore en
    // circuit" (voir resultats()).
    public const STATUT_ACTIFS = 'actifs';

    #[Url]
    public string $sens = '';

    // Tri de la colonne "N° Courrier" (maquette 2026-09-18, icône ⇕ dans
    // l'en-tête) : whitelist stricte de colonnes triables, jamais le nom de
    // colonne accepté tel quel depuis le client (injection SQL via
    // orderBy() sinon).
    private const COLONNES_TRIABLES = ['numero_reference', 'date_mouvement'];

    #[Url]
    public string $tri = 'date_mouvement';

    #[Url]
    public string $direction = 'desc';

    // Nouvelle maquette "Tous les courriers" (2026-09-18, fournie par
    // l'utilisateur) : filtres rapides Priorité/Période en plus des
    // filtres avancés déjà existants ci-dessus. "Période" est un préréglage
    // (voir periodeVersDates() ci-dessous), pas un remplacement de
    // dateDebut/dateFin (toujours disponibles dans les filtres avancés pour
    // une plage précise).
    #[Url]
    public string $priorite = '';

    #[Url]
    public string $periode = '';

    // Module 8/10 — barre de recherche unique de la nouvelle barre de
    // navigation (maquette GPT, 2026-09-16, voir DECISIONS.md "Navigation
    // (navbar + sidebar)") : un simple lien GET (?q=...) suffit à la
    // remplir grâce à #[Url], sans faire de la navbar elle-même un
    // composant Livewire. Distinct des filtres avancés ci-dessus (numero/
    // objet/expediteur) : recherche large sur les trois à la fois, pas un
    // remplacement des filtres structurés.
    #[Url]
    public string $q = '';

    // Panneau latéral "Aperçu du courrier" (nouvelle maquette 2026-09-18) :
    // un seul id à la fois, jamais le modèle complet en propriété publique
    // (Règle n°2) — rechargé et réautorisé à chaque requête via
    // courrierApercu() ci-dessous.
    public ?int $courrierApercuId = null;

    // Colonne de cases à cocher (maquette 2026-09-18) : uniquement des ids
    // (Règle n°2), liaison de tableau native de Livewire (chaque case
    // partage le même wire:model, avec sa propre `value`). Réellement
    // fonctionnelle — jamais une case qui ferait semblant de fonctionner.
    // Persiste volontairement à travers un changement de page/filtre (pas
    // vidée par updated() ci-dessous) : chaque id reste revérifié
    // individuellement (Règle n°6) au moment de l'action, donc rien
    // d'incorrect à la valider ; la vider automatiquement au moindre
    // changement de propriété casserait transfererSelection()/
    // classerSelection() elles-mêmes (destinataireChoisi/dossierChoisi sont
    // aussi des propriétés, mises à jour dans LA MÊME requête que l'appel,
    // juste avant lui).
    public array $selectionnes = [];

    // Module 1/4 — transfert en masse (2026-09-22, demande explicite de
    // l'utilisateur : "no button to transfer courier in bulk why" — même
    // action que MesCourriers::transfererSelection(), reciblée sur cette
    // page). Curatée par l'administrateur pour CET utilisateur précis (voir
    // DECISIONS.md "Destinataires de transfert"), pas dérivée d'un
    // profil/privilège — même liste, même id brut revérifié avant d'agir
    // (Règle n°6).
    public ?int $destinataireChoisi = null;

    // Module 3/9 — classement en masse (2026-09-22, demande explicite de
    // l'utilisateur : "les trois" points d'entrée pour "mettre un courrier
    // dans un dossier créé"). Id brut, revérifié contre
    // $this->dossiersAccessibles avant d'agir (Règle n°6).
    public ?int $dossierChoisi = null;

    // Suppression (2026-09-23, courriers.supprimer) — motif obligatoire.
    public string $motifSuppression = '';

    public function mount(): void
    {
        $this->authorize('rechercher', Courrier::class);
    }

    // Règle n°5 : suppression LOGIQUE uniquement (SoftDeletes — la ligne et
    // tout son historique restent en base), tracée dans l'historique
    // append-only AVANT la suppression, dans la même transaction ; jamais un
    // courrier archivé (CourrierPolicy::delete()). Id revérifié côté serveur
    // (Règle n°6), jamais fait confiance au panneau affiché.
    public function supprimerCourrier(int $courrierId): void
    {
        $courrier = Courrier::findOrFail($courrierId);
        $this->authorize('delete', $courrier);

        $this->validate(['motifSuppression' => ['required', 'string', 'min:5', 'max:500']], [], ['motifSuppression' => __('motif')]);

        DB::transaction(function () use ($courrier) {
            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => Auth::id(),
                'action' => 'suppression',
                'commentaire' => $this->motifSuppression,
            ]);

            $courrier->delete();
        });

        $this->reset('motifSuppression', 'courrierApercuId');
        $this->selectionnes = array_values(array_diff($this->selectionnes, [$courrier->id]));
        unset($this->resultats, $this->courrierApercu);

        Flux::modal('courrier-suppression')->close();
        Flux::toast(variant: 'success', text: __('Courrier :ref supprimé.', ['ref' => $courrier->numero_reference]));
    }

    public function basculerSelectionPage(): void
    {
        $idsPage = $this->resultats->pluck('id')->all();

        $this->selectionnes = array_diff($idsPage, $this->selectionnes) === []
            ? array_values(array_diff($this->selectionnes, $idsPage))
            : array_values(array_unique([...$this->selectionnes, ...$idsPage]));
    }

    #[Computed]
    public function destinatairesTransfert()
    {
        return Auth::user()->destinatairesTransfert()->orderBy('name')->get(['users.id', 'users.name']);
    }

    // Demande explicite de l'utilisateur (2026-09-22) : le bouton "Transférer
    // la sélection" lui-même reste visible/actionnable pour n'importe quel
    // profil ayant AU MOINS un privilège de transfert (jamais un rôle en
    // dur) — l'autorisation RÉELLE reste vérifiée courrier par courrier
    // ci-dessous, ceci ne sert qu'à éviter d'afficher un bouton qui
    // échouerait silencieusement pour tout le monde.
    #[Computed]
    public function peutTransfererAuMoinsUnCourrier(): bool
    {
        $utilisateur = Auth::user();

        return $utilisateur->hasPrivilege('courriers.transferer_tout') || $utilisateur->hasPrivilege('courriers.transferer_propre');
    }

    // Même logique que MesCourriers::transfererSelection() : le destinataire
    // choisi est revérifié contre les destinataires autorisés de
    // l'utilisateur (jamais fait confiance à l'ID posté), puis CHAQUE
    // courrier de la sélection est revérifié individuellement via
    // CourrierPolicy::transferer() (privilège + niveau de confidentialité +
    // accès dossier, TOUS déjà appliqués par cette ability) — un courrier
    // que cet utilisateur ne peut pas transférer, ou qui n'est plus au bon
    // statut, est silencieusement ignoré plutôt que de faire échouer tout
    // le lot.
    public function transfererSelection(WorkflowService $workflow): void
    {
        $utilisateur = Auth::user();
        $destinataire = $utilisateur->destinatairesTransfert()->where('users.id', $this->destinataireChoisi)->first();

        if (! $destinataire) {
            $this->addError('destinataireChoisi', __('Choisissez un destinataire.'));

            return;
        }

        $transferes = 0;

        foreach (Courrier::query()->whereIn('id', $this->selectionnes)->where('statut', 'en_attente_de_transfert')->get() as $courrier) {
            if (! $utilisateur->can('transferer', $courrier)) {
                continue;
            }

            $workflow->transferer($courrier, $destinataire, $utilisateur);
            $transferes++;
        }

        $this->selectionnes = [];
        $this->reset('destinataireChoisi');
        unset($this->resultats);

        Flux::toast(
            variant: $transferes > 0 ? 'success' : 'warning',
            text: $transferes > 0
                ? __(':n courrier(s) transféré(s) à :nom.', ['n' => $transferes, 'nom' => $destinataire->name])
                : __('Aucun courrier sélectionné n\'a pu être transféré.'),
        );
    }

    // Module 3/9 — même requête que DossierClassementList::tousLesDossiersAccessibles()
    // (dupliquée à l'identique, même convention déjà suivie pour services()
    // dans ce composant ET dans ShowCourrier/DossierClassementList).
    #[Computed]
    public function dossiersAccessibles()
    {
        $user = Auth::user();

        return DossierClassement::query()
            ->when(! $user->hasPrivilege('dossiers_classement.gerer_tout'), fn ($q) => $q->where(fn ($q) => $q
                ->where('cree_par_id', $user->id)
                ->orWhere('responsable_id', $user->id)
                ->orWhereHas('utilisateursAutorises', fn ($q) => $q->where('users.id', $user->id))))
            ->orderBy('nom')
            ->get();
    }

    // Contrairement à peutTransfererAuMoinsUnCourrier() ci-dessus, pas de
    // privilège fixe à vérifier : la classification est ouverte à quiconque
    // a accès à AU MOINS un dossier (créateur/responsable/partagé/gerer_tout)
    // — l'autorisation réelle reste vérifiée courrier par courrier ET dossier
    // par dossier dans classerSelection() ci-dessous.
    #[Computed]
    public function peutClasserAuMoinsUnCourrier(): bool
    {
        // + privilège d'action courriers.classer (2026-09-23).
        return Auth::user()->hasPrivilege('courriers.classer') && $this->dossiersAccessibles->isNotEmpty();
    }

    // Même principe que transfererSelection() ci-dessus : le dossier choisi
    // est revérifié contre les dossiers accessibles de l'utilisateur (jamais
    // fait confiance à l'ID posté), puis CHAQUE courrier de la sélection est
    // revérifié individuellement via CourrierPolicy::classer() — pas de
    // filtre de statut (contrairement au transfert, la classification n'est
    // pas liée au circuit de validation).
    public function classerSelection(DossierClassementService $service): void
    {
        $utilisateur = Auth::user();
        $dossier = $this->dossiersAccessibles->firstWhere('id', $this->dossierChoisi);

        if (! $dossier) {
            $this->addError('dossierChoisi', __('Choisissez un dossier.'));

            return;
        }

        $classes = 0;

        foreach (Courrier::query()->whereIn('id', $this->selectionnes)->get() as $courrier) {
            if (! $utilisateur->can('classer', $courrier)) {
                continue;
            }

            $service->classer($courrier, $dossier, $utilisateur);
            $classes++;
        }

        $this->selectionnes = [];
        $this->reset('dossierChoisi');
        unset($this->resultats);

        Flux::toast(
            variant: $classes > 0 ? 'success' : 'warning',
            text: $classes > 0
                ? __(':n courrier(s) classé(s) dans « :nom ».', ['n' => $classes, 'nom' => $dossier->nom])
                : __('Aucun courrier sélectionné n\'a pu être classé.'),
        );
    }

    // Vidé à chaque changement de filtre pour ne jamais rester bloqué sur
    // une page 2+ devenue vide après un filtrage plus restrictif.
    public function updated($nom): void
    {
        if ($nom !== 'page') {
            $this->resetPage();
        }
    }

    public function reinitialiser(): void
    {
        $this->reset('numero', 'objet', 'expediteur', 'contenu', 'typeDocument', 'confidentialite', 'dateDebut', 'dateFin', 'serviceId', 'statut', 'sens', 'priorite', 'periode', 'q');
        $this->resetPage();
    }

    public function ouvrirApercu(int $courrierId): void
    {
        $this->courrierApercuId = $courrierId;
    }

    public function trierPar(string $colonne): void
    {
        if (! in_array($colonne, self::COLONNES_TRIABLES, true)) {
            return;
        }

        if ($this->tri === $colonne) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->tri = $colonne;
            $this->direction = 'asc';
        }

        $this->resetPage();
    }

    #[Computed]
    public function services()
    {
        return Service::query()->where('actif', true)->orderBy('nom')->get(['id', 'nom']);
    }

    // Rechargé et réautorisé à chaque affichage (Règle n°6 — jamais
    // confiance en un ID transmis par le client) plutôt que de garder le
    // modèle en mémoire (Règle n°2).
    #[Computed]
    // Panneau TOUJOURS affiché (maquette 2026-09-18, demande explicite de
    // l'utilisateur : "those cards panel should always be shown not mask
    // and the should show the first courier by default") : sans sélection
    // explicite, affiche le PREMIER résultat de la page courante plutôt que
    // de masquer le panneau — celui-ci ne reste vide que s'il n'y a
    // vraiment aucun résultat.
    public function courrierApercu(): ?Courrier
    {
        // Sans sélection explicite, le PREMIER résultat de la page courante
        // fait office de défaut — même Règle n°3 (eager loading) que la
        // branche ci-dessous : on ne réutilise pas l'instance de
        // resultats() telle quelle (elle n'a que 'service' en eager load,
        // pas 'piecesJointes' nécessaire au panneau), on la recharge
        // proprement par id.
        $id = $this->courrierApercuId ?? $this->resultats->first()?->id;

        if ($id === null) {
            return null;
        }

        $courrier = Courrier::query()->with(['service', 'piecesJointes'])->find($id);

        if ($courrier === null || Auth::user()->cannot('view', $courrier)) {
            return null;
        }

        return $courrier;
    }

    // Même périmètre par profil que resultats() ci-dessous — extrait dans
    // sa propre méthode pour être réutilisé par statistiques() sans
    // dupliquer la logique (les 4 chiffres des cartes doivent porter sur
    // EXACTEMENT ce que l'utilisateur peut voir, pas sur tous les courriers
    // de l'entreprise).
    //
    // 2026-09-21 — la logique elle-même a été extraite dans
    // Courrier::scopeVisiblePar() (Module 3, "Dossiers & Archives") : elle
    // devait déjà rester synchronisée à la main avec CourrierPolicy::view()
    // pour la confidentialité, et le nouveau gate d'accès par dossier
    // aurait ajouté un troisième endroit à maintenir manuellement en
    // parallèle. Voir Courrier::scopeVisiblePar() pour le corps complet.
    private function portee(): Builder
    {
        return Courrier::query()->visiblePar(Auth::user());
    }

    // "Période" (nouvelle maquette 2026-09-18) : préréglages usuels plutôt
    // que d'exposer directement dateDebut/dateFin dans les filtres rapides
    // — ceux-ci restent disponibles séparément (filtres avancés) pour une
    // plage précise. Retourne [null, null] si aucun préréglage ne
    // correspond (dont periode === ''), pour ne rien filtrer dans ce cas.
    private function periodeVersDates(): array
    {
        return match ($this->periode) {
            'aujourd_hui' => [now()->startOfDay(), now()->endOfDay()],
            'cette_semaine' => [now()->startOfWeek(), now()->endOfWeek()],
            'ce_mois' => [now()->startOfMonth(), now()->endOfMonth()],
            'cette_annee' => [now()->startOfYear(), now()->endOfYear()],
            default => [null, null],
        };
    }

    // Toujours paginé (Règle n°3), eager loading systématique — jamais de
    // ::all() sur une table qui va grossir. Périmètre par profil identique à
    // CourrierPolicy::view() (Module 8 : "résultats filtrés selon les droits
    // d'accès de l'utilisateur"), appliqué ici au niveau de la requête
    // plutôt que filtré après coup en mémoire.
    #[Computed]
    public function resultats()
    {
        [$periodeDebut, $periodeFin] = $this->periodeVersDates();

        return $this->portee()
            ->when($this->q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('numero_reference', 'like', "%{$this->q}%")
                ->orWhere('objet', 'like', "%{$this->q}%")
                ->orWhere('expediteur_nom', 'like', "%{$this->q}%")
                ->orWhere('expediteur_organisation', 'like', "%{$this->q}%")))
            ->when($this->numero !== '', fn ($q) => $q->where('numero_reference', 'like', "%{$this->numero}%"))
            ->when($this->objet !== '', fn ($q) => $q->where('objet', 'like', "%{$this->objet}%"))
            ->when($this->expediteur !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('expediteur_nom', 'like', "%{$this->expediteur}%")
                ->orWhere('expediteur_organisation', 'like', "%{$this->expediteur}%")))
            // MATCH()/AGAINST() (indexé, voir la migration associée) sur
            // MySQL — mode booléen plutôt que langage naturel : ce dernier
            // exclut silencieusement tout terme présent dans plus de 50 %
            // des lignes, un seuil bien trop facile à atteindre avec le peu
            // de courriers du pilote. SQLite (tests) n'a pas d'équivalent
            // FULLTEXT — bascule sur un LIKE simple, sans risque de
            // performance sur un jeu de données de test minuscule.
            // Recherche dans le texte OCR : courriers.voir_texte_ocr requis
            // (2026-09-23) — ignorée côté serveur sinon, même si postée.
            ->when($this->contenu !== '' && $this->peutRechercherContenu, fn ($q) => $q->getConnection()->getDriverName() === 'mysql'
                ? $q->whereFullText('texte_ocr', $this->contenu, ['mode' => 'boolean'])
                : $q->where('texte_ocr', 'like', "%{$this->contenu}%"))
            ->when($this->typeDocument !== '', fn ($q) => $q->where('type_document', 'like', "%{$this->typeDocument}%"))
            ->when($this->confidentialite !== '', fn ($q) => $q->where('confidentialite', $this->confidentialite))
            ->when($this->priorite !== '', fn ($q) => $q->where('priorite', $this->priorite))
            ->when($this->dateDebut !== '', fn ($q) => $q->whereDate('date_mouvement', '>=', $this->dateDebut))
            ->when($this->dateFin !== '', fn ($q) => $q->whereDate('date_mouvement', '<=', $this->dateFin))
            ->when($periodeDebut && $periodeFin, fn ($q) => $q->whereBetween('date_mouvement', [$periodeDebut, $periodeFin]))
            ->when($this->serviceId, fn ($q) => $q->where('service_id', $this->serviceId))
            // 'actifs' (2026-09-24) : tous les statuts encore en circuit —
            // remplace l'ancienne page "Transferts"/file d'attente
            // (WorkflowQueue), supprimée à la demande de l'utilisateur.
            ->when($this->statut === self::STATUT_ACTIFS, fn ($q) => $q->whereIn('statut', WorkflowService::statutsActifs()))
            ->when($this->statut !== '' && $this->statut !== self::STATUT_ACTIFS, fn ($q) => $q->where('statut', $this->statut))
            ->when($this->sens !== '', fn ($q) => $q->where('sens', $this->sens))
            ->with('service')
            // Tri dynamique (voir trierPar() et COLONNES_TRIABLES) : $tri est
            // repassé par la valeur whitelistée à l'écriture (trierPar()),
            // mais #[Url] le peuple aussi directement depuis la query string
            // au chargement de la page — jamais confiance en cette valeur
            // sans revalider ici (Règle n°6, pas d'orderBy() sur une colonne
            // arbitraire fournie par le client).
            ->orderBy(
                in_array($this->tri, self::COLONNES_TRIABLES, true) ? $this->tri : 'date_mouvement',
                $this->direction === 'asc' ? 'asc' : 'desc',
            )
            // 10 par page (demande explicite de l'utilisateur, 2026-09-14),
            // même changement que WorkflowQueue — auparavant 20.
            ->paginate(10);
    }

    // 4 cartes de la nouvelle maquette (2026-09-18) — clarifié avec
    // l'utilisateur (AskUserQuestion) : adaptées aux données RÉELLES du
    // schéma plutôt qu'aux libellés exacts de la maquette fournie, qui ne
    // correspondaient à aucun champ existant. "Terminés" = traité ou
    // archivé ; "En traitement" = tous les statuts intermédiaires actifs du
    // circuit ; "En erreur" = échec OCR (ocr_statut), la seule notion
    // d'"erreur" qui existe réellement dans le schéma (un rejet est une
    // décision humaine du circuit de validation, pas une erreur technique).
    // Variation en pourcentage = nombre de courriers CRÉÉS ce mois-ci vs le
    // mois précédent (created_at), un vrai calcul, jamais un chiffre
    // inventé — "—" si le mois précédent n'a aucune donnée de référence.
    //
    // 2026-09-23 — "same thing for all the pages, each card should be a
    // permission" : CHAQUE carte a désormais sa propre clé
    // (courriers.voir_carte_total/en_traitement/termines/en_erreur, plus
    // l'ancienne courriers.voir_statistiques partagée, voir PrivilegeSeeder).
    // Une clé manquante retire SEULEMENT l'entrée correspondante du
    // tableau — jamais calculée pour cette carte (pas seulement masquée
    // dans la vue), même principe que Dashboard::courrierEntrantAujourdhui().
    public const CARTES = [
        'total' => 'courriers.voir_carte_total',
        'enTraitement' => 'courriers.voir_carte_en_traitement',
        'termines' => 'courriers.voir_carte_termines',
        'enErreur' => 'courriers.voir_carte_en_erreur',
    ];

    #[Computed]
    public function statistiques(): array
    {
        $statutsEnTraitement = ['affecte', 'en_traitement', 'en_validation', 'en_attente_de_transfert', 'en_cours_de_transfert', 'en_attente_information'];
        $statutsTermines = ['traite', 'archive'];

        $definitions = [
            'total' => fn ($q) => $q,
            'enTraitement' => fn ($q) => $q->whereIn('statut', $statutsEnTraitement),
            'termines' => fn ($q) => $q->whereIn('statut', $statutsTermines),
            'enErreur' => fn ($q) => $q->whereIn('ocr_statut', ['echec', 'echec_qualite']),
        ];

        $utilisateur = Auth::user();
        $resultats = [];

        foreach (self::CARTES as $cle => $privilege) {
            if ($utilisateur->hasPrivilege($privilege)) {
                $resultats[$cle] = $this->statistique($definitions[$cle]);
            }
        }

        return $resultats;
    }

    private function statistique(callable $filtre): array
    {
        $total = $filtre($this->portee())->count();
        $moisActuel = $filtre($this->portee())->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $moisPrecedent = $filtre($this->portee())->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()])->count();

        $variation = match (true) {
            $moisPrecedent > 0 => (int) round((($moisActuel - $moisPrecedent) / $moisPrecedent) * 100),
            $moisActuel > 0 => 100,
            default => null,
        };

        return ['total' => $total, 'variation' => $variation];
    }

    // Module 8 — un résultat trouvé via `contenu` ne montre, par défaut,
    // aucun champ où le terme apparaît réellement (il peut n'être que dans
    // texte_ocr, jamais affiché en liste) : sans extrait, l'utilisateur ne
    // peut pas vérifier pourquoi ce courrier est remonté. Fenêtre de 60
    // caractères de chaque côté de la première occurrence — best-effort,
    // insensible à la casse ; ne tente pas de reproduire la logique de
    // pertinence MySQL (mots-clés multiples, proximité...), seulement de
    // localiser un repère visuel.
    // "Chaque action / lecture = un privilège" (2026-09-23) : lectures de
    // cette page gouvernées par leur propre privilège.
    #[Computed]
    public function peutRechercherContenu(): bool
    {
        return Auth::user()->hasPrivilege('courriers.voir_texte_ocr');
    }

    // PENTEST (2026-09-24) — $courrier est TYPÉ, donc Livewire le résout
    // lui-même via resolveRouteBinding() (Courrier::find($id) brut) AVANT
    // même d'entrer dans cette méthode, sans passer par visiblePar() ni
    // aucune policy — contrairement à courrierApercu()/supprimerCourrier()
    // ci-dessus qui revérifient explicitement un id reçu du client (Règle
    // n°6). ! $this->peutRechercherContenu (privilège global) ne suffit
    // pas : il fallait aussi revérifier CE courrier précis, sinon un id
    // arbitraire posté directement (hors UI, qui ne l'affiche jamais)
    // pouvait faire fuir un extrait de texte OCR hors du périmètre de
    // l'utilisateur. voirTexteOcr() inclut déjà view() (confidentialité +
    // dossier + périmètre de service).
    public function extraitTexteOcr(Courrier $courrier): ?string
    {
        if ($this->contenu === '' || blank($courrier->texte_ocr) || Auth::user()->cannot('voirTexteOcr', $courrier)) {
            return null;
        }

        $position = mb_stripos($courrier->texte_ocr, $this->contenu);

        if ($position === false) {
            return null;
        }

        $debut = max(0, $position - 60);
        $extrait = trim(mb_substr($courrier->texte_ocr, $debut, 120 + mb_strlen($this->contenu)));

        return ($debut > 0 ? '…' : '').$extrait.(mb_strlen($courrier->texte_ocr) > $debut + mb_strlen($extrait) ? '…' : '');
    }

    public function render()
    {
        return view('frontend::courrierList');
    }
}
