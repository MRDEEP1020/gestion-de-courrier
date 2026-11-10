<?php

namespace App\Services;

use App\Models\Courrier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

// Module 9 — copie d'archive au format PDF/A-2b (2026-10-30, conformité de
// l'archivage, voir DECISIONS.md) : norme d'archivage à long terme (polices
// incorporées, profil de couleurs, aucune dépendance externe). L'original
// n'est jamais modifié (Règle n°4) : la copie est écrite à côté
// (…-pdfa.pdf). Ghostscript reste en mode sûr (-dSAFER, document reçu =
// donnée non fiable), seule la lecture du profil de couleurs est autorisée.
// Appelé uniquement depuis ConvertirPdfaJob (Règle n°1).
class ConvertisseurPdfa
{
    // Chemin de la copie PDF/A, ou null si le document ne s'y prête pas
    // (image, Ghostscript non configuré). Lève une exception en cas d'échec
    // réel de conversion (le job retente).
    public function convertir(Courrier $courrier): ?string
    {
        $binaire = config('services.ghostscript.binary');

        if (! $binaire || ! $courrier->fichier_path || strtolower(pathinfo($courrier->fichier_path, PATHINFO_EXTENSION)) !== 'pdf') {
            return null;
        }

        $cible = preg_replace('/\.pdf$/i', '-pdfa.pdf', $courrier->fichier_path);

        // Écriture unique : une copie déjà produite n'est jamais réécrite.
        if (Storage::disk('s3')->exists($cible)) {
            return $cible;
        }

        $entree = tempnam(sys_get_temp_dir(), 'gec_pdfa_src_');
        $sortie = tempnam(sys_get_temp_dir(), 'gec_pdfa_out_');

        try {
            file_put_contents($entree, Storage::disk('s3')->get($courrier->fichier_path));

            $profils = dirname($binaire, 2).'/iccprofiles';
            $arguments = [
                $binaire, '-dPDFA=2', '-dBATCH', '-dNOPAUSE', '-dSAFER', '-dQUIET',
                '-sColorConversionStrategy=RGB', '-sDEVICE=pdfwrite', '-dPDFACompatibilityPolicy=1',
            ];

            if (is_file($profils.'/srgb.icc')) {
                array_push($arguments, '--permit-file-read='.$profils.'/', '-sOutputICCProfile='.$profils.'/srgb.icc');
            }

            array_push($arguments, '-sOutputFile='.$sortie, $entree);

            $resultat = Process::timeout(300)->run($arguments);

            if (! $resultat->successful() || filesize($sortie) === 0) {
                throw new RuntimeException('Conversion PDF/A échouée : '.trim($resultat->errorOutput() ?: $resultat->output()));
            }

            Storage::disk('s3')->put($cible, file_get_contents($sortie));

            return $cible;
        } finally {
            @unlink($entree);
            @unlink($sortie);
        }
    }
}
