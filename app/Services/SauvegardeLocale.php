<?php

namespace App\Services;

use App\Jobs\SauvegarderDocumentsLocalementJob;
use App\Models\Courrier;
use App\Models\DossierClassement;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

// 2026-11-11 (« je les veux aussi dans le pc comme ça ils seront là comme un
// point de sauvegarde si un jour l'appli crash on pourra récupérer les doc »)
// — copie de secours HORS de l'application, rangée comme les dossiers de
// classement, récupérable sans GEC : un ZIP chiffré AES-256 par courrier,
// qui s'ouvre avec 7-Zip ou WinRAR (choix de l'utilisateur : « Protégés par
// mot de passe », « Tout »). Puis (« je veux que ce soit les login password
// … plus un password universel donc passe-partout », choix « Mot de passe de
// sauvegarde par personne + passe-partout ») — deux espaces :
//
//   {dossier}/Tout (passe-partout)/Dossiers/{parent}/{dossier}/{référence}.zip
//   {dossier}/Tout (passe-partout)/Non classés/{service}/{année}/{référence}.zip
//   {dossier}/Personnes/{nom} (n°{id})/…  même rangement, limité à ce que la
//     personne voit dans GEC (Courrier::scopeVisiblePar(), même règle que
//     l'application) ; un PDF protégé par courrier qui s'ouvre avec SON mot
//     de passe de sauvegarde OU le passe-partout (« un mot de passe auquel
//     si j'entre elle pourra l'ouvrir sans le mot de passe de l'utilisateur »,
//     choix « Même fichier, deux mots de passe (PDF) ») — chiffré par qpdf.
//
// Le mot de passe de CONNEXION n'est pas utilisable : GEC n'en garde qu'une
// empreinte illisible, et un ZIP ne s'ouvre qu'avec un seul mot de passe.
//
// Chaque ZIP : le document, les pièces jointes et fiche.txt. Synchronisation
// incrémentale par espace : réécrit seulement ce qui a changé, déplace un
// courrier reclassé, supprime la copie d'un courrier supprimé, détruit à
// échéance (ConservationService::detruire()) ou devenu invisible pour la
// personne ; la copie d'une personne disparaît avec son mot de passe de
// sauvegarde ou son compte.
class SauvegardeLocale
{
    public const CLE_ETAT = 'gec.sauvegarde_locale.etat';

    public const DOSSIER_TOUT = 'Tout (passe-partout)';

    public const DOSSIER_PERSONNES = 'Personnes';

    private const MANIFESTE = '.gec-manifeste.json';

    private const MANIFESTE_PERSONNES = '.gec-personnes.json';

    public function active(): bool
    {
        return filled($this->racine()) && filled($this->motDePasse());
    }

    public function racine(): ?string
    {
        $dossier = config('services.sauvegarde_locale.dossier');

        return filled($dossier) ? rtrim((string) $dossier, '\\/') : null;
    }

    // 2026-11-11 (« est-ce qu'elle serait créée automatiquement chaque fois
    // qu'un utilisateur va créer un dossier ») — appelé par les événements
    // des modèles (AppServiceProvider) : la copie suit en quelques secondes
    // au lieu d'attendre le passage de chaque heure. Après validation de la
    // transaction, pour que la synchronisation voie le changement.
    public static function signalerChangement(): void
    {
        if (app(self::class)->active()) {
            SauvegarderDocumentsLocalementJob::dispatch()->afterCommit();
        }
    }

