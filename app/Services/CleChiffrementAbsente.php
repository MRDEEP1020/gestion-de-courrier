<?php

namespace App\Services;

use RuntimeException;

// Aucune clé de chiffrement des fichiers configurée (GEC_FICHIERS_CLE) au
// moment d'écrire (2026-11-09) : on refuse d'enregistrer le fichier en
// clair. Volontairement hors de la famille League\Flysystem, que les disques
// configurés avec 'throw' => false avalent en silence : un dépôt refusé doit
// se voir.
class CleChiffrementAbsente extends RuntimeException {}
