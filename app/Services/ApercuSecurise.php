<?php

namespace App\Services;

use App\Models\ApercuDocument;
use App\Models\Courrier;
use App\Models\CourrierBrouillon;
use App\Models\CourrierConsultation;
use App\Models\PieceJointe;
use App\Models\User;
use App\Jobs\PreparerApercuJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

// Module 9 — aperçu sécurisé (2026-11-09, demande « on ne doit pas copier ou
// télécharger un fichier », voir DECISIONS.md « Fichiers : chiffrement,
// aperçu sécurisé, aucun téléchargement »). Le fichier original ne quitte
// jamais le serveur :
//   1. en file d'attente (PreparerApercuJob), chaque page est rendue en image
//      (Ghostscript pour un PDF, GD pour une photo) et stockée chiffrée ;
//   2. à l'affichage, chaque page reçoit en filigrane le nom de la personne,
//      la date, l'heure et la référence (un simple calque sur une image déjà
//      prête, léger dans la requête). Le navigateur ne reçoit que ces images :
//      ni PDF à enregistrer, ni texte à copier ; une capture d'écran reste
//      possible mais désigne son auteur.
class ApercuSecurise
{
    public const TYPE_COURRIER = 'courrier';

    public const TYPE_PIECE_JOINTE = 'piece-jointe';

    public const TYPE_BROUILLON = 'brouillon';

    public const TYPES = [self::TYPE_COURRIER, self::TYPE_PIECE_JOINTE, self::TYPE_BROUILLON];

    // 150 dpi : une page A4 fait 1240 × 1754 px, nette jusqu'au zoom 200 %.
    public const RESOLUTION = 150;

    public const LARGEUR_MAX_IMAGE = 1600;

    public const PAGES_MAX = 100;

    private const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Document visé : chemin du fichier, courrier rattaché (droits, journal,
     * canal temps réel), brouillon éventuel, référence du filigrane.
     *
     * @return array{chemin: string, courrier: ?Courrier, brouillon: ?CourrierBrouillon, piece: ?PieceJointe, libelle: string}|null
     */
    public function cible(string $type, int $id): ?array
    {
        if ($type === self::TYPE_COURRIER) {
            $courrier = Courrier::with('service')->find($id);

            return $courrier?->fichier_path
                ? ['chemin' => $courrier->fichier_path, 'courrier' => $courrier, 'brouillon' => null, 'piece' => null, 'libelle' => $courrier->numero_reference]
                : null;
        }

        if ($type === self::TYPE_PIECE_JOINTE) {
            $piece = PieceJointe::with('courrier.service')->find($id);

            return $piece?->courrier
                ? ['chemin' => $piece->fichier_path, 'courrier' => $piece->courrier, 'brouillon' => null, 'piece' => $piece, 'libelle' => $piece->courrier->numero_reference]
                : null;
        }

        if ($type === self::TYPE_BROUILLON) {
            $brouillon = CourrierBrouillon::find($id);

            return $brouillon?->fichier_path
                ? ['chemin' => $brouillon->fichier_path, 'courrier' => null, 'brouillon' => $brouillon, 'piece' => null, 'libelle' => __('Document scanné')]
                : null;
        }

        return null;
    }

    // Règle n°6 — mêmes droits que l'ancien aperçu : voir le contenu du
    // courrier (et ses pièces jointes), ou utiliser le brouillon.
    public function autoriser(User $user, string $type, int $id): array
    {
        $cible = in_array($type, self::TYPES, true) ? $this->cible($type, $id) : null;
        abort_if($cible === null, 404);

        $autorise = match ($type) {
            self::TYPE_COURRIER => $user->can('voirContenu', $cible['courrier']),
            self::TYPE_PIECE_JOINTE => $user->can('voirContenu', $cible['courrier']) && $user->can('voirPiecesJointes', $cible['courrier']),
            self::TYPE_BROUILLON => $user->can('utiliser', $cible['brouillon']),
        };
        abort_unless($autorise, 403);

        return $cible;
    }