    /**
     * Compteurs de la copie complète, plus le nombre de copies personnelles ;
     * « erreurs » additionne les deux.
     *
     * @return array{ecrits: int, deplaces: int, supprimes: int, inchanges: int, erreurs: int, personnes: int}
     */
    public function synchroniser(): array
    {
        if (! $this->active()) {
            throw new RuntimeException('Copie de secours sur le PC désactivée : renseigner GEC_SAUVEGARDE_DOSSIER et GEC_SAUVEGARDE_MOT_DE_PASSE.');
        }

        $racine = $this->racine();
        File::ensureDirectoryExists($racine);
        $this->migrerAncienneDisposition($racine);

        $cheminsDossiers = $this->cheminsDossiers();

        $stats = $this->synchroniserEspace(
            $racine.'/'.self::DOSSIER_TOUT,
            Courrier::query(),
            $this->motDePasse(),
            $cheminsDossiers,
            array_keys($cheminsDossiers),
        );

        [$personnes, $erreursPersonnes] = $this->synchroniserPersonnes($racine, $cheminsDossiers);
        $stats['personnes'] = $personnes;
        $stats['erreurs'] += $erreursPersonnes;

        $this->ecrireLisezMoi($racine);

        return $stats;
    }

    private function motDePasse(): ?string
    {
        $motDePasse = config('services.sauvegarde_locale.mot_de_passe');

        return filled($motDePasse) ? (string) $motDePasse : null;
    }

    // Avant le 2026-11-11 (copie unique) : Dossiers/, Non classés/ et le
    // manifeste à la racine → déplacés tels quels dans « Tout
    // (passe-partout) », sans réécrire aucun ZIP (les chemins du manifeste
    // sont relatifs à l'espace).
    private function migrerAncienneDisposition(string $racine): void
    {
        if (! is_file($racine.'/'.self::MANIFESTE)) {
            return;
        }

        $tout = $racine.'/'.self::DOSSIER_TOUT;
        File::ensureDirectoryExists($tout);

        foreach (['Dossiers', 'Non classés', self::MANIFESTE] as $element) {
            if (file_exists($racine.'/'.$element) && ! file_exists($tout.'/'.$element)) {
                rename($racine.'/'.$element, $tout.'/'.$element);
            }
        }
    }

