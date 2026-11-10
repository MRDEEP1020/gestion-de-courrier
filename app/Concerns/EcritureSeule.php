<?php

namespace App\Concerns;

use LogicException;

// Règle n°5 — journaux en écriture seule (2026-10-30, audit de conformité de
// l'archivage, « fix all of them ») : jusque-là, l'historique n'était
// immuable que par convention (aucun code ne le modifiait, mais rien ne
// l'empêchait). Toute mise à jour ou suppression passant par le modèle lève
// désormais une exception. Seule la suppression en cascade décidée par la
// base (suppression définitive du courrier lui-même, jamais proposée par
// l'application) reste possible : elle ne passe pas par le modèle.
trait EcritureSeule
{
    public static function bootEcritureSeule(): void
    {
        static::updating(function ($ligne) {
            throw new LogicException(class_basename($ligne).' est en écriture seule : une entrée existante ne se modifie jamais.');
        });

        static::deleting(function ($ligne) {
            throw new LogicException(class_basename($ligne).' est en écriture seule : une entrée existante ne se supprime jamais.');
        });
    }
}
