<?php

namespace App\Services;

use App\Models\NumeroSequence;
use App\Models\Parametre;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class NumeroReferenceGenerator
{
    // Module 1 — format {préfixe}-{année}-{séquence paddée}, ex.
    // GEC-2026-000123 par défaut (voir specifications-modules-GEC.md). La
    // séquence est incrémentée sous verrou de ligne par année ; la contrainte
    // d'unicité en base sur courriers.numero_reference reste le filet de
    // sécurité final en cas de concurrence (Règle n°3 CONVENTIONS.md).
    //
    // 2026-09-15 (voir DECISIONS.md, synchronisation SRS-GEC.pdf) : le format
    // dépendait auparavant du service (GEC-{année}-{code}-{séquence}), mais un
    // courrier entrant n'a plus de service connu au moment de l'enregistrement
    // (choisi par le DGA/ADJ DGA plus tard) — le compteur est désormais global
    // par année, indépendant du service.
    //
    // 2026-09-23 — préfixe et nombre de chiffres de la séquence configurables
    // depuis "Paramètres système" (Parametre::actuel()) au lieu d'être
    // codés en dur ; changer ces valeurs n'affecte jamais les numéros DÉJÀ
    // générés (la séquence en base continue, seul l'AFFICHAGE du prochain
    // numéro change).
    public function generer(?int $annee = null): string
    {
        $annee ??= (int) now()->year;

        $tentativesRestantes = 1;

        while (true) {
            try {
                $sequence = DB::transaction(function () use ($annee) {
                    $ligne = NumeroSequence::query()
                        ->lockForUpdate()
                        ->firstOrCreate(
                            ['annee' => $annee],
                            ['dernier_numero' => 0]
                        );

                    $ligne->increment('dernier_numero');

                    return $ligne->dernier_numero;
                });

                break;
            } catch (QueryException $e) {
                if ($tentativesRestantes === 0) {
                    throw $e;
                }

                $tentativesRestantes--;
            }
        }

        $parametres = Parametre::actuel();

        return sprintf(
            '%s-%d-%0'.$parametres->numero_reference_chiffres_sequence.'d',
            $parametres->numero_reference_prefixe,
            $annee,
            $sequence,
        );
    }
}