    /**
     * Un espace = un dossier, un mot de passe, les courriers qu'il contient.
     * Avec un mot de passe « propriétaire » (copies personnelles, 2026-11-11 :
     * « un mot de passe auquel si j'entre elle pourra l'ouvrir sans le mot
     * de passe de l'utilisateur ») : un PDF protégé par courrier, qui s'ouvre
     * avec l'un OU l'autre ; sinon un ZIP chiffré.
     *
     * @param  array<int, string>  $cheminsDossiers  [dossier_id => « Parent/Enfant »]
     * @param  list<int>  $dossiersVisibles  dossiers créés dans l'espace, même vides
     * @return array{ecrits: int, deplaces: int, supprimes: int, inchanges: int, erreurs: int}
     */
    private function synchroniserEspace(string $espace, Builder $courriers, string $motDePasse, array $cheminsDossiers, array $dossiersVisibles, ?string $motDePasseProprietaire = null): array
    {
        File::ensureDirectoryExists($espace);

        $manifeste = $this->lireJson($espace.'/'.self::MANIFESTE);
        $extension = $motDePasseProprietaire === null ? 'zip' : 'pdf';
        // Le passe-partout entre dans la signature des PDF (le changer les
        // rechiffre), avec la version du format (« lecture-seule » : un PDF
        // écrit avant les restrictions est réécrit) ; celle des ZIP reste
        // celle d'avant (aucune réécriture).
        $cleMotDePasse = hash_hmac('sha256', $motDePasseProprietaire === null ? $motDePasse : $motDePasse."\0".$motDePasseProprietaire."\0lecture-seule", (string) config('app.key'));
        $stats = ['ecrits' => 0, 'deplaces' => 0, 'supprimes' => 0, 'inchanges' => 0, 'erreurs' => 0];
        $vus = [];

        (clone $courriers)
            ->with(['service', 'dossierClassement', 'piecesJointes'])
            ->where(fn ($q) => $q->whereNotNull('fichier_path')->orWhereHas('piecesJointes'))
            ->chunkById(100, function ($lot) use ($espace, $motDePasse, $motDePasseProprietaire, $extension, $cheminsDossiers, $cleMotDePasse, &$manifeste, &$stats, &$vus) {
                foreach ($lot as $courrier) {
                    $vus[$courrier->id] = true;
                    $chemin = $this->cheminRelatif($courrier, $cheminsDossiers, $extension);
                    $signature = $this->signature($courrier, $chemin, $cleMotDePasse);
                    $ancien = $manifeste[$courrier->id] ?? null;

                    if ($ancien && $ancien['signature'] === $signature && is_file($espace.'/'.$ancien['chemin'])) {
                        $stats['inchanges']++;

                        continue;
                    }

                    try {
                        $motDePasseProprietaire === null
                            ? $this->ecrireArchive($espace.'/'.$chemin, $courrier, $motDePasse)
                            : $this->ecrirePdf($espace.'/'.$chemin, $courrier, $motDePasse, $motDePasseProprietaire);
                    } catch (Throwable $e) {
                        report($e);
                        $stats['erreurs']++;

                        continue;
                    }

                    if ($ancien && $ancien['chemin'] !== $chemin) {
                        $this->supprimerFichier($espace, $ancien['chemin']);
                        $stats['deplaces']++;
                    } else {
                        $stats['ecrits']++;
                    }

                    $manifeste[$courrier->id] = ['chemin' => $chemin, 'signature' => $signature];
                }
            }, 'courriers.id', 'id');

        // Courriers disparus de l'espace (supprimés, détruits à échéance,
        // devenus invisibles pour la personne) : leur copie aussi.
        foreach (array_diff_key($manifeste, $vus) as $id => $entree) {
            $this->supprimerFichier($espace, $entree['chemin']);
            unset($manifeste[$id]);
            $stats['supprimes']++;
        }

        File::put($espace.'/'.self::MANIFESTE, json_encode($manifeste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Chaque dossier visible a son dossier sur le PC, même vide
        // (« partenaire ») ; ceux qui n'existent plus dans GEC (renommés,
        // supprimés, plus partagés) disparaissent quand ils sont vides.
        $aGarder = [];
        foreach ($dossiersVisibles as $id) {
            if (isset($cheminsDossiers[$id])) {
                File::ensureDirectoryExists($espace.'/Dossiers/'.$cheminsDossiers[$id]);
                $aGarder[str_replace('\\', '/', $espace.'/Dossiers/'.$cheminsDossiers[$id])] = true;
            }
        }

        $this->retirerDossiersVides($espace.'/Dossiers', $aGarder);
        $this->retirerDossiersVides($espace.'/Non classés');

        return $stats;
    }

    /**
     * Une copie par personne active ayant choisi un mot de passe de
     * sauvegarde ; retirée avec ce mot de passe ou avec le compte.
     *
     * @return array{0: int, 1: int} [copies tenues à jour, erreurs]
     */
    private function synchroniserPersonnes(string $racine, array $cheminsDossiers): array
    {
        $dossierPersonnes = $racine.'/'.self::DOSSIER_PERSONNES;
        $manifeste = $this->lireJson($racine.'/'.self::MANIFESTE_PERSONNES);
        $dossiers = DossierClassement::query()->get();
        $vus = [];
        $copies = 0;
        $erreurs = 0;

        foreach (User::query()->where('actif', true)->whereNotNull('mot_de_passe_sauvegarde')->get() as $user) {
            try {
                $motDePasse = $user->mot_de_passe_sauvegarde;
            } catch (Throwable $e) {
                // Illisible (clé de l'application changée) : la personne devra le redéfinir.
                report($e);
                $erreurs++;

                continue;
            }

            if (blank($motDePasse)) {
                continue;
            }

            $nom = self::nomPropre($user->name)." (n°{$user->id})";
            $ancien = $manifeste[$user->id] ?? null;

            // Nom de la personne changé : son dossier suit.
            if ($ancien && $ancien !== $nom && self::nomSur($ancien) && is_dir($dossierPersonnes.'/'.$ancien) && ! file_exists($dossierPersonnes.'/'.$nom)) {
                rename($dossierPersonnes.'/'.$ancien, $dossierPersonnes.'/'.$nom);
            }

            $dossiersVisibles = $dossiers->filter(fn (DossierClassement $dossier) => $user->can('view', $dossier))->modelKeys();
            $stats = $this->synchroniserEspace($dossierPersonnes.'/'.$nom, Courrier::query()->visiblePar($user), $motDePasse, $cheminsDossiers, $dossiersVisibles, $this->motDePasse());
            $this->ecrireLisezMoiPersonne($dossierPersonnes.'/'.$nom);

            $erreurs += $stats['erreurs'];
            $copies++;
            $manifeste[$user->id] = $nom;
            $vus[$user->id] = true;
        }

        // Mot de passe de sauvegarde retiré, compte désactivé ou supprimé :
        // sa copie aussi.
        foreach (array_diff_key($manifeste, $vus) as $id => $nom) {
            if (self::nomSur($nom)) {
                File::deleteDirectory($dossierPersonnes.'/'.$nom);
            }

            unset($manifeste[$id]);
        }

        File::ensureDirectoryExists($dossierPersonnes);
        File::put($racine.'/'.self::MANIFESTE_PERSONNES, json_encode($manifeste, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$copies, $erreurs];
    }

    // Dossier classé : son chemin dans l'arbre des dossiers. Non classé (ou
    // dossier supprimé) : par service puis par année d'enregistrement.
    private function cheminRelatif(Courrier $courrier, array $cheminsDossiers, string $extension): string
    {
        $fichier = self::nomPropre($courrier->numero_reference).'.'.$extension;

        if ($courrier->dossier_classement_id !== null && isset($cheminsDossiers[$courrier->dossier_classement_id])) {
            return 'Dossiers/'.$cheminsDossiers[$courrier->dossier_classement_id].'/'.$fichier;
        }

        return 'Non classés/'.self::nomPropre($courrier->service?->nom ?? 'Sans service').'/'.$courrier->created_at->format('Y').'/'.$fichier;
    }

    /** @return array<int, string> [dossier_id => « Parent/Enfant »] */
    private function cheminsDossiers(): array
    {
        $dossiers = DossierClassement::query()->get(['id', 'nom', 'parent_id'])->keyBy('id');

        // Deux dossiers frères de même nom (après nettoyage) : le numéro les distingue.
        $noms = [];
        foreach ($dossiers->groupBy(fn ($d) => $d->parent_id.'|'.mb_strtolower(self::nomPropre($d->nom))) as $freres) {
            foreach ($freres as $dossier) {
                $noms[$dossier->id] = self::nomPropre($dossier->nom).($freres->count() > 1 ? " (n°{$dossier->id})" : '');
            }
        }

        $chemins = [];
        foreach ($dossiers as $dossier) {
            $parties = [];
            $courant = $dossier;

            // Garde-fou : jamais plus de 20 niveaux (boucle parent_id impossible à suivre).
            for ($niveau = 0; $courant && $niveau < 20; $niveau++) {
                array_unshift($parties, $noms[$courant->id]);
                $courant = $courant->parent_id ? $dossiers->get($courant->parent_id) : null;
            }

            $chemins[$dossier->id] = implode('/', $parties);
        }

        return $chemins;
    }

    // Tout ce qui change le contenu ou la place du ZIP ; le mot de passe y
    // entre sous forme de HMAC (jamais en clair, ni simple empreinte, dans le
    // manifeste) pour que son changement rechiffre toutes les archives.
    private function signature(Courrier $courrier, string $chemin, string $cleMotDePasse): string
    {
        return sha1(json_encode([
            $chemin,
            $cleMotDePasse,
            $courrier->updated_at?->toIso8601String(),
            $courrier->fichier_path,
            $courrier->dossierClassement?->nom,
            $courrier->piecesJointes->map(fn ($p) => [$p->id, $p->fichier_path, $p->updated_at?->toIso8601String()])->all(),
        ]));
    }

    private function ecrireArchive(string $cible, Courrier $courrier, string $motDePasse): void
    {
        File::ensureDirectoryExists(dirname($cible));
        $temporaire = $cible.'.tmp';
        $disque = Storage::disk('s3');

        $zip = new ZipArchive;
        if ($zip->open($temporaire, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Archive impossible à créer : {$temporaire}");
        }

        $entrees = [];
        $absents = [];

        if ($courrier->fichier_path !== null) {
            if ($disque->exists($courrier->fichier_path)) {
                $nom = 'document.'.(pathinfo($courrier->fichier_path, PATHINFO_EXTENSION) ?: 'pdf');
                $zip->addFromString($nom, $disque->get($courrier->fichier_path));
                $entrees[$nom] = __('Document principal');
            } else {
                $absents[] = __('Document principal');
            }
        }

        foreach ($courrier->piecesJointes->values() as $index => $piece) {
            if ($disque->exists($piece->fichier_path)) {
                $nom = 'pieces-jointes/'.($index + 1).'.'.(pathinfo($piece->fichier_path, PATHINFO_EXTENSION) ?: 'pdf');
                $zip->addFromString($nom, $disque->get($piece->fichier_path));
                $entrees[$nom] = $piece->nom_original;
            } else {
                $absents[] = $piece->nom_original;
            }
        }

        $zip->addFromString('fiche.txt', $this->fiche($courrier, $entrees, $absents));

        // Noms neutres dans l'archive (le nom d'origine d'une pièce peut en
        // dire long) : la correspondance est dans fiche.txt, chiffrée aussi.
        foreach ([...array_keys($entrees), 'fiche.txt'] as $nom) {
            $zip->setEncryptionName($nom, ZipArchive::EM_AES_256, $motDePasse);
        }

        if (! $zip->close()) {
            throw new RuntimeException("Archive impossible à écrire : {$temporaire}");
        }

        if (! rename($temporaire, $cible)) {
            @unlink($temporaire);

            throw new RuntimeException("Archive impossible à mettre en place : {$cible}");
        }
    }

    // Copie personnelle : un seul PDF par courrier — fiche, document, pièces
    // jointes PDF ou images —, chiffré AES-256 par qpdf avec deux mots de
    // passe (celui de la personne et le passe-partout) : n'importe quel
    // lecteur PDF l'ouvre avec l'un OU l'autre. Les autres pièces (Word,
    // Excel…) ne se mettent pas en PDF : la fiche renvoie à la copie « Tout ».
    private function ecrirePdf(string $cible, Courrier $courrier, string $motDePasse, string $motDePasseProprietaire): void
    {
        File::ensureDirectoryExists(dirname($cible));
        $travail = sys_get_temp_dir().'/gec-pdf-'.Str::uuid();
        File::ensureDirectoryExists($travail);
        $disque = Storage::disk('s3');

        try {
            $sources = [];
            if ($courrier->fichier_path !== null) {
                $sources[] = [$courrier->fichier_path, __('Document principal')];
            }
            foreach ($courrier->piecesJointes as $piece) {
                $sources[] = [$piece->fichier_path, __('Pièce jointe : :nom', ['nom' => $piece->nom_original])];
            }

            $morceaux = [];
            $inclus = [];
            $horsPdf = [];
            $absents = [];

            foreach ($sources as $index => [$chemin, $libelle]) {
                if (! $disque->exists($chemin)) {
                    $absents[] = $libelle;

                    continue;
                }

                $extension = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));
                $fichier = "{$travail}/{$index}.pdf";

                if ($extension === 'pdf') {
                    File::put($fichier, $disque->get($chemin));
                } elseif (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
                    $image = 'data:image/'.($extension === 'png' ? 'png' : 'jpeg').';base64,'.base64_encode($disque->get($chemin));
                    File::put($fichier, Pdf::loadHTML('<img src="'.$image.'" style="max-width:100%;max-height:100%">')->setPaper('a4')->output());
                } else {
                    $horsPdf[] = $libelle;

                    continue;
                }

                $morceaux[] = $fichier;
                $inclus[] = $libelle;
            }

            $fiche = "{$travail}/fiche.pdf";
            File::put($fiche, Pdf::loadView('pdf.fiche-sauvegarde', [
                'courrier' => $courrier,
                'lignes' => $this->lignesFiche($courrier),
                'inclus' => $inclus,
                'horsPdf' => $horsPdf,
                'absents' => $absents,
            ])->setPaper('a4')->output());

            // Arguments dans un fichier (« @fichier ») : les mots de passe
            // n'apparaissent jamais dans la liste des processus du serveur.
            // 2026-11-11 (« ils voient les fichiers sans les extraire … on ne
            // peut pas le télécharger ou imprimer ») — ouvert avec le mot de
            // passe de la personne : lecture seule (impression, copie,
            // modification bloquées par le lecteur PDF) ; avec le
            // passe-partout (propriétaire) : tous les droits.
            $arguments = "{$travail}/arguments";
            File::put($arguments, implode("\n", [
                '--empty',
                '--encrypt', $motDePasse, $motDePasseProprietaire, '256',
                '--print=none', '--modify=none', '--extract=n', '--annotate=n', '--form=n', '--assemble=n',
                '--',
                '--pages', $fiche, ...$morceaux, '--',
                $cible.'.tmp',
            ])."\n");

            $qpdf = new Process([(string) config('services.sauvegarde_locale.qpdf', 'qpdf'), '@'.$arguments]);
            $qpdf->setTimeout(300);
            $qpdf->run();

            // 0 : réussi ; 3 : réussi avec avertissements (PDF source abîmé mais lisible).
            if (! in_array($qpdf->getExitCode(), [0, 3], true) || ! is_file($cible.'.tmp')) {
                throw new RuntimeException("qpdf a échoué pour {$courrier->numero_reference} : ".trim($qpdf->getErrorOutput()));
            }

            if (! rename($cible.'.tmp', $cible)) {
                throw new RuntimeException("PDF impossible à mettre en place : {$cible}");
            }
        } finally {
            if (is_file($cible.'.tmp')) {
                unlink($cible.'.tmp');
            }

            File::deleteDirectory($travail);
        }
    }

    /** Ce qu'est le courrier, pour le retrouver sans GEC : [libellé => valeur]. */
    private function lignesFiche(Courrier $courrier): array
    {
        $correspondant = $courrier->sens === 'entrant'
            ? ($courrier->expediteur_organisation ?: $courrier->expediteur_nom)
            : $courrier->destinataire;

        return [
            __('Référence') => $courrier->numero_reference,
            __('Sens') => $courrier->sens === 'entrant' ? __('Entrant') : __('Sortant'),
            __('Type') => $courrier->type_document,
            __('Objet') => $courrier->objet,
            ($courrier->sens === 'entrant' ? __('Expéditeur') : __('Destinataire')) => $correspondant ?: '—',
            __('Date de réception') => $courrier->date_mouvement?->format('d/m/Y'),
            __('Service') => $courrier->service?->nom ?? '—',
            __('Dossier') => $courrier->dossierClassement?->nom ?? __('Non classé'),
            __('Statut') => Courrier::libelleStatut($courrier->statut),
            __('Confidentialité') => __('niveau :n', ['n' => $courrier->confidentialite]),
        ];
    }

    private function fiche(Courrier $courrier, array $entrees, array $absents): string
    {
        $lignes = collect($this->lignesFiche($courrier))->map(fn ($valeur, $libelle) => "{$libelle} : {$valeur}")->values()->all();
        $lignes[] = '';
        $lignes[] = __('Fichiers').' :';

        foreach ($entrees as $nom => $origine) {
            $lignes[] = "  - {$nom} ← {$origine}";
        }

        foreach ($absents as $origine) {
            $lignes[] = '  - '.__(':nom : introuvable sur le stockage au moment de la copie', ['nom' => $origine]);
        }

        $lignes[] = '';
        $lignes[] = __('Copie faite le :quand', ['quand' => now()->format('d/m/Y à H:i')]);

        return implode("\r\n", $lignes)."\r\n";
    }

    private function ecrireLisezMoi(string $racine): void
    {
        $texte = implode("\r\n", [
            __('GEC — copie de secours des courriers'),
            '',
            __('Ce dossier est mis à jour automatiquement par GEC (chaque heure et après chaque changement). Ne le modifiez pas à la main.'),
            '',
            __('« Tout (passe-partout) » : tous les courriers, un ZIP chiffré (AES-256) par courrier. S\'ouvre avec WinRAR ou 7-Zip et le passe-partout gardé par l\'administrateur ; fiche.txt, dans chaque ZIP, décrit le courrier.'),
            __('« Personnes » : une copie par personne, limitée aux courriers qu\'elle voit dans GEC. Un PDF protégé (AES-256) par courrier, avec la fiche en première page, qui s\'ouvre sans rien extraire (Edge, Chrome, Adobe) : avec le mot de passe de sauvegarde de la personne (GEC › Paramètres › Mon profil), en lecture seule — impression et copie bloquées ; avec le passe-partout, tous les droits.'),
            '',
            __('Dans chaque copie : « Dossiers » (rangés comme les dossiers de classement de GEC) et « Non classés » (par service puis par année).'),
            '',
            __('Un courrier supprimé ou détruit à la fin de sa durée de conservation disparaît aussi de ces copies.'),
        ])."\r\n";

        File::put($racine.'/LISEZ-MOI.txt', $texte);

        // L'ancien LISEZ-MOI de la copie unique, s'il est resté dans l'espace.
        if (is_file($racine.'/'.self::DOSSIER_TOUT.'/LISEZ-MOI.txt')) {
            @unlink($racine.'/'.self::DOSSIER_TOUT.'/LISEZ-MOI.txt');
        }
    }

    private function ecrireLisezMoiPersonne(string $espace): void
    {
        File::put($espace.'/LISEZ-MOI.txt', implode("\r\n", [
            __('Copie personnelle : les courriers visibles par ce compte dans GEC.'),
            __('Chaque PDF s\'ouvre directement dans Edge, Chrome ou Adobe avec le mot de passe de sauvegarde de ce compte (GEC › Paramètres › Mon profil), en lecture seule — impression et copie bloquées —, ou avec le passe-partout de l\'administrateur.'),
        ])."\r\n");
    }

    private function lireJson(string $chemin): array
    {
        $contenu = is_file($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;

        return is_array($contenu) ? $contenu : [];
    }

    // Jamais en dehors de l'espace, même avec un manifeste abîmé.
    private function supprimerFichier(string $espace, string $relatif): void
    {
        if (str_contains($relatif, '..')) {
            return;
        }

        $chemin = $espace.'/'.$relatif;

        if (is_file($chemin)) {
            @unlink($chemin);
        }
    }

    // Nom de dossier de personne lu dans un manifeste : jamais un chemin.
    private static function nomSur(string $nom): bool
    {
        return $nom !== '' && ! str_contains($nom, '..') && ! preg_match('#[\\\\/]#', $nom);
    }

    /** @param  array<string, true>  $aGarder  chemins (avec « / ») à ne jamais retirer */
    private function retirerDossiersVides(string $dossier, array $aGarder = []): void
    {
        if (! is_dir($dossier)) {
            return;
        }

        foreach (File::directories($dossier) as $sousDossier) {
            $this->retirerDossiersVides($sousDossier, $aGarder);

            if (! isset($aGarder[str_replace('\\', '/', $sousDossier)]) && File::isEmptyDirectory($sousDossier)) {
                @rmdir($sousDossier);
            }
        }
    }

    // Nom utilisable comme dossier/fichier Windows : « 12/10/2026 - Offre
    // Pro » → « 12-10-2026 - Offre Pro ».
    public static function nomPropre(string $nom): string
    {
        $propre = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]+/u', '-', $nom);
        $propre = trim(preg_replace('/\s+/u', ' ', (string) $propre), " .\t");
        $propre = Str::limit($propre !== '' ? $propre : 'Sans nom', 80, '');

        // Noms réservés par Windows (un dossier « CON » ne peut pas être créé).
        return preg_match('/^(CON|PRN|AUX|NUL|COM\d|LPT\d)$/i', $propre) ? $propre.'_' : $propre;
    }
}