    // Réponse de la visionneuse : les pages prêtes, ou « en préparation »
    // (le travail part en file d'attente, la visionneuse se recharge à
    // l'événement temps réel apercu.pret).
    public function manifeste(User $user, string $type, int $id): array
    {
        $cible = $this->autoriser($user, $type, $id);
        $apercu = ApercuDocument::where('fichier_type', $type)->where('fichier_id', $id)->first();

        if ($apercu === null) {
            PreparerApercuJob::dispatch($type, $id);

            return [
                'pret' => false,
                'canal' => $this->canal($cible),
                'evenement' => '.apercu.pret',
                'message' => __('Préparation de l\'aperçu sécurisé…'),
            ];
        }

        if ($apercu->statut === ApercuDocument::STATUT_NON_SUPPORTE) {
            return ['pret' => true, 'pages' => [], 'message' => __('Aperçu sécurisé indisponible pour ce format de fichier.')];
        }

        if ($cible['courrier']) {
            CourrierConsultation::enregistrer($cible['courrier']->id, $type === self::TYPE_PIECE_JOINTE ? 'apercu_piece_jointe' : 'apercu', $cible['piece']?->id);
        }

        return [
            'pret' => true,
            'pages' => collect($apercu->pages)->map(fn (array $dimensions, int $index) => [
                'url' => route('apercus.page', [$type, $id, $index + 1]),
                'largeur' => $dimensions[0],
                'hauteur' => $dimensions[1],
            ])->all(),
        ];
    }

    // Une page, filigranée au nom de la personne qui la regarde.
    public function page(User $user, string $type, int $id, int $numero): string
    {
        $cible = $this->autoriser($user, $type, $id);
        $apercu = ApercuDocument::where('fichier_type', $type)->where('fichier_id', $id)->first();
        abort_if($apercu === null || $numero < 1 || $numero > count($apercu->pages), 404);

        $image = Storage::disk('s3')->get($this->cheminPage($type, $id, $numero));
        abort_if($image === null, 404);

        return $this->filigraner($image, implode(' · ', [$user->name, now()->format('d/m/Y H:i'), $cible['libelle'], __('consultation seule')]));
    }

    public function canal(array $cible): string
    {
        return $cible['courrier']
            ? 'courrier.'.$cible['courrier']->id
            : 'App.Models.User.'.$cible['brouillon']->cree_par_id;
    }

    // Un nouveau fichier remplace l'ancien (nouvelle numérisation) : l'aperçu
    // repart de zéro, la visionneuse affiche « en préparation » d'ici là.
    public function invalider(string $type, int $id): void
    {
        ApercuDocument::where('fichier_type', $type)->where('fichier_id', $id)->delete();
    }

    public function cheminPage(string $type, int $id, int $numero): string
    {
        return "apercus/{$type}/{$id}/{$numero}.jpg";
    }

    // Appelé par PreparerApercuJob (Règle n°1) : rend les pages et les
    // stocke (chiffrées par le disque).
    public function preparer(string $type, int $id): void
    {
        $cible = $this->cible($type, $id);

        if ($cible === null) {
            return;
        }

        $extension = strtolower(pathinfo($cible['chemin'], PATHINFO_EXTENSION));
        $dossier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gec_apercu_'.Str::random(16);
        File::ensureDirectoryExists($dossier);

        try {
            $contenu = Storage::disk('s3')->get($cible['chemin']);

            if ($contenu === null) {
                throw new RuntimeException("Fichier introuvable sur le stockage : {$cible['chemin']}");
            }

            $source = $dossier.DIRECTORY_SEPARATOR.'source.'.$extension;
            file_put_contents($source, $contenu);

            $images = match (true) {
                $extension === 'pdf' => $this->rendrePdf($source, $dossier),
                in_array($extension, self::IMAGES, true) => [$this->normaliserImage($source, $dossier, $extension)],
                // TIFF et autres : ni GD ni Ghostscript ne savent les lire.
                default => null,
            };

            if ($images === null) {
                $this->enregistrer($type, $id, ApercuDocument::STATUT_NON_SUPPORTE, []);

                return;
            }

            $pages = [];

            foreach (array_values($images) as $index => $fichier) {
                if (! Storage::disk('s3')->put($this->cheminPage($type, $id, $index + 1), (string) file_get_contents($fichier))) {
                    throw new RuntimeException('Page d\'aperçu non enregistrée sur le stockage.');
                }

                [$largeur, $hauteur] = getimagesize($fichier);
                $pages[] = [$largeur, $hauteur];
            }

            // Pages d'une version précédente plus longue (nouvelle numérisation).
            foreach (Storage::disk('s3')->files("apercus/{$type}/{$id}") as $fichierStocke) {
                if ((int) pathinfo($fichierStocke, PATHINFO_FILENAME) > count($pages)) {
                    Storage::disk('s3')->delete($fichierStocke);
                }
            }

            $this->enregistrer($type, $id, ApercuDocument::STATUT_PRET, $pages);
        } finally {
            File::deleteDirectory($dossier);
        }
    }

