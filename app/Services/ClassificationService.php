<?php

namespace App\Services;

use App\Models\Courrier;
use App\Models\RegleClassement;
use Illuminate\Support\Str;

class ClassificationService
{
    // Module 3 — Phase 1 : règles simples uniquement (pas de ML, voir PRD.md).
    // Cette classe isole la logique de classement pour pouvoir la remplacer par
    // un modèle ML en Phase 2 (sinistres) sans toucher au reste du code : le job
    // et l'interface ne connaissent que classer() / extraireMotsCles().

    public const MAX_MOTS_CLES_OCR = 10;

    // Longueur maximale d'un mot-clé (colonne mots_cles.libelle ; un « mot »
    // OCR plus long est presque toujours du bruit : URL, ligne collée, code-barres).
    public const LONGUEUR_MAX_MOT_CLE = 60;

    // Mots vides FR/EN les plus fréquents — suffisant pour des mots-clés "simples".
    private const MOTS_VIDES = [
        'dans', 'pour', 'avec', 'sans', 'sous', 'vers', 'chez', 'cette', 'cela', 'ceci', 'celui', 'celle',
        'nous', 'vous', 'elle', 'elles', 'leur', 'leurs', 'votre', 'notre', 'mais', 'donc', 'ainsi', 'alors',
        'aussi', 'comme', 'plus', 'moins', 'tout', 'tous', 'toute', 'toutes', 'être', 'etre', 'avoir', 'fait',
        'faire', 'objet', 'monsieur', 'madame', 'bonjour', 'cordialement', 'merci', 'veuillez', 'agréer',
        'salutations', 'date', 'suite', 'part', 'entre', 'après', 'apres', 'avant', 'pendant', 'depuis',
        'aucun', 'aucune', 'autre', 'autres', 'même', 'meme', 'très', 'tres', 'bien', 'dont', 'ceux',
        'this', 'that', 'with', 'from', 'have', 'your', 'will', 'been', 'were', 'they', 'them', 'their',
        'which', 'what', 'when', 'where', 'about', 'there', 'would', 'could', 'should', 'dear', 'please',
    ];

    // Nombre de courriers d'historique examinés pour la règle "expéditeur déjà
    // routé" — borne raisonnable pour une comparaison PHP (accents/casse) sans
    // scanner toute la table (Règle n°3) ; à revoir avec le Module 8 si le
    // volume dépasse cette fenêtre récente.
    public const FENETRE_HISTORIQUE_EXPEDITEUR = 200;

    /**
     * @return array{type_document: ?string, service_id: ?int, regle_id: ?int, regle_nom: ?string, tags: string[], service_source: ?string}
     */
    public function classer(Courrier $courrier): array
    {
        $resultat = [
            'type_document' => null, 'service_id' => null, 'regle_id' => null,
            'regle_nom' => null, 'tags' => [], 'service_source' => null,
        ];

        $champs = [
            'objet' => self::normaliser($courrier->objet),
            'expediteur' => self::normaliser(trim($courrier->expediteur_nom.' '.$courrier->expediteur_organisation)),
            // Un texte OCR de qualité insuffisante (echec_qualite) n'est jamais
            // pris en compte : un scan illisible ne doit pas produire de fausse
            // correspondance (constat de revue, corrigé le 2026-09-04).
            'texte_ocr' => self::normaliser($courrier->texteOcrExploitable()),
        ];

        $regles = RegleClassement::query()
            ->where('actif', true)
            ->orderBy('priorite')
            ->orderBy('id')
            ->get();

        foreach ($regles as $regle) {
            if (! self::correspond($regle, $champs)) {
                continue;
            }

            $type = $regle->type_document_propose ?: null;
            $service = $regle->service_propose_id;

            // Première règle (par priorité) qui propose un type ou un service :
            // elle l'emporte et est retenue comme règle à l'origine du classement.
            // Une règle qui ne pose que des tags n'est jamais « la règle » affichée.
            if ($resultat['regle_id'] === null && ($type !== null || $service !== null)) {
                $resultat['regle_id'] = $regle->id;
                $resultat['regle_nom'] = $regle->nom;
            }

            $resultat['type_document'] ??= $type;

            if ($resultat['service_id'] === null && $service !== null) {
                $resultat['service_id'] = $service;
                $resultat['service_source'] = 'regle';
            }

            // Les tags de toutes les règles qui correspondent se cumulent.
            foreach ($regle->tags ?? [] as $tag) {
                $resultat['tags'][] = self::libelle($tag);
            }
        }

        $resultat['tags'] = array_values(array_unique(array_filter($resultat['tags'])));

        // Règle métier confirmée par le client (specifications-modules-GEC.md,
        // Module 3) : "si cet expéditeur a déjà été routé vers un service par
        // le passé, ses courriers suivants sont automatiquement proposés pour
        // le même service" — prioritaire sur la règle mots-clés en cas de
        // conflit. Le type de document et les tags des mots-clés restent
        // inchangés ; seul le service est remplacé.
        if ($service = self::proposerServiceParHistoriqueExpediteur($courrier)) {
            $resultat['service_id'] = $service;
            $resultat['service_source'] = 'historique';

            // Aucune règle de mots-clés n'a par ailleurs proposé de type : la
            // seule origine de la proposition devient l'historique.
            if ($resultat['regle_id'] === null) {
                $resultat['regle_nom'] = "Historique de l'expéditeur";
            }
        }

        return $resultat;
    }

