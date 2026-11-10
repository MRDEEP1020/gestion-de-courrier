<?php

namespace App\Services;

use App\Events\CourrierStatutChange;
use App\Jobs\ConvertirPdfaJob;
use App\Models\Affectation;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Decharge;
use App\Models\Service;
use App\Models\TransfertService;
use App\Models\User;
use App\Notifications\CourrierNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class WorkflowService
{
    // Module 4 — circuit générique unique en phase 1 (PRD.md §3/§4 : "pas de
    // circuits multiples par type" est IN SCOPE, "règles de workflow avancées
    // différenciées par type de courrier" est explicitement HORS SCOPE) :
    // une seule machine à états pour tous les courriers, pas de configuration
    // par type ni par administrateur. Chaque transition est vérifiée ici
    // (défense en profondeur, indépendamment des boutons affichés côté vue)
    // et trace une entrée d'historique immuable (Règle n°5).
    //
    // "Traité" → "Archivé" n'est pas une transition manuelle : le spec Module 9
    // dit "un courrier clôturé est automatiquement transféré vers l'archivage"
    // — voir archiverAutomatiquement() plus bas, appelée uniquement par
    // ArchiverCourriersTraitesJob (2026-09-21, "Dossiers & Archives").
    // 2026-10-23 (voir DECISIONS.md "Transfert direct au service et
    // acceptation") — plus de validation DGA/ADJ : l'accueil transfère un
    // courrier entrant directement au service concerné, qui l'ACCEPTE ou le
    // REFUSE. Les deux statuts existants changent de sens, sans nouvelle
    // valeur d'ENUM (décision du 2026-09-08 de ne jamais renommer une valeur
    // déjà en place) : 'en_attente_de_transfert' = pas encore transféré (ou
    // refusé, à réorienter), 'en_cours_de_transfert' = transféré, en attente
    // d'acceptation par le service, 'enregistre' = accepté, à affecter. Un
    // courrier sortant démarre toujours directement à 'enregistre'.
    private const TRANSITIONS = [
        'enregistre' => ['affecte', 'rejete'],
        'en_attente_de_transfert' => ['en_cours_de_transfert', 'rejete'],
        'en_cours_de_transfert' => ['enregistre', 'en_attente_de_transfert', 'rejete'],
        'affecte' => ['en_traitement', 'en_attente_information', 'rejete'],
        'en_traitement' => ['en_validation', 'en_attente_information', 'rejete'],
        'en_validation' => ['traite', 'en_traitement', 'en_attente_information', 'rejete', 'en_attente_signature_dg'],
        'en_attente_information' => ['en_traitement'],
        // 2026-10-09 — extension du circuit générique unique (PAS un circuit
        // différent par type/département, voir DECISIONS.md "Signature DG /
        // Trésorerie" — entretien terrain Sinistres) : certains courriers
        // (ex. une facture) ont besoin de la signature DG avant clôture,
        // parfois suivie d'un passage en trésorerie (décaissement). Chemin
        // OPT-IN — un responsable qui n'a pas le privilège dédié continue de
        // clôturer directement via valider() comme avant, sans jamais passer
        // par ces deux statuts. Voir CourrierPolicy::envoyerSignatureDg()/
        // signerDg()/traiterTresorerie() pour le détail des privilèges.
        'en_attente_signature_dg' => ['traite', 'en_attente_tresorerie'],
        'en_attente_tresorerie' => ['traite'],
        // Module 9 — ajoutée le 2026-09-21 : "Traité" → "Archivé" devient
        // enfin une vraie transition, déclenchée par ArchiverCourriersTraitesJob
        // (voir archiverAutomatiquement() plus bas), pas par un bouton.
        'traite' => ['archive'],
    ];

    // Statuts où une personne affectée a la main — relance manuelle possible
    // (2026-10-30, voir relancer()).
    public const STATUTS_RELANCE = ['affecte', 'en_traitement', 'en_attente_information'];

    // 2026-09-21 — 'traite' a désormais une transition sortante (vers
    // 'archive', voir archiverAutomatiquement() plus bas) mais n'est PAS un
    // statut actif pour autant : un courrier clôturé ne doit pas être
    // recompté comme "en cours" (dashboard, file d'attente, charge par
    // collaborateur) simplement parce qu'il a un devenir automatique.
    // array_keys(TRANSITIONS) seul suffisait tant que 'traite' était un
    // cul-de-sac ; explicitement exclu maintenant qu'il ne l'est plus.
    public static function statutsActifs(): array
    {
        return array_diff(array_keys(self::TRANSITIONS), ['traite']);
    }

    // Module 6 — "si un collaborateur a déjà des courriers en cours et un
    // autre n'en a aucun, le système affecte automatiquement au moins
    // chargé" : calcule la charge (courriers actifs actuellement affectés)
    // de chaque collaborateur d'un service, pour présélectionner le moins
    // chargé dans le formulaire d'affectation — le responsable choisit
    // toujours librement avant de valider (jamais d'automatisme imposé sans
    // validation, cohérent avec le reste du projet).
    //
    // @return Collection<int, int> user_id => nombre de courriers actifs
    public function chargeParCollaborateur(int $serviceId): Collection
    {
        return Courrier::query()
            ->whereIn('statut', self::statutsActifs())
            ->whereHas('affectationCourante.collaborateur', fn ($q) => $q->where('service_id', $serviceId))
            ->with('affectationCourante')
            ->get()
            ->groupBy(fn (Courrier $courrier) => $courrier->affectationCourante->user_id)
            ->map->count();
    }

    // Module 6 — première affectation d'un courrier fraîchement enregistré.
    public function affecter(Courrier $courrier, User $collaborateur, User $auteur, ?string $commentaire = null): void
    {
        $this->verifierTransition($courrier, 'affecte');

        DB::transaction(function () use ($courrier, $collaborateur, $auteur, $commentaire) {
            Affectation::create([
                'courrier_id' => $courrier->id,
                'user_id' => $collaborateur->id,
                'affecte_par_id' => $auteur->id,
                'motif_reaffectation' => null,
            ]);

            $courrier->update(['statut' => 'affecte']);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'affectation',
                'commentaire' => trim($collaborateur->name.($commentaire ? " — {$commentaire}" : '')),
            ]);
        });

        $this->diffuserChangementStatut($courrier->id, 'affecte');
        $this->notifier($collaborateur, $courrier, 'affectation', $auteur, $commentaire);
    }

    // Module 1/4 (2026-10-23, voir DECISIONS.md "Transfert direct au service
    // et acceptation") — l'accueil transfère un courrier entrant directement
    // au service concerné (plus de DGA). Le service est posé dès maintenant
    // (son responsable doit pouvoir voir l'enveloppe du courrier pour
    // décider), mais le CONTENU lui reste masqué jusqu'à l'acceptation (voir
    // CourrierPolicy::voirContenu()) et les fichiers ne quittent pas
    // "_en_attente" avant elle (voir accepterTransfert()). Le service doit
    // avoir un responsable : c'est lui qui accepte (Module 6 — un courrier a
    // toujours un responsable identifié). L'appelant a déjà vérifié le droit
    // de transférer (CourrierPolicy::transferer()) : WorkflowService reste la
    // couche transition/historique, pas la couche autorisation.
    public function transfererAuService(Courrier $courrier, Service $service, User $auteur): TransfertService
    {
        $this->verifierTransition($courrier, 'en_cours_de_transfert');

        if ($service->responsable_id === null) {
            throw new RuntimeException("Transfert impossible : le service « {$service->nom} » n'a pas de responsable.");
        }

        $transfert = DB::transaction(function () use ($courrier, $service, $auteur) {
            $courrier->update([
                'statut' => 'en_cours_de_transfert',
                'service_id' => $service->id,
            ]);

            $transfert = TransfertService::create([
                'courrier_id' => $courrier->id,
                'service_id' => $service->id,
                'transfere_par_id' => $auteur->id,
            ]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'transfert',
                'commentaire' => "Transféré au service {$service->nom}",
            ]);

            return $transfert;
        });

        $this->diffuserChangementStatut($courrier->id, 'en_cours_de_transfert');
        $this->notifier($service->responsable, $courrier, 'transfert', $auteur);

        return $transfert;
    }

    // Le responsable du service destinataire accepte : numéro de décharge
    // (preuve exigée avant la remise de l'original papier), fichiers
    // déplacés vers le dossier du service (Règle n°4), agent prévenu qu'il
    // peut remettre l'original. Ligne de transfert relue et verrouillée dans
    // la transaction : un double clic ou un second onglet ne décide jamais
    // deux fois (même principe que finaliserBrouillon()).
    public function accepterTransfert(Courrier $courrier, User $auteur): TransfertService
    {
        $this->verifierTransition($courrier, 'enregistre');

        $transfert = DB::transaction(function () use ($courrier, $auteur) {
            $transfert = $this->transfertEnAttenteVerrouille($courrier);

            $courrier->update(['statut' => 'enregistre']);

            $transfert->update([
                'statut' => TransfertService::ACCEPTE,
                'decide_par_id' => $auteur->id,
                'decide_le' => now(),
                'numero_decharge' => $transfert->genererNumeroDecharge(),
            ]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'transfert_accepte',
                'commentaire' => "Accepté par {$auteur->name} — décharge {$transfert->numero_decharge}",
            ]);

            return $transfert;
        });

        $this->diffuserChangementStatut($courrier->id, 'enregistre');

        // Hors transaction (stockage S3, non transactionnel) et non bloquant :
        // un échec laisse le fichier accessible à son ancien chemin plutôt
        // que d'annuler l'acceptation elle-même.
        $courrierFrais = $courrier->fresh(['service', 'piecesJointes']);
        $this->deplacerFichiersVersService($courrierFrais);
        $this->notifier($transfert->transferePar, $courrierFrais, 'transfert_accepte', $auteur);

        return $transfert;
    }

    // Le service refuse (motif obligatoire) : le courrier retourne à
    // l'accueil, sans service, pour être réorienté. Ses fichiers n'ont
    // jamais quitté "_en_attente" (déplacés seulement à l'acceptation) :
    // rien à défaire sur le stockage.
    public function refuserTransfert(Courrier $courrier, User $auteur, string $motif): TransfertService
    {
        $this->verifierTransition($courrier, 'en_attente_de_transfert');

        $transfert = DB::transaction(function () use ($courrier, $auteur, $motif) {
            $transfert = $this->transfertEnAttenteVerrouille($courrier);

            $courrier->update([
                'statut' => 'en_attente_de_transfert',
                'service_id' => null,
            ]);

            $transfert->update([
                'statut' => TransfertService::REFUSE,
                'decide_par_id' => $auteur->id,
                'decide_le' => now(),
                'motif_refus' => $motif,
            ]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'transfert_refuse',
                'commentaire' => "Refusé par {$auteur->name} (service {$transfert->service?->nom}) — motif : {$motif}",
            ]);

            return $transfert;
        });

        $this->diffuserChangementStatut($courrier->id, 'en_attente_de_transfert');
        $this->notifier($transfert->transferePar, $courrier, 'transfert_refuse', $auteur, $motif);

        return $transfert;
    }

    // L'accueil a remis l'original papier au service qui l'a accepté. Pas
    // une transition de circuit (le courrier peut déjà être affecté ou en
    // traitement) : seulement la ligne de transfert et une trace d'historique.
    // 2026-10-29 — la remise débloque la signature du service (ordre remise →
    // signature → affectation) : sa fiche ouverte ailleurs se rafraîchit.
    public function marquerOriginalRemis(Courrier $courrier, User $auteur): TransfertService
    {
        $transfert = DB::transaction(function () use ($courrier, $auteur) {
            $transfert = TransfertService::where('courrier_id', $courrier->id)
                ->where('statut', TransfertService::ACCEPTE)
                ->whereNull('original_remis_le')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($transfert === null) {
                throw new RuntimeException('Aucun transfert accepté en attente de remise de l\'original.');
            }

            $transfert->update([
                'original_remis_le' => now(),
                'original_remis_par_id' => $auteur->id,
            ]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'original_remis',
                'commentaire' => "Original papier remis au service {$transfert->service?->nom} (décharge {$transfert->numero_decharge})",
            ]);

            return $transfert;
        });

        $this->diffuserChangementStatut($courrier->id, $courrier->statut);

        return $transfert;
    }

    // 2026-10-28 (« mettre le nom comme signature au cas où on ne veut pas
    // imprimer ») — le service signe électroniquement la réception de
    // l'original : son nom et l'heure figurent dans le cadre « Reçu par » de
    // la décharge. Signature unique, tracée dans l'historique (Règle n°5).
    // L'ordre remise → signature (2026-10-29) est vérifié par l'appelant
    // (CourrierPolicy::signerReceptionOriginal()).
    public function signerReceptionOriginal(Courrier $courrier, User $auteur): TransfertService
    {
        $transfert = DB::transaction(function () use ($courrier, $auteur) {
            $transfert = TransfertService::where('courrier_id', $courrier->id)
                ->where('statut', TransfertService::ACCEPTE)
                ->whereNull('reception_signee_le')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($transfert === null) {
                throw new RuntimeException('Aucune décharge en attente de la signature de réception.');
            }

            $transfert->update([
                'reception_signee_le' => now(),
                'reception_signee_par_id' => $auteur->id,
            ]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'reception_original_signee',
                'commentaire' => "Réception de l'original signée électroniquement (décharge {$transfert->numero_decharge})",
            ]);

            return $transfert;
        });

        // La signature débloque l'affectation : fiches ouvertes rafraîchies.
        $this->diffuserChangementStatut($courrier->id, $courrier->statut);

        return $transfert;
    }

    // 2026-10-23 (voir DECISIONS.md "Transmission descendante par la
    // Direction") — le responsable d'une entité (ex. une Direction) transmet
    // un courrier accepté à une unité placée sous elle : le service change,
    // le courrier reste "À affecter" chez le chef de cette unité. Pas de
    // nouvelle décharge ni d'acceptation (le papier est déjà dans l'entité).
    // L'appelant a vérifié que $cible est bien une unité rattachée et le
    // droit de transmettre (Règle n°6) : WorkflowService reste la couche
    // transition/historique. Fichiers déplacés vers le dossier du nouveau
    // service (Règle n°4).
    public function transmettreAUnite(Courrier $courrier, Service $cible, User $auteur): void
    {
        if ($courrier->statut !== 'enregistre') {
            throw new RuntimeException("Transmission impossible depuis le statut « {$courrier->statut} ».");
        }

        if ($cible->id === $courrier->service_id || $cible->responsable_id === null) {
            throw new RuntimeException("Transmission impossible vers le service « {$cible->nom} ».");
        }

        $origine = $courrier->service?->nom;

        DB::transaction(function () use ($courrier, $cible, $auteur, $origine) {
            $courrier->update(['service_id' => $cible->id]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'transmission',
                'commentaire' => trim(($origine ? "Transmis par {$origine} " : 'Transmis ')."au service {$cible->nom}"),
            ]);
        });

        // Statut inchangé, mais la fiche ouverte ailleurs doit se rafraîchir
        // (nouveau responsable, nouveaux boutons).
        $this->diffuserChangementStatut($courrier->id, 'enregistre');

        $courrierFrais = $courrier->fresh(['service', 'piecesJointes']);
        $this->deplacerFichiersVersService($courrierFrais);
        $this->notifier($cible->responsable, $courrierFrais, 'transmission', $auteur);
    }

    private function transfertEnAttenteVerrouille(Courrier $courrier): TransfertService
    {
        $transfert = TransfertService::where('courrier_id', $courrier->id)
            ->where('statut', TransfertService::EN_ATTENTE)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($transfert === null) {
            throw new RuntimeException("Aucun transfert en attente de décision pour le courrier {$courrier->numero_reference}.");
        }

        return $transfert;
    }

    // Fichiers rangés sous un autre segment que celui du service actuel
    // (provisoire "_en_attente", ou le dossier d'un service qui a refusé
    // après une re-numérisation) — déplacés vers le vrai dossier du service
    // (Règle n°4 : courriers/{annee}/{service}/...), document principal et
    // pièces jointes.
    private function deplacerFichiersVersService(Courrier $courrier): void
    {
        $segment = $courrier->segmentClassement();

        if ($segment === Courrier::SEGMENT_SERVICE_EN_ATTENTE) {
            return;
        }

        if ($courrier->fichier_path !== null && ($nouveauChemin = self::cheminSousSegment($courrier->fichier_path, $segment)) !== null) {
            if ($this->deplacerFichier($courrier->fichier_path, $nouveauChemin)) {
                $courrier->update(['fichier_path' => $nouveauChemin]);
            }
        }

        foreach ($courrier->piecesJointes as $pieceJointe) {
            $nouveauChemin = self::cheminSousSegment($pieceJointe->fichier_path, $segment);

            if ($nouveauChemin !== null && $this->deplacerFichier($pieceJointe->fichier_path, $nouveauChemin)) {
                $pieceJointe->update(['fichier_path' => $nouveauChemin]);
            }
        }
    }

    // courriers/{annee}/{segment}/{reste} → même chemin sous $segment. Null
    // si le fichier y est déjà, ou s'il ne suit pas ce schéma de nommage
    // (jamais un chemin inventé pour un fichier rangé ailleurs).
    private static function cheminSousSegment(string $chemin, string $segment): ?string
    {
        $parties = explode('/', $chemin, 4);

        if (count($parties) !== 4 || $parties[0] !== 'courriers' || $parties[2] === $segment) {
            return null;
        }

        $parties[2] = $segment;

        return implode('/', $parties);
    }

    // Retourne true seulement si le déplacement a réellement eu lieu —
    // l'appelant ne doit mettre à jour fichier_path que dans ce cas, jamais
    // pointer vers un chemin où le fichier n'est pas réellement arrivé.
    private function deplacerFichier(string $ancien, string $nouveau): bool
    {
        try {
            if (! Storage::disk('s3')->exists($ancien)) {
                // Rien à l'ancien chemin : pas encore scanné, OU déjà déplacé
                // (requête concurrente, tentative précédente dont la mise à
                // jour de fichier_path n'a pas abouti). Dans ce second cas le
                // fichier est déjà à destination : on se recale dessus plutôt
                // que de laisser fichier_path pointer dans le vide — constaté
                // le 2026-09-24 (GEC-2026-000003 : fichier dans DIS/, chemin
                // resté sous _en_attente/, aperçu en erreur 500).
                return Storage::disk('s3')->exists($nouveau);
            }

            Storage::disk('s3')->move($ancien, $nouveau);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        // Règle n°4 (complétée) — même geste que RegistrationForm::finaliserBrouillon()
        // pour la copie de secours : jamais bloquant si absente/non configurée.
        // Séparé du déplacement principal (2026-09-24) : un échec ICI faisait
        // renvoyer false alors que le fichier principal AVAIT été déplacé —
        // fichier_path restait alors sur l'ancien chemin, désormais vide.
        try {
            if (filled(config('filesystems.disks.s3_backup.bucket')) && Storage::disk('s3_backup')->exists($ancien)) {
                Storage::disk('s3_backup')->move($ancien, $nouveau);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    // Module 6 — "réaffectation possible à tout moment, avec motif obligatoire".
    // Ne change pas le statut du circuit (une réaffectation en cours de
    // traitement reste "en_traitement", par ex.) : seule la ligne d'affectation
    // change, append-only comme le reste de la traçabilité (Règle n°5).
    public function reaffecter(Courrier $courrier, User $nouveauCollaborateur, User $auteur, string $motif): void
    {
        if (! in_array($courrier->statut, ['affecte', 'en_traitement', 'en_validation', 'en_attente_information'], true)) {
            throw new RuntimeException("Réaffectation impossible depuis le statut « {$courrier->statut} ».");
        }

        $ancienCollaborateur = $courrier->affectationCourante?->collaborateur;

        DB::transaction(function () use ($courrier, $nouveauCollaborateur, $auteur, $motif) {
            Affectation::create([
                'courrier_id' => $courrier->id,
                'user_id' => $nouveauCollaborateur->id,
                'affecte_par_id' => $auteur->id,
                'motif_reaffectation' => $motif,
            ]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'reaffectation',
                'commentaire' => "{$nouveauCollaborateur->name} — motif : {$motif}",
            ]);
        });

        $this->notifier($nouveauCollaborateur, $courrier, 'reaffectation_nouveau', $auteur, $motif);
        // Jamais le même utilisateur des deux côtés (réaffecté à soi-même
        // après une correction de motif, ex.) — une seule notification suffit.
        if ($ancienCollaborateur && $ancienCollaborateur->id !== $nouveauCollaborateur->id) {
            $this->notifier($ancienCollaborateur, $courrier, 'reaffectation_ancien', $auteur, $motif);
        }
    }

    // Module 4 — le collaborateur affecté commence effectivement le traitement.
    public function demarrerTraitement(Courrier $courrier, User $auteur): void
    {
        $this->transitionnerSimple($courrier, 'en_traitement', $auteur, 'traitement_demarre');
    }

    // Module 4 — le collaborateur soumet sa réponse/action pour validation hiérarchique.
    public function soumettrePourValidation(Courrier $courrier, User $auteur, ?string $commentaire = null): void
    {
        $this->transitionnerSimple($courrier, 'en_validation', $auteur, 'soumis_validation', $commentaire, $courrier->service?->responsable);
    }

    // Module 4 — "un supérieur hiérarchique valide" → clôture (Traité).
    public function valider(Courrier $courrier, User $auteur, ?string $commentaire = null): void
    {
        $this->transitionnerSimple($courrier, 'traite', $auteur, 'validation_acceptee', $commentaire, $courrier->affectationCourante?->collaborateur);
    }

    // Module 4 — "ou renvoie pour correction" → retour en traitement, motif obligatoire.
    public function renvoyerPourCorrection(Courrier $courrier, User $auteur, string $motif): void
    {
        $this->transitionnerSimple($courrier, 'en_traitement', $auteur, 'validation_refusee', $motif, $courrier->affectationCourante?->collaborateur);
    }

    // 2026-10-09 — alternative à valider() : au lieu de clôturer directement,
    // le responsable envoie le courrier en signature DG (ex. une facture).
    // Réservée aux responsables ayant courriers.envoyer_signature_dg (opt-in
    // par département, voir CourrierPolicy::envoyerSignatureDg()) — jamais
    // un circuit différent, seulement une transition supplémentaire de la
    // MÊME machine à états générique (voir commentaire TRANSITIONS).
    public function envoyerPourSignatureDg(Courrier $courrier, User $auteur, ?string $commentaire = null): void
    {
        $this->transitionnerSimple($courrier, 'en_attente_signature_dg', $auteur, 'envoye_signature_dg', $commentaire, $this->utilisateursAvecPrivilege('courriers.dga_signer'));
    }

    // La DG signe ET clôture directement — courrier qui n'avait besoin que
    // d'une signature, pas d'un passage en trésorerie (ex. un accord).
    public function signerEtCloturer(Courrier $courrier, User $auteur, ?string $commentaire = null): void
    {
        $this->transitionnerSimple($courrier, 'traite', $auteur, 'signe_dg_cloture', $commentaire, $this->auteurDeLaDerniereAction($courrier, 'envoye_signature_dg'));
    }

    // La DG signe ET transmet à la trésorerie — courrier de décaissement
    // (ex. une facture à payer).
    public function signerEtEnvoyerTresorerie(Courrier $courrier, User $auteur, ?string $commentaire = null): void
    {
        $this->transitionnerSimple($courrier, 'en_attente_tresorerie', $auteur, 'signe_dg_tresorerie', $commentaire, $this->utilisateursAvecPrivilege('courriers.traiter_tresorerie'));
    }

    // La trésorerie clôture une fois le décaissement traité — prévient la
    // DG qui avait signé (confirmation de clôture du dossier).
    public function traiterParTresorerie(Courrier $courrier, User $auteur, ?string $commentaire = null): void
    {
        $this->transitionnerSimple($courrier, 'traite', $auteur, 'traite_tresorerie', $commentaire, $this->auteurDeLaDerniereAction($courrier, 'signe_dg_tresorerie'));
    }

    // Statut "En attente d'information" du spec Module 4 — ex. en attente
    // d'une pièce complémentaire de l'expéditeur. Motif obligatoire (traçabilité).
    public function mettreEnAttente(Courrier $courrier, User $auteur, string $motif): void
    {
        $this->transitionnerSimple($courrier, 'en_attente_information', $auteur, 'mise_en_attente', $motif, $this->autrePartie($courrier, $auteur));
    }

    public function reprendre(Courrier $courrier, User $auteur): void
    {
        $this->transitionnerSimple($courrier, 'en_traitement', $auteur, 'reprise', null, $this->autrePartie($courrier, $auteur));
    }

    // Module 7 (2026-10-30, « courrier en traitement qui part vers le retard
    // doit faire des relances… on doit pouvoir les relancer manuellement
    // aussi ») — relance MANUELLE de la personne affectée, en plus des
    // relances automatiques de SendMailAlertJob. Pas une transition : le
    // statut ne change pas, seule une trace (Règle n°5) et une notification
    // en file d'attente (Règle n°1) sont créées.
    public function relancer(Courrier $courrier, User $auteur, ?string $message = null): User
    {
        $courrier->refresh();
        $affecte = $courrier->affectationCourante?->collaborateur;

        if (! in_array($courrier->statut, self::STATUTS_RELANCE, true) || $affecte === null) {
            throw new RuntimeException("Relance impossible : courrier {$courrier->numero_reference} au statut « {$courrier->statut} » ou sans personne affectée.");
        }

        CourrierHistorique::create([
            'courrier_id' => $courrier->id,
            'auteur_id' => $auteur->id,
            'action' => 'relance',
            'commentaire' => $message !== null && $message !== ''
                ? "Relance envoyée à {$affecte->name} — {$message}"
                : "Relance envoyée à {$affecte->name}",
        ]);

        $this->notifier($affecte, $courrier, 'relance', $auteur, $message ?: null);

        return $affecte;
    }

    // Statut "Rejeté" du spec Module 4 — dérogation explicite tracée (motif
    // obligatoire) plutôt qu'un simple abandon silencieux.
    public function rejeter(Courrier $courrier, User $auteur, string $motif): void
    {
        $this->transitionnerSimple($courrier, 'rejete', $auteur, 'rejet', $motif, $courrier->affectationCourante?->collaborateur);
    }

    // Le signataire (DG) d'une étape antérieure (envoye_signature_dg/
    // signe_dg_tresorerie) — reconstitué depuis l'historique (append-only,
    // Règle n°5), jamais un champ dédié supplémentaire sur `courriers`.
    private function auteurDeLaDerniereAction(Courrier $courrier, string $action): ?User
    {
        return CourrierHistorique::where('courrier_id', $courrier->id)
            ->where('action', $action)
            ->latest()
            ->first()
            ?->auteur;
    }

    // "L'autre partie" du binôme responsable/collaborateur pour une action
    // que l'un OU l'autre peut déclencher (mettreEnAttente()/reprendre(),
    // CourrierPolicy::mettreEnAttente()/reprendre() acceptent traiter() OU
    // valider()) — jamais l'auteur notifié de sa propre action.
    private function autrePartie(Courrier $courrier, User $auteur): Collection
    {
        return collect([$courrier->service?->responsable, $courrier->affectationCourante?->collaborateur])
            ->filter()
            ->reject(fn (User $u) => $u->id === $auteur->id)
            ->unique('id')
            ->values();
    }

    // Module 5 (2026-09-24, voir DECISIONS.md "Chronomètre de traitement") —
    // le responsable fixe le délai de traitement : le chronomètre part (ou
    // repart) MAINTENANT pour $minutes. La date limite SLA (date_limite, via
    // echeance) suit la fin du chronomètre, pour que listes, alertes et
    // tableau de bord restent cohérents. Tracé dans l'historique (Règle n°5),
    // motif conservé en cas de modification.
    public function fixerDelai(Courrier $courrier, int $minutes, User $auteur, ?string $motif = null): void
    {
        if (! in_array($courrier->statut, self::statutsActifs(), true)) {
            throw new RuntimeException("Délai non modifiable depuis le statut « {$courrier->statut} ».");
        }

        $modification = $courrier->chrono_fin_le !== null;
        $fin = now()->addMinutes($minutes);

        DB::transaction(function () use ($courrier, $fin, $auteur, $motif, $modification, $minutes) {
            $courrier->update([
                'chrono_debut_le' => $courrier->chrono_debut_le ?? now(),
                'chrono_fin_le' => $fin,
                'echeance' => $fin->toDateString(),
            ]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => $modification ? 'delai_modifie' : 'delai_fixe',
                'commentaire' => trim(self::libelleDuree($minutes).' — échéance le '.$fin->format('d/m/Y H:i').($motif ? " — motif : {$motif}" : '')),
            ]);
        });
    }

    public static function libelleDuree(int $minutes): string
    {
        return $minutes % (24 * 60) === 0
            ? trans_choice(':n jour|:n jours', intdiv($minutes, 24 * 60), ['n' => intdiv($minutes, 24 * 60)])
            : trans_choice(':n heure|:n heures', intdiv($minutes, 60), ['n' => intdiv($minutes, 60)]);
    }

    // Module 1/9 (2026-09-24, voir DECISIONS.md "Courrier confidentiel : accès
    // et clôture par le destinataire") — le destinataire d'un pli
    // confidentiel le marque comme remis : 'enregistre' → 'traite', puis
    // archivage automatique comme tout courrier traité. Volontairement HORS
    // de TRANSITIONS : y ajouter 'enregistre' → 'traite' permettrait à
    // valider() de sauter tout le circuit d'un courrier normal ("un courrier
    // ne peut pas sauter une étape obligatoire") — vérifié ici, pour ce seul
    // type de courrier.
    public function cloturerConfidentiel(Courrier $courrier, User $auteur): void
    {
        if (! $courrier->confidentiel_direct || $courrier->statut !== 'enregistre') {
            throw new RuntimeException("Clôture impossible : courrier non confidentiel ou statut « {$courrier->statut} ».");
        }

        DB::transaction(function () use ($courrier, $auteur) {
            $courrier->update(['statut' => 'traite', 'chrono_arrete_le' => now()]);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => 'confidentiel_remis',
                'commentaire' => 'Pli confidentiel marqué comme remis par son destinataire.',
            ]);
        });

        $this->diffuserChangementStatut($courrier->id, 'traite');
    }

    // $destinataires : Règle n°1 étendue (2026-10-09, audit "list me all")
    // — la notification de qui est concerné par CETTE transition précise,
    // résolue par chaque méthode publique ci-dessus (qui connaît le bon
    // destinataire), jamais devinée ici. null = personne à notifier
    // (ex. demarrerTraitement(), action purement auto-concernante).
    private function transitionnerSimple(Courrier $courrier, string $statutSuivant, User $auteur, string $action, ?string $commentaire = null, User|Collection|null $destinataires = null): void
    {
        $this->verifierTransition($courrier, $statutSuivant);

        DB::transaction(function () use ($courrier, $statutSuivant, $auteur, $action, $commentaire) {
            // Chronomètre figé à la clôture (2026-09-24) : le temps réellement
            // passé reste affiché ensuite, même une fois le courrier archivé.
            $courrier->update(array_merge(
                ['statut' => $statutSuivant],
                in_array($statutSuivant, ['traite', 'rejete'], true) ? ['chrono_arrete_le' => now()] : [],
            ));

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $auteur->id,
                'action' => $action,
                'commentaire' => $commentaire,
            ]);
        });

        $this->diffuserChangementStatut($courrier->id, $statutSuivant);
        $this->notifier($destinataires, $courrier, $action, $auteur, $commentaire);
    }

    // Jamais un utilisateur notifié de sa PROPRE action (ex. le responsable
    // qui vient de mettre lui-même le courrier en attente) — filtré ici une
    // seule fois plutôt que dans chaque appelant. $auteur (2026-10-12,
    // demande explicite — "make each notification show who send it") :
    // qui a réellement déclenché l'action, stocké dans l'enveloppe de la
    // notification pour affichage sur la page Notifications — jamais
    // utilisé pour une logique métier ici, uniquement transmis.
    private function notifier(User|Collection|null $destinataires, Courrier $courrier, string $action, ?User $auteur = null, ?string $commentaire = null): void
    {
        $destinataires = match (true) {
            $destinataires === null => collect(),
            $destinataires instanceof User => collect([$destinataires]),
            default => $destinataires,
        };

        // 2026-10-30 (« if it is the responsible doing the traitement some of
        // the workflow process should not send notif ») — le filtre promis
        // ci-dessus n'était pas réellement appliqué : le responsable qui
        // traite lui-même recevait « Un courrier vous a été affecté », puis
        // « soumis pour validation » et « validé » sur ses propres actions.
        $destinataires = $destinataires
            ->filter()
            ->reject(fn (User $user) => $auteur !== null && $user->id === $auteur->id)
            ->unique('id')
            ->values();

        if ($destinataires->isEmpty()) {
            return;
        }

        try {
            Notification::send($destinataires, new CourrierNotification($courrier, $action, $commentaire, $auteur));
        } catch (Throwable $e) {
            report($e);
        }
    }

    // Utilisé pour les 2 étapes du circuit Signature DG/Trésorerie sans
    // destinataire nominatif unique en base (contrairement à
    // transfererAuService(), qui vise le responsable du service) — tout utilisateur ayant le
    // privilège concerné est prévenu, même principe que
    // OrganisationIndex pour "qui peut agir ici".
    private function utilisateursAvecPrivilege(string $cle): Collection
    {
        return User::query()->get()->filter(fn (User $u) => $u->hasPrivilege($cle))->values();
    }

    // Diffusion "au mieux" (best-effort) du changement de statut — jamais
    // laisser un souci de canal temps réel (Reverb non joignable, coupure
    // réseau...) faire échouer la transition elle-même, qui est déjà commitée
    // en base à ce stade. Constaté le 2026-09-18 : `CourrierStatutChange`
    // est `ShouldBroadcastNow` (diffusion synchrone) et
    // `Illuminate\Broadcasting\BroadcastException` étend `RuntimeException`
    // — sans ce garde-fou, une simple coupure du serveur Reverb en local
    // remontait jusqu'à `ShowCourrier::executer()` et était affichée à
    // l'utilisateur comme "la fiche a changé entre-temps", alors que
    // l'action avait en réalité parfaitement réussi. Même principe que
    // `deplacerFichier()` ci-dessus pour les échecs de déplacement S3.
    // Module 9 — clôture automatique "Traité" → "Archivé" ("un courrier
    // clôturé est automatiquement transféré vers l'archivage"). Aucun
    // auteur humain : convention "système" déjà utilisée par
    // ProcessDocumentOcr (auteur_id: null). Appelée UNIQUEMENT par
    // ArchiverCourriersTraitesJob — jamais par un contrôleur/composant. Pas
    // d'autorisation ici : ce n'est pas CourrierPolicy::archive() (réservée
    // à un futur archivage manuel), et l'appelant est le système lui-même,
    // pas un utilisateur agissant (même principe déjà en place partout
    // ailleurs dans ce service : l'autorisation est la responsabilité de
    // l'appelant, WorkflowService reste la couche transition/historique).
    public function archiverAutomatiquement(Courrier $courrier): void
    {
        $this->verifierTransition($courrier, 'archive');

        DB::transaction(function () use ($courrier) {
            // 2026-10-30 (conformité de l'archivage) — date d'archivage et
            // échéance de conservation (ConservationService).
            $courrier->update(['statut' => 'archive', 'archive_le' => now()]);
            app(ConservationService::class)->appliquer($courrier);

            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => null,
                'action' => 'archivage_automatique',
                'commentaire' => 'Archivé automatiquement (courrier traité).',
            ]);
        });

        $this->diffuserChangementStatut($courrier->id, 'archive');

        // 2026-10-30 — copie d'archive PDF/A, en file d'attente (Règle n°1).
        if (config('services.archivage.pdfa_auto')) {
            ConvertirPdfaJob::dispatch($courrier->id)->afterCommit();
        }
    }

    // Module 9 — "Décharge" (specifications-modules-GEC.md point 5) : émise
    // quand quelqu'un emprunte l'original physique d'un courrier archivé.
    // Ne change PAS le statut du courrier (reste "archive" tout du long) —
    // ce n'est pas une transition de circuit, juste un reçu de garde
    // physique superposé. Un seul emprunt actif à la fois par courrier
    // (verrou applicatif ici, en plus de dechargeActive() côté lecture) :
    // on ne peut pas prêter le même original à deux personnes en même temps.
    // Requête fraîche (jamais $courrier->dechargeActive, la relation EN
    // MÉMOIRE peut avoir été chargée/mise en cache à "null" par un appelant
    // AVANT la création de cette décharge — constaté en test, deux appels
    // successifs sur la même instance $courrier réutilisaient le cache).
    public function emettreDecharge(Courrier $courrier, User $emprunteur, User $emisPar, ?string $motif = null): Decharge
    {
        if ($courrier->statut !== 'archive') {
            throw new RuntimeException("Décharge impossible : le courrier n'est pas archivé (statut « {$courrier->statut} »).");
        }

        if (Decharge::where('courrier_id', $courrier->id)->whereNull('rendu_le')->exists()) {
            throw new RuntimeException('Décharge impossible : l\'original est déjà emprunté et non rendu.');
        }

        $decharge = DB::transaction(function () use ($courrier, $emprunteur, $emisPar, $motif) {
            $decharge = Decharge::create([
                'courrier_id' => $courrier->id,
                'emprunteur_id' => $emprunteur->id,
                'emis_par_id' => $emisPar->id,
                'lieu_rangement' => $courrier->dossierClassement?->reference_localisation_physique,
                'motif' => $motif,
                'emprunte_le' => now(),
            ]);

            // Règle métier Module 9 : "Toute décharge émise doit être
            // tracée dans l'historique du courrier concerné". `created()`
            // (voir Decharge::booted()) a déjà rempli numero_reference sur
            // CETTE instance au moment où Decharge::create() revient ici.
            CourrierHistorique::create([
                'courrier_id' => $courrier->id,
                'auteur_id' => $emisPar->id,
                'action' => 'decharge_emise',
                'commentaire' => "Décharge {$decharge->numero_reference} émise — original emprunté par {$emprunteur->name}.",
            ]);

            return $decharge;
        });

        $this->notifier($emprunteur, $courrier, 'decharge_emise', $emisPar);

        return $decharge;
    }

    public function marquerDechargeRendue(Decharge $decharge, User $auteur): void
    {
        if ($decharge->estRendue()) {
            throw new RuntimeException('Cette décharge est déjà marquée comme rendue.');
        }

        DB::transaction(function () use ($decharge, $auteur) {
            $decharge->update(['rendu_le' => now()]);

            CourrierHistorique::create([
                'courrier_id' => $decharge->courrier_id,
                'auteur_id' => $auteur->id,
                'action' => 'decharge_rendue',
                'commentaire' => "Décharge {$decharge->numero_reference} — original rendu.",
            ]);
        });

        $this->notifier($decharge->emisPar, $decharge->courrier, 'decharge_rendue', $auteur);
    }

    private function diffuserChangementStatut(int $courrierId, string $statut): void
    {
        try {
            CourrierStatutChange::dispatch($courrierId, $statut);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // "Un courrier ne peut pas sauter une étape obligatoire" (Règle métier
    // Module 4) : vérifié ici indépendamment de ce que la vue affiche — un ID
    // de courrier/statut ne doit jamais être fait confiance côté client (Règle n°6).
    private function verifierTransition(Courrier $courrier, string $statutSuivant): void
    {
        $autorises = self::TRANSITIONS[$courrier->statut] ?? [];

        if (! in_array($statutSuivant, $autorises, true)) {
            throw new RuntimeException("Transition « {$courrier->statut} » → « {$statutSuivant} » non autorisée.");
        }
    }
}
