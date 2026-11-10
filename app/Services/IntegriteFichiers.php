<?php

namespace App\Services;

use App\Models\Courrier;
use App\Models\CourrierHistorique;
use App\Models\EmpreinteFichier;
use App\Models\PieceJointe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

// Module 9 — intégrité des fichiers archivés (2026-10-30, conformité de
// l'archivage, voir DECISIONS.md) : empreinte SHA-256 scellée au premier
// passage puis revérifiée (VerifierIntegriteFichiersJob). Toujours appelé
// depuis un job (Règle n°1) : lire un fichier sur le stockage n'a pas sa
// place dans une requête.
class IntegriteFichiers
{
    // Contenu présent mais indéchiffrable : ne correspond à aucune empreinte.
    private const ILLISIBLE = '';

    // SHA-256 calculé en flux (mémoire constante, même pour un gros PDF) ;
    // null si le fichier est absent du stockage.
    public function empreinteDe(string $chemin): ?string
    {
        $disque = Storage::disk('s3');

        if (! $disque->exists($chemin)) {
            return null;
        }

        $flux = $disque->readStream($chemin);
        $contexte = hash_init('sha256');
        hash_update_stream($contexte, $flux);

        if (is_resource($flux)) {
            fclose($flux);
        }

        return hash_final($contexte);
    }

    // Scelle l'empreinte d'un fichier (premier passage), ou, s'il a changé de
    // chemin (déplacement à l'acceptation par le service), vérifie que son
    // contenu est resté le même. Fichier pas encore présent : rien (repris
    // par la vérification quotidienne), jamais une fausse alerte.
    // `$remplacement` : le document a été légitimement remplacé (nouvelle
    // numérisation, tracée dans l'historique) — nouvelle empreinte, jamais
    // une alerte « modifié » (2026-11-09).
    public function sceller(string $type, int $id, string $chemin, bool $remplacement = false): ?EmpreinteFichier
    {
        $existante = EmpreinteFichier::where('fichier_type', $type)->where('fichier_id', $id)->first();

        try {
            $empreinte = $this->empreinteDe($chemin);
        } catch (FilesystemException $e) {
            // Stockage injoignable : jamais une fausse alerte, la
            // vérification quotidienne reprendra ce fichier.
            Log::warning('Empreinte non scellée : stockage injoignable', compact('type', 'id', 'chemin') + ['erreur' => $e->getMessage()]);

            return null;
        } catch (FichierChiffreInvalide $e) {
            // Contenu chiffré illisible (modifié sur le stockage, ou clé
            // inconnue) : altéré s'il avait déjà une empreinte.
            Log::error('Empreinte non scellée : fichier chiffré illisible', compact('type', 'id', 'chemin') + ['erreur' => $e->getMessage()]);

            if ($existante === null || $remplacement) {
                return null;
            }

            $existante->chemin = $chemin;

            return $this->appliquerResultat($existante, self::ILLISIBLE);
        }

        if ($empreinte === null) {
            Log::warning('Empreinte non scellée : fichier absent du stockage', compact('type', 'id', 'chemin'));

            return null;
        }

        if ($existante !== null && $remplacement) {
            $existante->forceFill([
                'chemin' => $chemin,
                'sha256' => $empreinte,
                'scelle_le' => now(),
                'verifie_le' => now(),
                'statut' => EmpreinteFichier::STATUT_OK,
            ])->save();

            return $existante;
        }

        if ($existante === null) {
            return EmpreinteFichier::create([
                'fichier_type' => $type,
                'fichier_id' => $id,
                'chemin' => $chemin,
                'sha256' => $empreinte,
                'scelle_le' => now(),
                'verifie_le' => now(),
                'statut' => EmpreinteFichier::STATUT_OK,
            ]);
        }

        $existante->chemin = $chemin;

        return $this->appliquerResultat($existante, $empreinte);
    }

    // Chemin actuel du fichier d'après son enregistrement (courrier, copie
    // PDF/A ou pièce jointe), null si l'enregistrement n'en a plus.
    public function cheminActuel(string $type, int $id): ?string
    {
        return match ($type) {
            EmpreinteFichier::TYPE_COURRIER => Courrier::withTrashed()->whereKey($id)->value('fichier_path'),
            EmpreinteFichier::TYPE_COURRIER_PDFA => Courrier::withTrashed()->whereKey($id)->value('fichier_pdfa_path'),
            EmpreinteFichier::TYPE_PIECE_JOINTE => PieceJointe::whereKey($id)->value('fichier_path'),
            default => null,
        };
    }