    private function enregistrer(string $type, int $id, string $statut, array $pages): void
    {
        ApercuDocument::updateOrCreate(
            ['fichier_type' => $type, 'fichier_id' => $id],
            ['statut' => $statut, 'pages' => $pages, 'genere_le' => now()],
        );
    }

    /**
     * @return list<string>
     */
    private function rendrePdf(string $source, string $dossier): array
    {
        $binaire = config('services.ghostscript.binary');

        if (blank($binaire)) {
            throw new RuntimeException('Ghostscript n\'est pas configuré (GHOSTSCRIPT_PATH) : aperçu d\'un PDF impossible.');
        }

        // -dSAFER : le document reçu est une donnée non fiable.
        $resultat = Process::timeout(300)->run([
            $binaire, '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dQUIET',
            '-sDEVICE=jpeg', '-dJPEGQ=82', '-r'.self::RESOLUTION,
            '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
            '-dFirstPage=1', '-dLastPage='.self::PAGES_MAX,
            '-sOutputFile='.$dossier.DIRECTORY_SEPARATOR.'page-%04d.jpg',
            $source,
        ]);

        $images = glob($dossier.DIRECTORY_SEPARATOR.'page-*.jpg') ?: [];
        sort($images);

        if (! $resultat->successful() || $images === []) {
            throw new RuntimeException('Rendu des pages échoué : '.trim($resultat->errorOutput() ?: $resultat->output()));
        }

        return $images;
    }

    private function normaliserImage(string $source, string $dossier, string $extension): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($source));

        if ($image === false) {
            throw new RuntimeException('Image illisible.');
        }

        // Photos de téléphone : l'orientation est dans les métadonnées EXIF,
        // qu'on retire en ré-encodant ; on l'applique donc à l'image.
        if (in_array($extension, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
            $angle = match ((int) (@exif_read_data($source)['Orientation'] ?? 1)) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };

            if ($angle !== 0) {
                $image = imagerotate($image, $angle, 0);
            }
        }

        if (imagesx($image) > self::LARGEUR_MAX_IMAGE) {
            $image = imagescale($image, self::LARGEUR_MAX_IMAGE);
        }

        // Fond blanc sous une image transparente (PNG).
        $toile = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($toile, 0, 0, imagecolorallocate($toile, 255, 255, 255));
        imagecopy($toile, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        $sortie = $dossier.DIRECTORY_SEPARATOR.'page-0001.jpg';
        imagejpeg($toile, $sortie, 85);

        return $sortie;
    }

    // Texte en diagonale répété sur toute la page, encre bleu marine à ~25 %
    // d'opacité : lisible sans gêner la lecture du document.
    public function filigraner(string $jpeg, string $texte): string
    {
        $image = imagecreatefromstring($jpeg);
        imagealphablending($image, true);

        $largeur = imagesx($image);
        $hauteur = imagesy($image);
        $encre = imagecolorallocatealpha($image, 20, 40, 90, 96);
        $police = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');

        if (is_file($police)) {
            $taille = max(10, (int) round($largeur / 60));
            $boite = imagettfbbox($taille, 0, $police, $texte);
            $pasX = abs($boite[2] - $boite[0]) + $taille * 6;
            $pasY = $taille * 8;

            for ($ligne = 0, $y = 0; $y < $hauteur + $largeur; $y += $pasY, $ligne++) {
                for ($x = -$largeur + ($ligne % 2) * intdiv($pasX, 2); $x < $largeur; $x += $pasX) {
                    imagettftext($image, $taille, 30, $x, $y, $encre, $police, $texte);
                }
            }
        } else {
            $pasX = strlen($texte) * imagefontwidth(5) + 60;

            for ($y = 20; $y < $hauteur; $y += 70) {
                for ($x = 10; $x < $largeur; $x += $pasX) {
                    imagestring($image, 5, $x, $y, $texte, $encre);
                }
            }
        }

        ob_start();
        imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }
}