    // "Historique d'expéditeur" (spec Module 3) : comparaison insensible à la
    // casse et aux accents sur le nom OU l'organisation, limitée aux courriers
    // dont le classement a été explicitement validé par un agent (un signal
    // fiable, contrairement à une simple proposition jamais confirmée) — la
    // plus récente correspondance l'emporte.
    private static function proposerServiceParHistoriqueExpediteur(Courrier $courrier): ?int
    {
        $nom = self::normaliser($courrier->expediteur_nom);
        $organisation = self::normaliser($courrier->expediteur_organisation);

        if ($nom === '' && $organisation === '') {
            return null;
        }

        $historique = Courrier::query()
            ->where('classement_statut', 'valide')
            ->whereKeyNot($courrier->id)
            ->whereNotNull('service_id')
            ->where(fn ($q) => $q->whereNotNull('expediteur_nom')->orWhereNotNull('expediteur_organisation'))
            ->orderByDesc('updated_at')
            ->limit(self::FENETRE_HISTORIQUE_EXPEDITEUR)
            ->get(['id', 'expediteur_nom', 'expediteur_organisation', 'service_id']);

        foreach ($historique as $candidat) {
            $memeNom = $nom !== '' && self::normaliser($candidat->expediteur_nom) === $nom;
            $memeOrganisation = $organisation !== '' && self::normaliser($candidat->expediteur_organisation) === $organisation;

            if ($memeNom || $memeOrganisation) {
                return $candidat->service_id;
            }
        }

        return null;
    }

    /**
     * Mots-clés "extraits automatiquement du texte OCR" (spec Module 3) :
     * les mots significatifs les plus fréquents, hors mots vides et nombres.
     *
     * @return string[]
     */
    public function extraireMotsCles(?string $texte, int $max = self::MAX_MOTS_CLES_OCR): array
    {
        if (blank($texte)) {
            return [];
        }

        $frequences = [];

        // Les apostrophes séparent (élisions : « l'assuré » → « assuré », « qu'il » → rien).
        foreach (preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($texte)) as $mot) {
            $mot = trim($mot, '-');
            $longueur = mb_strlen($mot);

            // Lettres (et traits d'union) uniquement : écarte nombres, dates
            // (03-09-2026), références et bruit OCR mêlant lettres et chiffres (dla777).
            if ($longueur < 4 || $longueur > self::LONGUEUR_MAX_MOT_CLE || ! preg_match('/^\p{L}[\p{L}-]*\p{L}$/u', $mot) || in_array($mot, self::MOTS_VIDES, true)) {
                continue;
            }

            $frequences[$mot] = ($frequences[$mot] ?? 0) + 1;
        }

        uksort($frequences, fn ($a, $b) => [$frequences[$b], $a] <=> [$frequences[$a], $b]);

        return array_slice(array_keys($frequences), 0, $max);
    }

    /** @param array<string, string> $champs */
    private static function correspond(RegleClassement $regle, array $champs): bool
    {
        $surveilles = $regle->champs ?: RegleClassement::CHAMPS;
        $meule = implode(' ', array_intersect_key($champs, array_flip($surveilles)));

        if (trim($meule) === '') {
            return false;
        }

        foreach ($regle->mots_cles ?? [] as $motCle) {
            $motCle = self::normaliser($motCle);

            if ($motCle !== '' && str_contains($meule, $motCle)) {
                return true;
            }
        }

        return false;
    }

    // Comparaison insensible à la casse et aux accents ("Sinistre", "SINISTRÉ" → "sinistre").
    public static function normaliser(?string $texte): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower((string) $texte))));
    }

    public static function libelle(string $tag): string
    {
        return mb_strtolower(trim($tag));
    }
}
