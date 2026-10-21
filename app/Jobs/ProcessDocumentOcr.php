<?php

namespace App\Jobs;

use App\Events\OcrTermine;
use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\Parametre;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use thiagoalessio\TesseractOCR\Command as TesseractCommand;
use thiagoalessio\TesseractOCR\FriendlyErrors as TesseractFriendlyErrors;
use thiagoalessio\TesseractOCR\Process as TesseractProcess;
use thiagoalessio\TesseractOCR\TesseractOCR;
use thiagoalessio\TesseractOCR\UnsuccessfulCommandException;
use Throwable;

class ProcessDocumentOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    // Rendu Ghostscript à 600 dpi + double passage Tesseract par page
    // (normal + inversé, voir texteBanniereInversee()) le 2026-09-18 :
    // le timeout par défaut du worker de queue (souvent 60s) ne suffit
    // plus, surtout sur un document multi-pages — explicite plutôt
    // qu'implicite, pour ne jamais faire tuer puis réessayer un job en
    // cours d'exécution normale (gaspillage, pas une vraie panne).
    public int $timeout = 300;

    // Module 2 — contrôle qualité ("vérification que le scan est lisible ;
    // sinon, re-scan demandé") : en dessous de ces seuils, le résultat est
    // marqué `echec_qualite`. La confiance vient des scores par mot de
    // Tesseract. Configurables depuis l'UI ("Group A", 2026-09-24) —
    // ex-`const`, voir DECISIONS.md "Paramètres système configurables —
    // Groupe A/B".
    public static function longueurMinimaleTexte(): int
    {
        return Parametre::actuel()->ocr_longueur_minimale_texte;
    }

    public static function confianceMinimale(): int
    {
        return Parametre::actuel()->ocr_confiance_minimale;
    }

    public function __construct(public Courrier $courrier) {}

    public function handle(): void
    {
        $this->courrier->update(['ocr_statut' => 'en_cours']);

        $extension = strtolower(pathinfo($this->courrier->fichier_path, PATHINFO_EXTENSION) ?: 'pdf');
        $fichierTemporaire = tempnam(sys_get_temp_dir(), 'gec_ocr_').'.'.$extension;

        try {
            file_put_contents($fichierTemporaire, Storage::disk('s3')->get($this->courrier->fichier_path));

            self::corrigerOrientation($fichierTemporaire, $extension);

            [$texte, $confiance] = self::ocrFichier($fichierTemporaire, $extension);

            $lisible = mb_strlen($texte) >= self::longueurMinimaleTexte()
                && $confiance !== null
                && $confiance >= self::confianceMinimale();

            $this->courrier->update([
                'texte_ocr' => $texte !== '' ? $texte : null,
                'ocr_statut' => $lisible ? 'reussi' : 'echec_qualite',
                'ocr_confiance' => $confiance,
                'ocr_traite_le' => now(),
                // Module 1/2 — indicatif seulement (voir DECISIONS.md) : n'alimente
                // jamais numero_reference, généré et garanti unique par ailleurs
                // (Règle n°3). Tenté même si le corps du texte est de qualité
                // insuffisante : le tampon est une petite zone, potentiellement
                // lisible même quand le reste ne l'est pas.
                'numero_tampon_detecte' => self::extraireNumeroTampon($texte),
            ]);

            // Règle n°5 — traçabilité immuable du résultat du traitement.
            CourrierHistorique::create([
                'courrier_id' => $this->courrier->id,
                'auteur_id' => null,
                'action' => $lisible ? 'ocr_reussi' : 'ocr_qualite_insuffisante',
                'commentaire' => $lisible
                    ? "Confiance moyenne : {$confiance} %."
                    : self::motifQualite($texte, $confiance),
            ]);

            $this->notifier($lisible ? 'reussi' : 'echec_qualite', $confiance);

            // Module 3 — le texte complet permet un classement plus précis qu'à
            // l'enregistrement (objet/expéditeur seuls).
            if ($lisible) {
                IndexCourrierJob::dispatch($this->courrier)->onQueue('indexation');
            }
        } finally {
            if (file_exists($fichierTemporaire)) {
                unlink($fichierTemporaire);
            }
        }
    }

    // Règle n°1 — échec loggé de façon exploitable, jamais un catch silencieux.
    // Le détail technique va dans le log ; l'historique (visible par l'agent
    // sur la fiche) reçoit une explication lisible.
    public function failed(?Throwable $exception): void
    {
        Log::error('Échec du traitement OCR', [
            'courrier_id' => $this->courrier->id,
            'numero_reference' => $this->courrier->numero_reference,
            'erreur' => $exception?->getMessage(),
        ]);

        $this->courrier->update(['ocr_statut' => 'echec']);

        CourrierHistorique::create([
            'courrier_id' => $this->courrier->id,
            'auteur_id' => null,
            'action' => 'ocr_echec',
            'commentaire' => self::messageLisible($exception),
        ]);

        $this->notifier('echec');
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    // Règle n°1 (seconde moitié) — notification temps réel à la fin du job.
    // Best-effort : un serveur Reverb arrêté ne doit jamais faire échouer un OCR réussi.
    private function notifier(string $statut, ?int $confiance = null): void
    {
        try {
            OcrTermine::dispatch($this->courrier->id, $statut, $confiance);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // Point d'entrée OCR unique, réutilisé par ProcessBrouillonOcr. Un PDF est
    // d'abord converti page par page en PNG via Ghostscript (voir
    // convertirPdfEnImages ci-dessous — ce build de Tesseract ne sait pas lire
    // un PDF lui-même, malgré le message d'erreur qui l'évoque) ; le texte des
    // pages est concaténé, la confiance moyennée sur l'ensemble des mots
    // reconnus. Sans Ghostscript configuré, un PDF est quand même tenté tel
    // quel (comportement historique : échoue proprement, voir messageLisible()).
    //
    // @return array{0: string, 1: ?int} [texte, confiance moyenne]
    //
    // 2026-10-08 — toutes les pages d'un PDF multi-pages sont désormais
    // OCRisées EN PARALLÈLE entre elles (retour utilisateur : toujours trop
    // lent malgré la parallélisation des deux passes PAR page, voir
    // ocrToutesPagesEnParallele() ci-dessous) — avant, cette boucle
    // traitait les pages l'une après l'autre ; sur un document de 5 pages,
    // mesuré en conditions réelles, ce changement fait passer le total
    // d'environ 33,6s à environ 23,7s (Ghostscript + inversion restent
    // intrinsèquement liés aux 600 dpi, incompressibles sans rouvrir ce
    // compromis — voir le journal de développement). Toujours AUCUN changement de
    // précision : mêmes pages, même double passage, juste plus de
    // processus système lancés en même temps.
    public static function ocrFichier(string $chemin, string $extension): array
    {
        self::augmenterLimiteMemoireSiNecessaire();

        $pagesGenerees = $extension === 'pdf' ? self::convertirPdfEnImages($chemin) : [];
        $fichiers = $pagesGenerees ?: [$chemin];
        $fichiersInverses = [];

        try {
            foreach ($fichiers as $i => $fichierPage) {
                $fichiersInverses[$i] = self::inverserImage($fichierPage);
            }

            $textes = [];
            $confiances = [];

            foreach (self::ocrToutesPagesEnParallele($fichiers, $fichiersInverses) as [$textePage, $confiancePage, $texteBanniere]) {
                if ($texteBanniere !== null) {
                    $textePage = trim($textePage."\n\n".$texteBanniere);
                }

                if ($textePage !== '') {
                    $textes[] = $textePage;
                }

                if ($confiancePage !== null) {
                    $confiances[] = $confiancePage;
                }
            }

            return [
                trim(implode("\n\n", $textes)),
                $confiances === [] ? null : (int) round(array_sum($confiances) / count($confiances)),
            ];
        } finally {
            foreach ($pagesGenerees as $page) {
                if (file_exists($page)) {
                    unlink($page);
                }
            }

            foreach ($fichiersInverses as $fichierInverse) {
                if ($fichierInverse !== null && file_exists($fichierInverse)) {
                    unlink($fichierInverse);
                }
            }
        }
    }

    // Le rendu Ghostscript à 600 dpi (voir convertirPdfEnImages() ci-dessous)
    // produit des images PNG nettement plus lourdes une fois décompressées
    // en mémoire par GD (inverserImage()) — la limite mémoire PHP par
    // défaut (souvent 128M) ne suffit plus et fait planter le job avec une
    // erreur fatale "Allowed memory size exhausted" (constaté en
    // conditions réelles, 2026-09-18, en rejouant l'OCR sur un vrai
    // document). Relève UNIQUEMENT la limite du processus PHP COURANT
    // (ini_set, jamais php.ini global) et seulement si elle est plus basse
    // que nécessaire — ne réduit jamais une limite déjà plus généreuse
    // (notamment "-1", illimité) déjà configurée par l'environnement.
    private static function augmenterLimiteMemoireSiNecessaire(): void
    {
        $limiteActuelle = ini_get('memory_limit');

        if ($limiteActuelle === '-1' || ! preg_match('/^(\d+)([KMG]?)$/i', trim($limiteActuelle), $correspondance)) {
            return;
        }

        $multiplicateurs = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3];
        $octetsActuels = (int) $correspondance[1] * $multiplicateurs[strtoupper($correspondance[2])];
        $octetsCibles = 512 * 1024 * 1024;

        if ($octetsActuels < $octetsCibles) {
            ini_set('memory_limit', '512M');
        }
    }

    // Convertit un PDF en une image PNG par page via Ghostscript, 600 dpi
    // (doublé depuis 300 dpi le 2026-09-18, demande explicite de
    // l'utilisateur : un bandeau de pied de page trop fin restait illisible
    // par Tesseract même après inversion des couleurs — voir
    // texteBanniereInversee() — la cause n'étant pas la polarité des
    // couleurs mais la hauteur en pixels du texte à 300 dpi, insuffisante
    // pour que l'analyse de mise en page de Tesseract reconnaisse même une
    // ligne de texte à cet endroit). Accepté : fichiers PNG intermédiaires
    // ~4x plus lourds et temps de conversion/OCR plus long pour chaque
    // document, pas seulement ceux ayant ce problème. Timeout Ghostscript
    // doublé en conséquence (120s, contre 60s à 300 dpi).
    private static function convertirPdfEnImages(string $cheminPdf): array
    {
        $binaire = config('services.ghostscript.binary');

        if (blank($binaire)) {
            return [];
        }

        $prefixe = tempnam(sys_get_temp_dir(), 'gec_pdf_page_');
        unlink($prefixe); // tempnam() crée un fichier vide — seul le nom sert de préfixe unique.
        $motif = $prefixe.'-%d.png';

        try {
            $resultat = Process::timeout(120)->run([
                $binaire, '-dNOPAUSE', '-dBATCH', '-dSAFER',
                '-sDEVICE=png16m', '-r600',
                '-o', $motif,
                $cheminPdf,
            ]);
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        if ($resultat->failed()) {
            Log::warning('Conversion PDF→image (Ghostscript) échouée', ['erreur' => $resultat->errorOutput()]);

            return [];
        }

        $pages = glob($prefixe.'-*.png') ?: [];
        sort($pages, SORT_NATURAL);

        return $pages;
    }

    // Module 1/2 — deuxième passe OCR sur une copie de la page aux couleurs
    // inversées, demande explicite de l'utilisateur (2026-09-18) : un
    // bandeau clair-sur-sombre (texte blanc sur fond bleu marine/vert
    // sombre) est illisible par Tesseract dans la passe normale — vu à
    // deux reprises sur de vrais documents ("Raison sociale"/NIU
    // illisibles sur TBG/CMR/NSIA, RC/NIU illisibles sur MEGATIM/AFG BANK,
    // 2026-09-17/18). Inverser TOUTE la page transforme ce bandeau en texte
    // sombre-sur-clair NORMAL, que Tesseract lit alors correctement.
    // Seuil de confiance élevé (70, voir extraireTexteEtConfiance()) sur
    // cette seconde passe : une fois la page ENTIÈRE inversée, le texte
    // normal (sombre sur clair à l'origine) devient à son tour
    // clair-sur-sombre et redevient ILLISIBLE pour Tesseract — seul le
    // texte du bandeau (désormais normal) obtient un score de confiance
    // élevé, tout le bruit généré par l'inversion du reste de la page est
    // filtré. Ce texte supplémentaire est ANNEXÉ au texte de la passe
    // normale (ocrFichier() ci-dessus), jamais utilisé pour recalculer la
    // confiance globale du document (qui reste basée sur la passe normale
    // uniquement — le but est de récupérer des fragments isolés, pas de
    // juger la qualité globale du scan). Best-effort à tous les niveaux :
    // ne lève jamais d'exception, renvoie simplement null si l'inversion
    // ou cette seconde passe échoue pour une raison quelconque (extension
    // GD absente, format d'image non géré, échec Tesseract...).
    //
    // 2026-10-08 — toutes les pages, toutes les passes, lancées EN MÊME
    // TEMPS (retour utilisateur : toujours "trop lent" après une première
    // parallélisation limitée à la paire normale/inversée D'UNE SEULE
    // page). Jusqu'à 2×N processus Tesseract système simultanés pour un
    // document de N pages — mesuré sur un vrai document de 5 pages : ~8s
    // d'attente pour les 10 processus (contre ~5×5s en série avant). AUCUN
    // changement de précision : mêmes pages, même double passage par page,
    // seul le nombre de processus lancés EN MÊME TEMPS augmente. Attention
    // production (Règle n°1) : chaque OCR peut donc brièvement solliciter
    // jusqu'à 2×N cœurs — avec plusieurs brouillons traités en parallèle
    // par Horizon, le pic de charge CPU se cumule ; acceptable sur un poste
    // de dev à 6 cœurs/12 threads (mesuré), à surveiller si le volume réel
    // en pilote s'avère plus élevé.
    // thiagoalessio\TesseractOCR\Process démarre déjà le process système au
    // constructeur (proc_open) et ne bloque qu'à wait() — construire TOUS
    // les processus avant d'attendre le premier suffit à les faire tourner
    // en parallèle au niveau du système d'exploitation ; chaque wait()
    // suivant ne fait qu'attendre un processus déjà en cours, jamais en
    // relancer un nouveau en série. Reconstruit volontairement nous-mêmes
    // le "démarrage puis attente" que fait TesseractOCR::run() (lui,
    // bloquant, les deux étapes combinées en un seul appel — impossible à
    // chevaucher tel quel) en RÉUTILISANT les briques PUBLIQUES de la
    // bibliothèque (Command déjà construit par
    // ->lang()/->psm()/->configFile()/->tessdataDir(), Process,
    // FriendlyErrors::checkCommandExecution()) : zéro logique de
    // construction de commande ou de classification d'erreur dupliquée,
    // seul l'ORDRE démarrage/attente change.
    //
    // @param  array<int, string>  $fichiers  pages PNG (une par page du PDF, ou le fichier original pour une image seule)
    // @param  array<int, ?string>  $fichiersInverses  même index que $fichiers, null si l'inversion a échoué pour cette page
    // @return array<int, array{0: string, 1: ?int, 2: ?string}> une entrée par page, même ordre/index que $fichiers — [texte normal, confiance normale, texte bannière ou null]
    private static function ocrToutesPagesEnParallele(array $fichiers, array $fichiersInverses): array
    {
        $ocrNormaux = [];
        $ocrInverses = [];
        $processusNormaux = [];
        $processusInverses = [];

        foreach ($fichiers as $i => $fichierPage) {
            $ocrNormaux[$i] = self::construireTesseract($fichierPage);
        }

        // Même exécutable pour toutes les pages — une seule vérification suffit.
        TesseractFriendlyErrors::checkTesseractPresence($ocrNormaux[array_key_first($ocrNormaux)]->command->executable);

        try {
            // Construit TOUS les processus (normaux ET inversés, toutes
            // pages confondues) avant d'attendre le premier — voir le
            // commentaire ci-dessus, c'est ce qui les fait tourner en
            // parallèle au niveau du système d'exploitation.
            foreach ($fichiers as $i => $fichierPage) {
                TesseractFriendlyErrors::checkImagePath($ocrNormaux[$i]->command->image);
                $processusNormaux[$i] = new TesseractProcess((string) $ocrNormaux[$i]->command);

                if ($fichiersInverses[$i] !== null) {
                    $ocrInverses[$i] = self::construireTesseract($fichiersInverses[$i]);
                    $processusInverses[$i] = new TesseractProcess((string) $ocrInverses[$i]->command);
                }
            }

            $resultats = [];

            foreach ($fichiers as $i => $fichierPage) {
                $sortieNormale = $processusNormaux[$i]->wait();
                $sortieInverse = ($processusInverses[$i] ?? null)?->wait();

                try {
                    TesseractFriendlyErrors::checkCommandExecution($ocrNormaux[$i]->command, $sortieNormale['out'], $sortieNormale['err']);
                    $tsvNormal = file_get_contents($ocrNormaux[$i]->command->getOutputFile());
                } catch (UnsuccessfulCommandException $e) {
                    // Tesseract n'a rien lu (manuscrit, page blanche) : résultat
                    // de qualité, pas une panne — pas de retry, page suivante.
                    if (! self::estUneAbsenceDeTexte($e)) {
                        throw $e;
                    }

                    $tsvNormal = '';
                }

                [$textePage, $confiancePage] = self::extraireTexteEtConfiance($tsvNormal);

                $texteBanniere = null;

                if (isset($ocrInverses[$i])) {
                    try {
                        TesseractFriendlyErrors::checkCommandExecution($ocrInverses[$i]->command, $sortieInverse['out'], $sortieInverse['err']);
                        $tsvInverse = file_get_contents($ocrInverses[$i]->command->getOutputFile());

                        [$texte] = self::extraireTexteEtConfiance($tsvInverse, seuilConfianceMot: 70);

                        $texteBanniere = $texte === '' ? null : $texte;
                    } catch (Throwable) {
                        // Best-effort — même comportement que l'ancienne
                        // texteBanniereInversee() : ne remonte jamais, simple
                        // fragment de bannière en moins.
                    }
                }

                $resultats[$i] = [$textePage, $confiancePage, $texteBanniere];
            }

            return $resultats;
        } finally {
            foreach ($ocrNormaux as $ocr) {
                self::nettoyerSortieTesseract($ocr->command);
            }

            foreach ($ocrInverses as $ocr) {
                self::nettoyerSortieTesseract($ocr->command);
            }
        }
    }

    // Factorisé (2026-10-08) : la passe normale et la passe bannière
    // inversée utilisaient avant des réglages dupliqués à l'identique.
    private static function construireTesseract(string $fichier): TesseractOCR
    {
        $ocr = (new TesseractOCR($fichier))
            ->executable(config('services.tesseract.binary'))
            ->lang('fra', 'eng')
            ->psm(1)
            ->configFile('tsv');

        if ($tessdata = config('services.tesseract.tessdata')) {
            $ocr->tessdataDir($tessdata);
        }

        return $ocr;
    }

    // Équivalent du cleanTempFiles() privé de TesseractOCR (inaccessible
    // depuis ici) — supprime le fichier de sortie tsv généré par Tesseract,
    // avec et sans extension (mêmes deux chemins que la bibliothèque nettoie
    // elle-même normalement dans run()).
    private static function nettoyerSortieTesseract(TesseractCommand $command): void
    {
        foreach ([$command->getOutputFile(false), $command->getOutputFile(true)] as $chemin) {
            if (file_exists($chemin)) {
                unlink($chemin);
            }
        }
    }

    // GD (quasi systématiquement disponible, contrairement à Imagick) —
    // seuls PNG (toutes les pages issues de Ghostscript) et JPEG (photos de
    // téléphone) sont gérés ; un format non reconnu ou l'absence de GD
    // renvoie simplement null (best-effort, voir texteBanniereInversee()).
    private static function inverserImage(string $chemin): ?string
    {
        if (! function_exists('imagefilter')) {
            return null;
        }

        $extension = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));

        $image = match ($extension) {
            'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($chemin) : false,
            'jpg', 'jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($chemin) : false,
            default => false,
        };

        if ($image === false) {
            return null;
        }

        imagefilter($image, IMG_FILTER_NEGATE);

        $prefixe = tempnam(sys_get_temp_dir(), 'gec_ocr_inverse_');
        unlink($prefixe);
        $cheminInverse = $prefixe.'.png';

        // Niveau 3 (2026-10-08, mesuré en conditions réelles sur une page
        // 600 dpi) — PAS le défaut de imagepng() (6) : fichier temporaire
        // ~3x plus lourd mais écrit ~15% plus vite, sans AUCUNE perte
        // (PNG reste sans perte quel que soit le niveau) — Tesseract lit le
        // fichier décompressé, peu importe comment il a été compressé.
        // Testé aussi 0 (pas de compression du tout) : PAS plus rapide
        // (le coût dominant est le décodage/filtrage des pixels par GD,
        // pas l'écriture), juste un fichier temporaire ~400x plus lourd —
        // 3 reste le meilleur compromis mesuré.
        imagepng($image, $cheminInverse, 3);
        imagedestroy($image);

        return $cheminInverse;
    }

    // Reconstruit le texte (mots → lignes → paragraphes) et la confiance moyenne
    // à partir de la sortie TSV de Tesseract : level page block par line word
    // left top width height conf text. Seules les lignes de niveau 5 sont des mots.
    // $seuilConfianceMot : seuil minimal (0-100) sous lequel un mot est
    // ignoré — 0 par défaut (comportement d'origine, tout mot reconnu est
    // gardé). Utilisé avec un seuil élevé par texteBanniereInversee()
    // ci-dessus pour ne garder QUE les fragments fiables d'une passe OCR
    // sur une page inversée (voir son commentaire).
    public static function extraireTexteEtConfiance(string $tsv, float $seuilConfianceMot = 0): array
    {
        $texte = '';
        $confiances = [];
        $derniereLigne = null;
        $dernierParagraphe = null;

        foreach (preg_split('/\r\n|\r|\n/', $tsv) as $ligne) {
            $colonnes = explode("\t", $ligne);

            if (count($colonnes) < 12 || $colonnes[0] !== '5') {
                continue;
            }

            $mot = trim($colonnes[11]);
            $confiance = (float) $colonnes[10];

            if ($mot === '' || $confiance < 0 || $confiance < $seuilConfianceMot) {
                continue;
            }

            $paragraphe = "{$colonnes[1]}-{$colonnes[2]}-{$colonnes[3]}";
            $ligneCle = "{$paragraphe}-{$colonnes[4]}";

            if ($derniereLigne !== null && $ligneCle !== $derniereLigne) {
                $texte .= $paragraphe !== $dernierParagraphe ? "\n\n" : "\n";
            } elseif ($texte !== '') {
                $texte .= ' ';
            }

            $texte .= $mot;
            $confiances[] = $confiance;
            $derniereLigne = $ligneCle;
            $dernierParagraphe = $paragraphe;
        }

        return [
            trim($texte),
            $confiances === [] ? null : (int) round(array_sum($confiances) / count($confiances)),
        ];
    }

    // Photos de téléphone : l'orientation est dans les métadonnées EXIF, pas
    // dans les pixels — sans correction, Tesseract lit la page couchée.
    // Best-effort : toute erreur ici laisse le fichier tel quel.
    public static function corrigerOrientation(string $chemin, string $extension): void
    {
        if (! in_array($extension, ['jpg', 'jpeg'], true) || ! function_exists('exif_read_data')) {
            return;
        }

        try {
            $exif = @exif_read_data($chemin);
            $orientation = (int) ($exif['Orientation'] ?? 1);

            $angle = match ($orientation) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };

            if ($angle === 0) {
                return;
            }

            $image = imagecreatefromjpeg($chemin);
            $tournee = imagerotate($image, $angle, 0);
            imagejpeg($tournee, $chemin, 92);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // "The command did not produce any output" sans autre erreur que le message
    // d'information "Estimating resolution" = Tesseract a tourné mais n'a rien lu.
    public static function estUneAbsenceDeTexte(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        if (! str_contains($message, 'did not produce any output')) {
            return false;
        }

        // "Can't open tsv" : le fichier de configuration `tsv` manque dans le
        // dossier tessdata (configs/) — Tesseract retombe en texte brut et le
        // wrapper ne trouve pas de .tsv. C'est une installation incomplète, pas
        // un scan vide : doit remonter comme une panne, avec un message clair.
        foreach (['Pdf reading is not supported', 'not found', 'Error in pixRead', 'Error during processing', "Can't open", 'read_params_file'] as $panne) {
            if (str_contains($message, $panne)) {
                return false;
            }
        }

        return true;
    }

    // Module 1/2 — tentative d'extraction du numéro sur le tampon d'entrée
    // existant chez le client. Format réel confirmé le 2026-09-04 sur deux
    // vrais tampons (voir DECISIONS.md "Numéro de tampon") :
    // "NSIA ASSURANCES {jour} {mois abrégé fr} '{année}  {heure:min:sec}-{numéro}"
    // ex. "NSIA ASSURANCES 21 JUIL '26 10:26:48-1789553" — jour numérique,
    // mois en lettres (3 à 5 caractères, ex. JUIL, AOUT), heure avec secondes.
    // Best-effort : la lisibilité du tampon lui-même (encre pâle/colorée sur
    // une photo de téléphone) reste un problème séparé, non résolu par ce
    // motif — voir DECISIONS.md. Ne renvoie que le texte détecté, jamais une
    // valeur imposée.
    public static function extraireNumeroTampon(string $texte): ?string
    {
        if (preg_match(
            '/NSIA\s+ASSURANCES?\W{0,5}(\d{1,2})\s+([A-Za-zÉÛéû]{3,5})\W{0,3}(\d{2,4})\s+(\d{1,2}:\d{2}(?::\d{2})?)\s*-\s*(\d{3,10})/u',
            $texte,
            $correspondance,
        )) {
            return trim(preg_replace('/\s+/', ' ', $correspondance[0]));
        }

        return null;
    }

    // Module 1 — extraction directe du champ "Objet" : convention standard
    // d'un courrier administratif francophone ("Objet : ..."), repérée sur
    // les vrais documents fournis en test (2026-09-04). Best-effort — un
    // texte sans cette mention tente le repli ci-dessous, jamais une valeur
    // inventée.
    public static function extraireObjet(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        if ($objet = self::extraireObjetDeLaMentionExplicite($texte)) {
            return $objet;
        }

        return self::objetPresDeLaFormuleDappel($texte);
    }

    // "Concerne :" est un synonyme administratif courant d'"Objet :" — vu
    // sur un vrai document ("The Best Group (TBG)"), 2026-09-07. Recolle la
    // ligne suivante quand la ligne captée se termine par une conjonction/
    // préposition (jamais la fin naturelle d'une phrase en français) : un
    // simple retour à la ligne dû à la largeur de la page, pas une nouvelle
    // phrase — vu sur un vrai document ("Concerne : ACCOMPAGNEMENT DANS LA
    // MISE EN PLACE DES SOLUTIONS INFORMATIQUES, DEMATERIALISATION
    // OU\nDIGITALISATION DES PROCESSUS METIERS.", TBG/CMR/NSIA, 2026-09-17),
    // où l'objet était tronqué avant "DIGITALISATION...". Ne recolle PAS
    // quand la ligne captée se termine normalement (ex. "Objet: Réclamation
    // sinistre auto" suivi de "Suite..." qui commence le corps du courrier,
    // sans ligne vide entre les deux) — un nom/adjectif en fin de ligne
    // n'indique jamais, seul, un retour à la ligne forcé.
    //
    // Second signal ajouté le 2026-09-22 (vrai document CDS Technologies,
    // demande explicite de l'utilisateur "objet doesn't take all the info") :
    // "Objet : OFFRE SPECIALE DE DEUX MOIS SDG\nDE CONNEXION INTERNET PAR
    // FIBRE OPTIQUE GRATUITE" — la 1ère ligne ne se termine par AUCUN mot de
    // liaison reconnu ("SDG", probablement lui-même du bruit OCR d'un tampon
    // de service voisin), donc le motif ci-dessus s'arrêtait avant la vraie
    // suite. Un objet rédigé TOUT EN MAJUSCULES qui continue sur la ligne
    // suivante ELLE AUSSI tout en majuscules est un signal de repli fiable et
    // déjà utilisé ailleurs dans ce fichier pour la même raison structurelle
    // (voir organisationPresDunBlocCoordonnees()) : un objet officiel en
    // capitales ne redescend jamais en minuscules au milieu de lui-même.
    // Vérifié sur la ligne SUIVANTE (pas seulement la ligne déjà captée) :
    // sinon, une 2e ligne elle-même en majuscules continuerait d'avaler la
    // formule d'appel qui suit ("Monsieur le Directeur Général,").
    private static function extraireObjetDeLaMentionExplicite(string $texte): ?string
    {
        $lignes = preg_split('/\r\n|\r|\n/', $texte);
        $motDeLiaisonEnFinDeLigne = '/\b(?:ou|et|de|du|des|la|le|les|un|une|à|a|en|avec|pour|sur|dans|par|ni|que|qui)\s*[,;]?$/iu';

        foreach ($lignes as $index => $ligne) {
            if (! preg_match('/\b(?:Objet|Concerne)\s*[:\-]\s*(.+)/iu', $ligne, $correspondance)) {
                continue;
            }

            $morceaux = [trim($correspondance[1])];

            for ($suivant = $index + 1; $suivant <= $index + 4 && $suivant < count($lignes); $suivant++) {
                $ligneSuivante = trim($lignes[$suivant]);

                if ($ligneSuivante === '') {
                    break;
                }

                $finitParUnMotDeLiaison = preg_match($motDeLiaisonEnFinDeLigne, end($morceaux)) === 1;
                $toutEnMajuscules = self::estEnMajusculesUniquement(end($morceaux)) && self::estEnMajusculesUniquement($ligneSuivante);

                if (! $finitParUnMotDeLiaison && ! $toutEnMajuscules) {
                    break;
                }

                $morceaux[] = $ligneSuivante;
            }

            $objet = trim(implode(' ', $morceaux));

            return $objet === '' ? null : mb_substr($objet, 0, 255);
        }

        return null;
    }

    // Au moins une lettre majuscule, aucune lettre minuscule (accents
    // inclus via \p{Lu}/\p{Ll}) — chiffres/ponctuation/symboles ignorés.
    private static function estEnMajusculesUniquement(string $ligne): bool
    {
        return preg_match('/\p{Lu}/u', $ligne) === 1 && preg_match('/\p{Ll}/u', $ligne) === 0;
    }

    // Repli quand le mot "Objet" n'apparaît pas : un courrier (souvent
    // commercial/publicitaire, vu sur un vrai document le 2026-09-04,
    // "FORMAVISION.COM" — "Solutions innovantes : Optimisez votre bureau…")
    // utilise parfois un intitulé différent au même endroit structurel,
    // juste avant la formule d'appel ("Monsieur," / "Madame le Directeur
    // Général,"…). Restreint au SEUL paragraphe qui précède immédiatement
    // la formule d'appel (jusqu'à la ligne vide précédente, jamais
    // au-delà) — pas un "Libellé : texte" cherché n'importe où dans le
    // document : une fenêtre plus large (plusieurs paragraphes) confondait
    // à tort un "Contacts : …" d'en-tête, plusieurs paragraphes plus haut,
    // avec l'objet (bug réel trouvé en écrivant les tests de cette même
    // entrée). Les nombreux "Libellé :" d'un en-tête/pied de page ne se
    // trouvent structurellement jamais DANS ce paragraphe précis, donc pas
    // besoin d'une liste d'exclusion. Best-effort — jamais une valeur
    // inventée.
    private static function objetPresDeLaFormuleDappel(string $texte): ?string
    {
        $lignes = preg_split('/\r\n|\r|\n/', $texte);

        foreach ($lignes as $index => $ligne) {
            if (! preg_match('/^\s*(?:Madame|Monsieur)\b.{0,60},\s*$/iu', $ligne)) {
                continue;
            }

            // Ignore la (les) ligne(s) vide(s) juste avant la formule
            // d'appel, puis remonte tant que les lignes ne sont pas vides :
            // c'est tout le paragraphe précédent, jamais un paragraphe
            // antérieur à celui-là.
            $i = $index - 1;

            while ($i >= 0 && trim($lignes[$i]) === '') {
                $i--;
            }

            $paragraphePrecedent = [];

            while ($i >= 0 && trim($lignes[$i]) !== '') {
                array_unshift($paragraphePrecedent, trim($lignes[$i]));
                $i--;
            }

            foreach ($paragraphePrecedent as $position => $ligneDuParagraphe) {
                if (preg_match('/^([^\n:]{3,60}):\s*(.+)$/u', $ligneDuParagraphe, $correspondance)) {
                    // Recolle les lignes suivantes du MÊME paragraphe : un
                    // simple retour à la ligne dû à la largeur de la page,
                    // pas une nouvelle phrase — sans ça, la phrase capturée
                    // s'arrêtait au premier saut de ligne du document
                    // ("...et systèmes de", sans "surveillance avancés.")
                    // au lieu de la phrase complète (bug réel constaté par
                    // l'utilisateur le 2026-09-07, voir DECISIONS.md).
                    $suite = array_slice($paragraphePrecedent, $position + 1);
                    $objet = trim(implode(' ', [$correspondance[2], ...$suite]));

                    return $objet === '' ? null : mb_substr($objet, 0, 255);
                }
            }

            // Formule d'appel trouvée mais rien qui y ressemble dans le
            // paragraphe précédent : ne pas continuer à chercher une autre
            // occurrence plus loin dans le texte (ex. la formule de
            // politesse finale).
            break;
        }

        return null;
    }

    // Module 1 — extraction du destinataire, moins fiable qu'extraireObjet()
    // ci-dessus (plusieurs motifs plutôt qu'un, décision du 2026-09-04, voir
    // DECISIONS.md "Destinataire proposé automatiquement") : le risque de
    // fausse proposition, jugé trop élevé pour tenter l'expéditeur faute de
    // convention fixe, est accepté ici en échange d'un marqueur explicite ou
    // d'une civilité + titre. Best-effort — jamais une valeur inventée.
    public static function extraireDestinataire(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        // Marqueur explicite, le plus fiable. L'accent de "à" est optionnel :
        // fréquemment perdu par l'OCR ("A l'attention de..."). S'arrête à la
        // première virgule/point (pas seulement à la fin de ligne) : quand le
        // marqueur apparaît au milieu d'une phrase plutôt que sur sa propre
        // ligne d'adresse, "(.+)" seul capturait toute la suite de la phrase
        // sur cette ligne (ex. "...à l'attention de Madame la Chargée du
        // dossier, comme convenu lors de notre entretien...") — bug réel
        // constaté lors de la revue du 2026-09-04, voir DECISIONS.md.
        if (preg_match('/(?:à|a)\s*l[\'’]attention\s+de\s*[:\-]?\s*([^,.\n]+)/iu', $texte, $correspondance)) {
            $destinataire = rtrim(trim($correspondance[1]), " \t,.;:");

            return $destinataire === '' ? null : mb_substr($destinataire, 0, 255);
        }

        // Convention "A"/"À" isolé en tête de bloc adresse (vue sur un vrai
        // document 2026-09-04, sans "à l'attention de" : "A\nMonsieur le
        // Directeur Général\nNSIA ASSURANCE\nYaoundé"), ou sur la même ligne
        // ("À Monsieur le Directeur Général"). Motif dédié plutôt qu'inclus
        // dans le motif civilité ci-dessous : capture tout le bloc adresse
        // (jusqu'à 4 lignes), pas seulement la première ligne de civilité.
        if ($destinataire = self::destinataireApresMarqueurA($texte)) {
            return $destinataire;
        }

        // Repli — civilité suivie d'un titre, convention observée sur les
        // courriers réels adressés à Nsia (ex. "Monsieur Le Directeur
        // Général de NSIA Assurances"). Insensible à la casse du titre
        // ("Monsieur le Directeur…" est au moins aussi courant que "Monsieur
        // Le Directeur…" — vu sur un vrai document le 2026-09-04, aucune
        // raison de l'exiger en majuscule).
        if (preg_match('/^\s*((?:Madame|Monsieur)\s+(?:Le|La|Les)\s+.{3,120})$/mui', $texte, $correspondance)) {
            $destinataire = rtrim(trim(preg_replace('/\s+/', ' ', $correspondance[1])), " \t,.;:");

            return $destinataire === '' ? null : mb_substr($destinataire, 0, 255);
        }

        return null;
    }

    // Bloc adresse introduit par "A"/"À" isolé sur sa propre ligne : la
    // première ligne suivante DOIT commencer par une civilité (Madame/
    // Monsieur), sinon la ligne "A"/"À" est ignorée — sans ce garde-fou, un
    // "A" isolé serait trop souvent un simple artefact OCR (lettre isolée
    // mal reconnue) plutôt qu'un vrai marqueur d'adresse. Capture ensuite
    // jusqu'à 4 lignes non vides (nom/titre, organisation, ville…), jusqu'à
    // la première ligne vide. Couvre aussi "A"/"À" suivi directement de la
    // civilité sur la même ligne.
    private static function destinataireApresMarqueurA(string $texte): ?string
    {
        $lignes = preg_split('/\r\n|\r|\n/', $texte);

        foreach ($lignes as $index => $ligne) {
            $ligneNettoyee = trim($ligne);

            if (preg_match('/^[AÀ]\.?\s+((?:Madame|Monsieur)\b.*)$/iu', $ligneNettoyee, $correspondance)) {
                $texteBloc = rtrim(trim($correspondance[1]), " \t,.;:");

                return $texteBloc === '' ? null : mb_substr($texteBloc, 0, 255);
            }

            if (! preg_match('/^[AÀ]\.?$/u', $ligneNettoyee)) {
                continue;
            }

            $bloc = [];

            for ($i = $index + 1; $i < count($lignes) && count($bloc) < 4; $i++) {
                $suivante = trim($lignes[$i]);

                if ($suivante === '') {
                    break;
                }

                if ($bloc === [] && ! preg_match('/^(?:Madame|Monsieur)\b/iu', $suivante)) {
                    break;
                }

                $bloc[] = $suivante;
            }

            if ($bloc === []) {
                continue;
            }

            $texteBloc = rtrim(implode("\n", $bloc), " \t,.;:");

            return $texteBloc === '' ? null : mb_substr($texteBloc, 0, 255);
        }

        return null;
    }

    // Module 1 — extraction de l'organisation expéditrice, best-effort et
    // volontairement limitée aux courriers d'entreprise à entreprise (voir
    // DECISIONS.md "Expéditeur (organisation) proposé automatiquement" pour
    // le compromis accepté le 2026-09-04) : repère un nom propre suivi (ou
    // précédé) d'une forme juridique courante en Afrique francophone (Sarl,
    // SA, S.A., Ets, GIE, Cabinet…), connecteurs minuscules ("de", "du",
    // "des", "d'", "l'"…) tolérés à l'intérieur du nom (ex. "Cabinet
    // d'Expertise Immobilière du Centre Sarl", "Cabinet d'Avocats Nkoto").
    // Ne couvre volontairement PAS l'expéditeur individuel (ex. réclamation
    // d'un assuré, personne physique) — aucune convention fixe n'existe pour
    // un nom de personne dans un courrier quelconque, même raisonnement
    // qu'avant pour l'expéditeur, mais sans motif de repli possible ici
    // comme pour extraireDestinataire(). Best-effort — jamais une valeur
    // inventée ; peut confondre un tiers cité dans le corps du texte avec
    // l'expéditeur réel (ex. un assureur/garage tiers mentionné dans une
    // réclamation) — raison pour laquelle la proposition reste toujours
    // modifiable, jamais imposée (bandeau "à vérifier"). Formes juridiques
    // anglophones (PLC, Ltd, Co Ltd — régions anglophones du Cameroun)
    // couvertes depuis le 2026-09-04 (voir DECISIONS.md, suite à la revue
    // adversariale du même jour) au même titre que les formes francophones ;
    // un dernier motif de repli (voir organisationPresDunBlocCoordonnees()
    // ci-dessous) couvre en plus les organisations sans forme juridique
    // reconnaissable dans le texte (administrations…), via la convention
    // d'en-tête/pied de page où le nom précède ou suit un bloc de
    // coordonnées, ou une mention "La Direction (Générale)".
    public static function extraireExpediteurOrganisation(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        // "Raison sociale :" est l'intitulé légal exact de ce marqueur sur un
        // document administratif/fiscal camerounais (vu sur un vrai document,
        // "The Best Group (TBG)", 2026-09-07) — même fiabilité qu'"Expéditeur :".
        if (preg_match('/\b(?:Exp[ée]diteur|Raison\s+sociale)\s*[:\-]\s*(.+)/iu', $texte, $correspondance)) {
            $expediteur = trim($correspondance[1]);

            return $expediteur === '' ? null : mb_substr($expediteur, 0, 255);
        }

        $mot = self::motDuNom();

        // Forme juridique en suffixe (le nom propre vient avant, ex. "ITSC
        // Sarl", "Golden Motors Cameroon PLC"). Espace horizontal uniquement
        // ([ \t], jamais \s) entre les mots : \s englobe aussi le saut de
        // ligne, ce qui recollait un fragment OCR isolé sur sa propre ligne
        // (ex. "URITE", bruit de bas de page précédente) avec le vrai nom sur
        // la ligne suivante — bug réel constaté le 2026-09-04, voir
        // DECISIONS.md. Frontière de fin en lookahead "pas suivi d'une
        // lettre" plutôt que `\b` classique : `\b` échoue après "S.A." (un
        // point n'est pas un caractère de mot, donc la frontière entre "."
        // et un espace n'existe pas) — le suffixe "S.A." était donc du code
        // mort, ne matchait jamais dans un texte réel (autre bug de la revue
        // du 2026-09-04).
        if (preg_match_all('/\b((?:'.$mot.'[ \t]+){1,6}(?:Sarl|SARL|SUARL|SA|S\.A\.|GIE|PLC|Co\.?\s+Ltd|Ltd|Limited))(?![\wÀ-ÿ])/u', $texte, $correspondances)) {
            if ($organisation = self::premiereOrganisationHorsNsia($correspondances[1])) {
                return $organisation;
            }
        }

        // Forme juridique en préfixe (le nom propre vient après, ex. "Ets
        // Fotso", "Cabinet Ndiaye", "Cabinet d'Avocats Nkoto").
        if (preg_match_all('/\b((?:Ets|[EÉ]tablissements|Cabinet)[ \t]+'.$mot.'(?:[ \t]+'.$mot.'){0,4})(?![\wÀ-ÿ])/u', $texte, $correspondances)) {
            if ($organisation = self::premiereOrganisationHorsNsia($correspondances[1])) {
                return $organisation;
            }
        }

        // Auto-présentation ("La société X…", "L'entreprise X…", "Le groupe
        // X…") : formule très courante dans le corps d'un courrier
        // commercial pour que l'expéditeur se nomme lui-même — vue sur un
        // vrai document ("La société FORMAVISION Cameroun créée en 2007 est
        // une filiale du groupe FORMAVISION International"), constatée le
        // 2026-09-04 quand ni l'en-tête (OCR trop dégradé, "RMA N.COM" pour
        // "FORMAVISION.COM") ni les motifs ci-dessus n'avaient rien trouvé.
        // Variante à la première personne ("Notre firme X…", "Notre société
        // X…"…) ajoutée le 2026-09-17 : vue sur un vrai document ("Notre
        // firme The Best Group (TBG) est spécialisée dans l'Ingénierie
        // Informatique…", TBG/CMR/NSIA), où ni "Raison sociale :" en pied de
        // page (bandeau clair-sur-sombre, OCR trop dégradé, "isles" au lieu
        // de "Raison sociale") ni aucun autre motif ci-dessus n'avait rien
        // trouvé.
        if (preg_match_all('/\b(?:[Ll]a\s+[Ss]oci[ée]t[ée]|[Ll][\'’][Ee]ntreprise|[Ll]e\s+[Gg]roupe|[Ll]a\s+[Cc]ompagnie|[Nn]otre\s+(?:firme|soci[ée]t[ée]|entreprise|groupe|compagnie|cabinet))\s+('.$mot.'(?:[ \t]+'.$mot.'){0,4})(?![\wÀ-ÿ])/u', $texte, $correspondances)) {
            if ($organisation = self::premiereOrganisationHorsNsia($correspondances[1])) {
                return $organisation;
            }
        }

        if ($organisation = self::organisationPresDunBlocCoordonnees($texte)) {
            return $organisation;
        }

        return self::organisationDepuisDomaineEmail($texte);
    }

    // Dernier recours parmi les derniers recours (demande explicite de
    // l'utilisateur, 2026-09-21, sur un vrai document "kpamela@
    // easytechgroup.net" sans aucune forme juridique ni bloc de coordonnées
    // reconnaissable) : à défaut de tout le reste, le nom de domaine d'une
    // adresse email trouvée dans le texte (réutilise extraireExpediteurEmail()
    // — jamais une seconde extraction divergente) est souvent le nom de
    // l'entreprise elle-même ("easytechgroup.net" → "Easytechgroup").
    // Jamais proposé pour un fournisseur d'email public/générique (gmail,
    // yahoo, hotmail...) — ce n'est alors le nom d'AUCUNE entreprise, ce
    // serait une valeur inventée, pas extraite. Même filtre anti-Nsia que
    // premiereOrganisationHorsNsia() : Nsia ne s'envoie jamais de courrier à
    // elle-même. Aucune tentative de scinder le domaine en mots ("easytech
    // group") : ce serait deviner une structure absente du texte source —
    // seule la casse est normalisée, laissé tel quel pour que l'agent
    // corrige au besoin (bandeau "à vérifier", comme toutes les propositions
    // automatiques de ce fichier).
    private static function organisationDepuisDomaineEmail(string $texte): ?string
    {
        $email = self::extraireExpediteurEmail($texte);

        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        $domaine = Str::after($email, '@');
        $segment = Str::before($domaine, '.');

        if ($segment === '' || mb_strlen($segment) < 3) {
            return null;
        }

        $fournisseursGeneriques = [
            'gmail', 'yahoo', 'hotmail', 'outlook', 'live', 'icloud', 'aol',
            'protonmail', 'gmx', 'orange', 'laposte', 'free', 'wanadoo',
            'yandex', 'zoho', 'mail',
        ];

        if (in_array(mb_strtolower($segment), $fournisseursGeneriques, true)) {
            return null;
        }

        if (str_contains(mb_strtoupper(Str::ascii($segment)), 'NSIA')) {
            return null;
        }

        return mb_convert_case($segment, MB_CASE_TITLE);
    }

    // Motif partagé (organisation près d'un marqueur ci-dessous en dépend
    // aussi) : un mot du nom est un mot capitalisé (élision "d'"/"l'"
    // tolérée devant, ex. "d'Expertise") ou un connecteur minuscule courant
    // ("de", "du", "des", "la", "le", "et") — sans ces connecteurs, un nom
    // comme "Cabinet d'Expertise Immobilière du Centre Sarl" n'était capturé
    // qu'à partir de "Centre Sarl" (bug réel constaté lors de la revue du
    // 2026-09-04, voir DECISIONS.md).
    private static function motDuNom(): string
    {
        return '(?:(?:[dl][\'’])?[A-ZÉÈÀ][\wÀ-ÿ.\'-]*|du|des|de|la|le|et)';
    }

    // Dernier recours (le moins fiable des motifs) : sans aucune forme
    // juridique reconnaissable dans le texte — cas des administrations, ou
    // d'une entreprise dont la forme n'est pas dans la liste ci-dessus — le
    // nom de l'organisation se trouve très souvent près d'un bloc de
    // coordonnées/identifiants légaux (immatriculation, boîte postale,
    // téléphone — convention d'en-tête ET de pied de page, vue sur le vrai
    // document ITSC Sarl/NSIA : "Contacts : ... BP 2138 Yaoundé Cameroun")
    // ou une mention "La Direction (Générale)" en signature. Fenêtre de 3
    // lignes avant/après le marqueur (pas seulement la ligne adjacente,
    // élargi le 2026-09-04 après un deuxième vrai document,
    // "FORMAVISION.COM" : le nom y est séparé du numéro de téléphone par un
    // slogan et une ligne d'adresse — un simple voisinage direct ne
    // suffisait pas), sans jamais franchir une ligne vide dans une
    // direction donnée (un bloc d'en-tête/pied de page reste compact).
    // Restreint aux lignes ENTIÈREMENT EN MAJUSCULES ou contenant déjà une
    // forme juridique connue (jamais une ligne en casse normale) : un
    // assuré qui donne son propre numéro de téléphone signe en casse
    // normale ("Jean Dupont, Tél : ..."), jamais en majuscules — ce
    // garde-fou évite de confondre sa signature avec un nom d'organisation.
    // Ajouté le 2026-09-04 suite à la revue adversariale (voir
    // DECISIONS.md) ; best-effort, jamais une valeur inventée. Limite
    // assumée : l'OCR ne conserve ni la taille, ni la couleur, ni le style
    // du texte d'origine — impossible de distinguer par ce biais un titre
    // stylisé d'un simple slogan, seule la position par rapport à un
    // marqueur reconnu compte ici.
    private static function organisationPresDunBlocCoordonnees(string $texte): ?string
    {
        $lignes = preg_split('/\r\n|\r|\n/', $texte);
        // "N°" optionnel devant "RC" (souvent absent en pratique) ; "N.I.U."
        // avec points entre les lettres toléré, pas seulement "NIU" collé.
        $marqueur = '/(?:N°\s*)?RC\b|RCCM|Contribuable|N\.?\s?I\.?\s?U\.?\s*:|B\.?\s?P\.?\s*\d|T[ée]l[ée]?(?:phone)?\s*[:.]?\s*\(?\+?\d|Contacts?\s*:|Immatriculation|(?:Pour\s+(?:la|le)\s+)?(?:La|Le)\s+Direction(?:\s+G[ée]n[ée]rale)?\b/iu';

        foreach ($lignes as $index => $ligne) {
            if (! preg_match($marqueur, $ligne)) {
                continue;
            }

            foreach (self::lignesVoisines($lignes, $index) as $candidat) {
                $candidat = trim($candidat);

                if ($candidat === '' || ! str_contains($candidat, ' ')) {
                    continue;
                }

                if (str_contains(mb_strtoupper(Str::ascii($candidat)), 'NSIA')) {
                    continue;
                }

                // Ligne ENTIÈREMENT EN MAJUSCULES uniquement : une ligne en
                // casse normale contenant juste une forme juridique connue
                // ("Sarl", "SA"…) N'EST PLUS acceptée ici, même en présence
                // du mot — les motifs ci-dessus (suffixe/préfixe, tiers 2-3),
                // qui exigent un mot valide immédiatement avant la forme
                // juridique, scannent déjà TOUT le document, pas seulement
                // une ligne : si un nom valide existait quelque part, ils
                // l'auraient déjà trouvé avant d'arriver jusqu'ici. Un simple
                // "contient Sarl" restant, sans cette exigence, ne pouvait
                // donc plus jamais trouver un VRAI nom que ces motifs
                // auraient manqué — seulement retourner, à tort, la ligne
                // entière dès qu'un mot-clé de forme juridique traînait
                // n'importe où dessus (ex. deux colonnes recollées par l'OCR
                // sur une même ligne physique, "oft SARL, À l'attention de
                // Monsieur le Directeur Général" — bug réel constaté le
                // 2026-09-07 sur un vrai document, "Univsoft SARL", voir
                // DECISIONS.md).
                if (preg_match('/^[A-ZÀ-ÖØ-Þ0-9&.,\'’\/\-\s]{3,80}$/u', $candidat) === 1) {
                    return mb_substr($candidat, 0, 255);
                }
            }
        }

        return null;
    }

    // Lignes candidates autour d'un marqueur, par proximité croissante
    // (elle-même, puis jusqu'à 3 lignes avant, puis jusqu'à 3 lignes après),
    // sans jamais franchir une ligne vide dans une direction donnée.
    private static function lignesVoisines(array $lignes, int $index): array
    {
        $candidats = [$lignes[$index]];

        foreach ([-1, 1] as $direction) {
            for ($decalage = 1; $decalage <= 3; $decalage++) {
                $voisinIndex = $index + $direction * $decalage;

                if (! isset($lignes[$voisinIndex]) || trim($lignes[$voisinIndex]) === '') {
                    break;
                }

                $candidats[] = $lignes[$voisinIndex];
            }
        }

        return $candidats;
    }

    // Module 1 — téléphone de l'expéditeur, sur la convention
    // "Contacts : ..."/"Tél : ..." déjà vue en pied de page du vrai document
    // ITSC Sarl/NSIA ("Contacts : (00237)658239075/ 675179454 BP 2138
    // Yaoundé Cameroun"). Anciennement fusionné avec email/adresse dans un
    // seul champ texte libre `expediteur_coordonnees` — séparé en trois
    // colonnes dédiées le 2026-09-17 (demande explicite de l'utilisateur,
    // voir DECISIONS.md), même raisonnement que la séparation RC/NIU. Ne
    // capture que le jeton numérique après le marqueur (chiffres/+/(/)/
    // espaces/slashes/tirets), pas le reste de la ligne — s'arrête donc
    // naturellement avant "BP ..." ou "Mail:" qui suivent souvent sur la
    // même ligne. Best-effort — jamais une valeur inventée ; sans marqueur
    // ET sans indicatif international reconnu, un numéro nu reste hors
    // périmètre (trop ambigu — pourrait être une référence, une date...).
    //
    // Repli sans marqueur, demande explicite de l'utilisateur (2026-09-17) :
    // un numéro préfixé par "+" suivi d'un indicatif téléphonique
    // international AFRICAIN reconnu (tous les pays d'Afrique — voir
    // INDICATIFS_TELEPHONIQUES_AFRICAINS ci-dessous) est un signal fiable en
    // lui-même, sans avoir besoin d'un marqueur "Tél :" — même principe que
    // l'email (le format suffit) — vu sur un vrai document en-tête sans
    // label ("+237 694 006 485", TBG/CMR/NSIA, 2026-09-17). Volontairement
    // PAS étendu au préfixe "00" (convention internationale alternative à
    // "+") pour ce repli sans marqueur : contrairement à "+", "00" en début
    // de nombre est un motif bien plus ambigu (pourrait être un simple
    // numéro commençant par des zéros) — "00" reste donc seulement accepté
    // À L'INTÉRIEUR d'un marqueur explicite "Contacts :"/"Tél :" ci-dessus.
    public static function extraireExpediteurTelephone(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        // "/" retiré du jeton capturé (2026-10-08, retour utilisateur : "let
        // it take one phone number not all") — gardé jusqu'ici précisément
        // pour capturer les DEUX numéros du document réel cité ci-dessus
        // ("Contacts : (00237)658239075/ 675179454"), ce qui collait les
        // deux ensemble en une seule valeur ; l'utilisateur veut maintenant
        // UN seul numéro (le premier) — "/" n'étant plus dans la classe de
        // caractères, le motif s'arrête naturellement avant lui au lieu de
        // le traverser.
        if (preg_match('/\bContacts?\s*:\s*(\(?\+?\d[\d\s\-.()]*\d)/iu', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[1]), 0, 255);
        }

        if (preg_match('/\bT[ée]l[ée]?(?:phone)?\s*[:.]?\s*(\(?\+?\d[\d\s\-.()]*\d)/iu', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[1]), 0, 255);
        }

        $indicatifs = implode('|', self::INDICATIFS_TELEPHONIQUES_AFRICAINS);

        if (preg_match('/\+(?:'.$indicatifs.')[\d\s\-.()]{4,20}\d/u', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[0]), 0, 255);
        }

        return null;
    }

    // Indicatifs téléphoniques internationaux (E.164) de tous les pays
    // d'Afrique — triés du plus long au plus court pour que l'alternance
    // regex ci-dessus ne s'arrête jamais prématurément sur un préfixe plus
    // court d'un indicatif à 3 chiffres (ex. tester "2" avant "27" aurait
    // été un bug, mais aucun de ces indicatifs n'est lui-même préfixe d'un
    // autre ici — tri conservé par prudence si la liste évolue).
    private const INDICATIFS_TELEPHONIQUES_AFRICAINS = [
        '211', '212', '213', '216', '218', '220', '221', '222', '223', '224',
        '225', '226', '227', '228', '229', '230', '231', '232', '233', '234',
        '235', '236', '237', '238', '239', '240', '241', '242', '243', '244',
        '245', '248', '249', '250', '251', '252', '253', '254', '255', '256',
        '257', '258', '260', '261', '262', '263', '264', '265', '266', '267',
        '268', '269', '290', '291', '20', '27',
    ];

    // Module 1 — email de l'expéditeur, séparé le 2026-09-17 (voir
    // commentaire d'extraireExpediteurTelephone() ci-dessus). Contrairement
    // au téléphone, ne nécessite AUCUN marqueur explicite ("Email :"...) :
    // le format même d'une adresse email ("X@Y.Z") est une convention assez
    // fiable pour ne jamais confondre avec autre chose — vu en en-tête d'un
    // vrai document ("info@thebest-group.com", TBG/CMR/NSIA, 2026-09-17)
    // sans aucun label devant. Best-effort — jamais une valeur inventée.
    public static function extraireExpediteurEmail(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        if (preg_match('/\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b/u', $texte, $correspondance)) {
            return mb_substr($correspondance[0], 0, 255);
        }

        return null;
    }

    // Module 1 — adresse de l'expéditeur, séparée le 2026-09-17 (voir
    // commentaire d'extraireExpediteurTelephone() ci-dessus). "Adresse :"/
    // "Siège (social) :" explicite en priorité ; à défaut, un bloc "BP ..."
    // (Boîte Postale), convention d'adresse quasi systématique sur un
    // courrier commercial camerounais, souvent accolé au téléphone sur la
    // même ligne "Contacts :" (vu sur le vrai document ITSC Sarl/NSIA) — la
    // fin d'un éventuel label vide qui suivrait sur la même ligne (ex.
    // "- Mail:", sans adresse email après) est retirée pour ne pas
    // l'inclure dans l'adresse. Virgule/point-virgule/deux-points tolérés
    // entre "BP" et le numéro (ex. "BP, 4568 Yaoundé") — vu sur un vrai
    // document (MEGATIM/AFG BANK, 2026-09-18) où "BP," (avec virgule)
    // ne matchait pas le motif d'origine, qui n'acceptait qu'un espace.
    // Best-effort — jamais une valeur inventée ; ne recolle pas une adresse
    // déjà coupée sur plusieurs lignes (limite assumée, contrairement à
    // extraireObjet() : aucun signal grammatical fiable équivalent à la
    // conjonction de fin de ligne ne s'applique à une adresse).
    public static function extraireExpediteurAdresse(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        if (preg_match('/\b(?:Adresse|Si[èe]ge(?:\s+social)?)\s*[:\-]\s*([^\n]+)/iu', $texte, $correspondance)) {
            $adresse = trim($correspondance[1]);

            return $adresse === '' ? null : mb_substr($adresse, 0, 255);
        }

        if (preg_match('/\bB\.?\s?P\.?\s*[,;:]?\s*\d[^\n]*/iu', $texte, $correspondance)) {
            $adresse = preg_replace('/\s*[-–]\s*(?:Mail|E-?mail)\s*:?\s*$/iu', '', trim($correspondance[0]));

            return $adresse === '' ? null : mb_substr($adresse, 0, 255);
        }

        return null;
    }

    // Module 1 — nom de l'expéditeur (personne physique), demande explicite
    // de l'utilisateur (2026-09-04, voir DECISIONS.md) : contrairement au
    // reste du texte d'un courrier quelconque, où aucune convention fixe ne
    // repère un nom de personne, la formule "Je soussigné(e) [Nom]," est une
    // convention administrative française bien établie — utilisée
    // précisément par un déclarant individuel (ex. un assuré rédigeant une
    // réclamation) pour s'identifier lui-même, sans ambiguïté sur qui est
    // l'expéditeur. Complétée par un marqueur explicite "Signé :"/"Signature :"
    // en fin de courrier. Best-effort — jamais une valeur inventée ; ne
    // couvre PAS un signataire non introduit par l'une de ces deux
    // conventions (une simple civilité en signature, ex. "Monsieur EYENGA
    // Paul" seule, reste hors périmètre — bien plus ambigu, voir DECISIONS.md).
    public static function extraireExpediteurNom(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        if (preg_match('/\bJe\s+soussign[ée]e?\s*,?\s*(?:Madame|Monsieur)?\s*([^,\n]{3,80})/iu', $texte, $correspondance)) {
            $nom = trim($correspondance[1]);

            return $nom === '' ? null : mb_substr($nom, 0, 255);
        }

        if (preg_match('/\b(?:Sign[ée]e?|Signature)\s*[:\-]\s*([^\n]{3,80})/iu', $texte, $correspondance)) {
            $nom = trim($correspondance[1]);

            return $nom === '' ? null : mb_substr($nom, 0, 255);
        }

        return null;
    }

    // Module 1 — mode de réception, demande explicite de l'utilisateur
    // (2026-09-04, voir DECISIONS.md) : contrairement à "dépôt physique"/
    // "poste" (aucune trace distinctive dans le contenu d'un courrier scanné
    // — un problème déjà documenté comme limite assumée), un email imprimé
    // ou transféré, ou un fax transmis, conservent souvent une trace
    // textuelle propre à leur canal. Best-effort, jamais une valeur inventée — sans
    // signal reconnu, le champ garde sa valeur par défaut du formulaire
    // (dépôt physique), à confirmer par l'agent comme n'importe quel autre
    // champ non proposé.
    public static function extraireModeReception(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        // En-tête d'email imprimé/transféré : "De :"/"From :" DIRECTEMENT
        // suivi d'une adresse mail — signal spécifique. Une simple adresse
        // mail citée ailleurs dans le texte (ex. la propre adresse du
        // signataire) ne suffit pas à elle seule.
        if (preg_match('/\b(?:De|From)\s*:\s*\S+@\S+/iu', $texte)) {
            return 'email';
        }

        // Bandeau de télécopie apposé automatiquement par la plupart des
        // télécopieurs à l'envoi/réception : la combinaison du mot "FAX"/
        // "TÉLÉCOPIE" (ou "TX"/"RX", jargon télécopieur) ET d'un compte de
        // page ("001/003", "Page 1/2") est spécifique à ce bandeau — un
        // simple "Fax :" isolé dans un en-tête (juste un numéro de contact
        // parmi d'autres) ne suffit pas seul, pour ne pas confondre avec un
        // simple moyen de contact indiqué sur une lettre déposée physiquement.
        $motCleFax = '(?:FAX|TX|RX|T[ée]l[ée]copie)';

        if (preg_match('/\b'.$motCleFax.'\b.{0,40}\d{1,3}\s*\/\s*\d{1,3}|\d{1,3}\s*\/\s*\d{1,3}.{0,40}\b'.$motCleFax.'\b/iu', $texte)) {
            return 'fax';
        }

        return null;
    }

    // Module 1 — numéro RC (Registre du Commerce), demande explicite de
    // l'utilisateur (2026-09-07) : identifiant légal quasi systématique en
    // en-tête/pied de page d'un courrier commercial camerounais (vu sur les
    // vrais documents fournis : "N° RC/YAO/2019/B/433", "RC/YAO/2007/B/4014",
    // et "RC N° : CM-DLA-02-2025-B-00827" — "N°" peut aussi bien précéder que
    // suivre "RC", d'où le "(?:N°)?" optionnel entre les deux). Capture le
    // motif complet "RC/{ville}/{année}/{type}/{numéro}" tel qu'écrit, sans
    // normaliser le "N°" qui peut l'entourer. Best-effort — jamais une valeur
    // inventée.
    public static function extraireExpediteurRc(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        // La partie valeur doit se terminer par un CHIFFRE, pas juste
        // "n'importe quel caractère de mot" — corrigé le 2026-09-22 sur un
        // vrai document ("RC/DLA/2020/B/3204Contr. N° M062015196381P") où
        // l'OCR colle "Contr." (début de "Contribuable") directement après
        // le numéro sans espace : `[\w\/\-]{3,30}` seul (glouton, `\w`
        // inclut les lettres) avalait ce fragment ("...3204Contr"). Tous les
        // numéros RC réels observés (voir les tests) se terminent par le
        // numéro de séquence, jamais par une lettre — `{2,29}\d` force donc
        // la capture à s'arrêter sur le dernier chiffre, jamais un mot
        // accolé sans séparateur juste après.
        if (preg_match('/\bRC\s*(?:N°)?\s*[\/:]\s*[\w\/\-]{2,29}\d/u', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[0]), 0, 255);
        }

        return null;
    }

    // Module 1 — NIU (Numéro d'Identifiant Unique fiscal), demande explicite
    // de l'utilisateur (2026-09-07) : même famille que le RC, souvent noté
    // "Contribuable :" (ancien intitulé, même valeur constatée sur le vrai
    // document ITSC Sarl : "Contribuable : M051912784615T - NIU :
    // M051912784615T"). "I"/"L" tolérés l'un pour l'autre dans le sigle : le
    // vrai document FORMAVISION.COM montre un OCR qui confond
    // systématiquement les deux à cet endroit précis ("NLU." et "N.U.L" pour
    // "N.I.U."/"N.U.I.") — tolérance étroite et justifiée par deux
    // occurrences réelles, pas une correction OCR générale. Best-effort —
    // jamais une valeur inventée.
    //
    // Repli sans label "NIU"/"Contribuable", demande explicite de
    // l'utilisateur (2026-09-18, "it always start either with P or M is
    // always beside RC") : un NIU camerounais commence systématiquement par
    // "P" ou "M" suivi d'une longue suite de chiffres (ex. "M021912751106K",
    // "M051912784615T") et se trouve quasi toujours immédiatement à côté du
    // RC sur la même ligne — vu sur un vrai document où le pied de page ne
    // porte AUCUNE étiquette du tout, juste "RC/DLA/2019/B/942 |
    // M021912751106K" (TBG/CMR/NSIA, 2026-09-17). Exige au moins 6 chiffres
    // consécutifs après le "P"/"M" (pas juste "[\w]") pour ne jamais
    // confondre avec un mot français ordinaire commençant par la même
    // lettre juste après le RC dans une phrase (ex. "RC/YAO/.../433
    // Monsieur Jean" — "Monsieur" n'a aucun chiffre, ne matche donc jamais).
    public static function extraireExpediteurNiu(?string $texte): ?string
    {
        if (blank($texte)) {
            return null;
        }

        if (preg_match('/\bN\.?\s?(?:[IL]\.?\s?U|U\.?\s?[IL])\.?\s*:?\s*([\w]{5,20})/u', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[1]), 0, 255);
        }

        if (preg_match('/\bContribuable\s*:?\s*([\w]{5,20})/iu', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[1]), 0, 255);
        }

        // "Cont[r]. N°" — abrégé de "Contribuable N°", demande explicite de
        // l'utilisateur (2026-09-22) sur le même vrai document EASYTECH
        // GROUP SA : "...3204Contr. N° M062015196381P" (RC immédiatement
        // suivi, sans espace, de l'abréviation de "Contribuable" puis de
        // "N°"). Étiquette explicite (comme "Contribuable" ci-dessus) : pas
        // besoin d'exiger la forme P/M + chiffres, contrairement au repli
        // "N°" seul plus bas, bien plus générique.
        if (preg_match('/\bCont(?:r)?\.?\s*N°\s*:?\s*([\w]{5,20})/iu', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[1]), 0, 255);
        }

        // "N° Cont." — même abréviation, ORDRE INVERSÉ ("N°" avant
        // "Cont[r]." plutôt qu'après), vu sur un second vrai document
        // (NOW TECHNOLOGIES CENTER Sarl, 2026-09-22) : "RC/DLA/2018/B/2407
        // N° cont. MOQ71812712493" — demande explicite de l'utilisateur
        // ("for the niu also add if he find this too N° cont."). Étiquette
        // explicite au même titre que "Cont[r]. N°" ci-dessus, pas besoin
        // d'exiger la forme P/M + chiffres.
        if (preg_match('/\bN°\s*Cont(?:r)?\.?\s*:?\s*([\w]{5,20})/iu', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[1]), 0, 255);
        }

        // "N°" comme étiquette directe du NIU, demande explicite de
        // l'utilisateur (2026-09-21) — certains documents réels notent le
        // NIU sous "N° : P..."/"N° M..." sans jamais écrire "NIU" ni
        // "Contribuable". "N°" seul est beaucoup trop générique (numéro de
        // téléphone, d'adresse, de RC...) pour être accepté tel quel : exige
        // donc la MÊME forme de NIU camerounais déjà établie ci-dessous
        // (P/M suivi d'au moins 6 chiffres) juste après, jamais un simple
        // "N°" suivi de n'importe quoi. Un "N° RC/..." (RC lui-même précédé
        // de "N°", déjà couvert par extraireExpediteurRc()) ne matche pas
        // ici : la forme après "N°" ne serait pas P/M + chiffres.
        if (preg_match('/\bN°\s*:?\s*([PM]\d{6,15}[A-Za-z]?)\b/u', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[1]), 0, 255);
        }

        if (preg_match('/\bRC\s*(?:N°)?\s*[\/:]\s*[\w\/\-]{3,30}\s*[|\-–]?\s*([PM]\d{6,15}[A-Za-z]?)\b/u', $texte, $correspondance)) {
            return mb_substr(trim($correspondance[1]), 0, 255);
        }

        return null;
    }

    // Nsia ne s'envoie jamais de courrier à elle-même : une mention de son
    // propre nom dans le corps du texte — quasi systématique dans une
    // réclamation ("...bien assuré auprès de NSIA Assurances SA...") — ne
    // doit jamais être proposée comme organisation expéditrice ; sans ce
    // filtre, c'était le cas le plus fréquent en pratique, pas un cas
    // marginal (constaté lors de la revue du 2026-09-04, voir DECISIONS.md).
    // Repris sur TOUTES les correspondances trouvées (preg_match_all), pas
    // seulement la première : une mention de Nsia apparaît presque toujours
    // avant le nom d'un éventuel véritable tiers plus loin dans le texte —
    // s'arrêter à la première aurait masqué ce tiers au lieu de le proposer.
    private static function premiereOrganisationHorsNsia(array $candidats): ?string
    {
        // Une formule d'introduction ("L'entreprise X…", "La société X…"…)
        // ne fait jamais partie du nom réel — bug réel constaté le
        // 2026-09-22 (document CDS Technologies Sarl, "L'entreprise CDS
        // Technologies Sarl" proposé au lieu de "CDS Technologies Sarl") :
        // le motif "forme juridique en suffixe" capture parfois CETTE
        // formule elle-même dans sa fenêtre de mots précédents —
        // "L'entreprise" commence par une majuscule comme n'importe quel
        // vrai mot du nom, rien dans $mot ne les distingue. Retirée ici si
        // présente en tête, plutôt que de laisser polluer le nom proposé —
        // mêmes formules que le motif "auto-présentation" plus haut, seul
        // point de vérité pour cette liste.
        $introduction = '/^(?:[Ll]a\s+[Ss]oci[ée]t[ée]|[Ll][\'’][Ee]ntreprise|[Ll]e\s+[Gg]roupe|[Ll]a\s+[Cc]ompagnie|[Nn]otre\s+(?:firme|soci[ée]t[ée]|entreprise|groupe|compagnie|cabinet))\s+/u';

        foreach ($candidats as $candidat) {
            $candidat = trim(preg_replace($introduction, '', trim($candidat)));

            if ($candidat === '' || str_contains(mb_strtoupper(Str::ascii($candidat)), 'NSIA')) {
                continue;
            }

            return mb_substr($candidat, 0, 255);
        }

        return null;
    }

    public static function motifQualite(string $texte, ?int $confiance): string
    {
        if (mb_strlen($texte) < self::longueurMinimaleTexte()) {
            return 'Aucun texte (ou presque) reconnu — scan illisible, page manuscrite ou vide. Un nouveau scan est recommandé.';
        }

        return "Texte reconnu mais confiance faible ({$confiance} %, minimum ".self::confianceMinimale().' %) — scan probablement flou ou mal cadré. Un nouveau scan est recommandé.';
    }

    public static function messageLisible(?Throwable $exception): string
    {
        $message = $exception?->getMessage() ?? '';

        if (str_contains($message, 'Pdf reading is not supported')) {
            return 'Le serveur ne peut pas lire les PDF (Ghostscript absent). '
                .'Numérisez en image (JPG/PNG) ou demandez l\'installation de Ghostscript, puis relancez la numérisation.';
        }

        if (str_contains($message, 'tesseract') && str_contains($message, 'not found')) {
            return 'Le moteur OCR (Tesseract) est introuvable sur le serveur — vérifier TESSERACT_PATH.';
        }

        if (str_contains($message, "Can't open") || str_contains($message, 'read_params_file')) {
            return 'Configuration OCR incomplète sur le serveur : le dossier tessdata (TESSERACT_TESSDATA_PATH) doit contenir '
                .'les fichiers de configuration de Tesseract (sous-dossier configs/, notamment "tsv"). Le document n\'est pas en cause.';
        }

        return 'Le traitement OCR a échoué. Le détail technique est dans le journal serveur ; vous pouvez relancer la numérisation.';
    }
}