    // Vérifie le fichier là où il se trouve AUJOURD'HUI : un fichier déplacé
    // depuis son scellement (ex. pièces jointes rangées dans le dossier du
    // service à l'acceptation) est relu à son nouveau chemin et comparé à la
    // même empreinte — jamais signalé introuvable à tort (constaté le
    // 2026-11-05 sur la vraie base, GEC-2026-000012).
    public function verifier(EmpreinteFichier $empreinte): EmpreinteFichier
    {
        $chemin = $this->cheminActuel($empreinte->fichier_type, $empreinte->fichier_id);

        if ($chemin !== null && $chemin !== $empreinte->chemin) {
            $empreinte->chemin = $chemin;
        }

        try {
            $calculee = $this->empreinteDe($empreinte->chemin);
        } catch (FichierChiffreInvalide) {
            // Le stockage a bien rendu un contenu, mais il ne se déchiffre
            // plus : modifié (l'authentification AES-GCM échoue), donc altéré.
            $calculee = self::ILLISIBLE;
        }

        return $this->appliquerResultat($empreinte, $calculee);
    }

    private function appliquerResultat(EmpreinteFichier $empreinte, ?string $calculee): EmpreinteFichier
    {
        $statut = match (true) {
            $calculee === null => EmpreinteFichier::STATUT_MANQUANT,
            hash_equals($empreinte->sha256, $calculee) => EmpreinteFichier::STATUT_OK,
            default => EmpreinteFichier::STATUT_ALTEREE,
        };

        $alerteNouvelle = $statut !== EmpreinteFichier::STATUT_OK && $empreinte->statut !== $statut;
        $retabli = $statut === EmpreinteFichier::STATUT_OK
            && in_array($empreinte->statut, [EmpreinteFichier::STATUT_ALTEREE, EmpreinteFichier::STATUT_MANQUANT], true);

        $empreinte->statut = $statut;
        $empreinte->verifie_le = now();
        $empreinte->save();

        if ($alerteNouvelle) {
            $this->alerter($empreinte);
        }

        // Une alerte levée reste dans l'historique (Règle n°5) : son
        // dénouement y est inscrit aussi, pour qu'un lecteur sache qu'elle
        // est close et pourquoi.
        if ($retabli) {
            $this->tracer($empreinte, 'integrite_retablie', "Fichier conforme à son empreinte scellée : {$empreinte->chemin}");
        }

        return $empreinte;
    }

    // Une alerte par changement d'état (jamais répétée chaque jour) :
    // journal d'erreurs + entrée dans l'historique du courrier (Règle n°5).
    private function alerter(EmpreinteFichier $empreinte): void
    {
        Log::critical('Intégrité d\'un fichier compromise', [
            'fichier_type' => $empreinte->fichier_type,
            'fichier_id' => $empreinte->fichier_id,
            'chemin' => $empreinte->chemin,
            'statut' => $empreinte->statut,
        ]);

        $this->tracer($empreinte, 'integrite_alerte', $empreinte->statut === EmpreinteFichier::STATUT_MANQUANT
            ? "Fichier introuvable sur le stockage : {$empreinte->chemin}"
            : "Le contenu du fichier ne correspond plus à son empreinte scellée : {$empreinte->chemin}");
    }

    private function tracer(EmpreinteFichier $empreinte, string $action, string $commentaire): void
    {
        $courrierId = $empreinte->fichier_type === EmpreinteFichier::TYPE_PIECE_JOINTE
            ? PieceJointe::whereKey($empreinte->fichier_id)->value('courrier_id')
            : $empreinte->fichier_id;

        if ($courrierId && Courrier::withTrashed()->whereKey($courrierId)->exists()) {
            CourrierHistorique::create([
                'courrier_id' => $courrierId,
                'auteur_id' => null,
                'action' => $action,
                'commentaire' => $commentaire,
            ]);
        }
    }
}
