<?php

namespace App\Services;

use RuntimeException;

// Fichier chiffré illisible (2026-11-09) : en-tête inconnu, clé inconnue,
// bloc modifié, tronqué ou déplacé. Distinct des erreurs du stockage
// (League\Flysystem, stockage injoignable) : la vérification d'intégrité le
// traite comme un fichier altéré, jamais comme une panne passagère.
class FichierChiffreInvalide extends RuntimeException {}
