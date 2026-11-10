<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Module 2 — OCR (voir DECISIONS.md). Chemin du binaire configurable pour
    // ne pas dépendre du PATH système, différent entre ce poste Windows et un
    // futur serveur Linux (où le défaut 'tesseract' suffit généralement).
    'tesseract' => [
        'binary' => env('TESSERACT_PATH', 'tesseract'),
        'tessdata' => env('TESSERACT_TESSDATA_PATH'),
    ],

    // Module 2 — conversion PDF → image avant l'OCR (voir DECISIONS.md "OCR
    // des PDF") : ce build de Tesseract ne sait pas lire un PDF lui-même
    // malgré le message d'erreur qui l'évoque ("Pdf reading is not
    // supported") — Ghostscript convertit chaque page en PNG au préalable.
    // Vide/absent : un PDF échoue proprement (comportement historique, voir
    // ProcessDocumentOcr::messageLisible()), jamais bloquant.
    'ghostscript' => [
        'binary' => env('GHOSTSCRIPT_PATH'),
    ],

    // Module 9 — conformité de l'archivage (2026-10-30, voir DECISIONS.md) :
    // scellement automatique des empreintes SHA-256 à chaque fichier ajouté
    // ou déplacé, et copie d'archive PDF/A à l'archivage. Actifs par défaut ;
    // désactivés en test (phpunit.xml), comme le disque de secours, pour
    // qu'aucun test ne lise le vrai stockage ni ne lance Ghostscript en tâche
    // de fond — les tests de ces fonctionnalités les réactivent explicitement.
    'archivage' => [
        'scellement_auto' => env('ARCHIVAGE_SCELLEMENT_AUTO', true),
        'pdfa_auto' => env('ARCHIVAGE_PDFA_AUTO', true),
        // 2026-11-09 — pages de l'aperçu sécurisé préparées en file
        // d'attente dès qu'un fichier arrive (désactivé en test, comme
        // ci-dessus : aucun test ne lance Ghostscript sans le demander).
        'apercu_auto' => env('ARCHIVAGE_APERCU_AUTO', true),
    ],

    // 2026-11-09 — occupation du stockage (Sécurité & Audit › Stockage) :
    // dossier de données du stockage objet, quand il est sur un disque de ce
    // serveur (MinIO), pour afficher l'espace libre. Vide pour un stockage
    // cloud (capacité gérée par le fournisseur).
    'stockage' => [
        'chemin_disque' => env('STOCKAGE_CHEMIN_DISQUE'),
    ],

    // 2026-11-09 — chiffrement des fichiers au repos (App\Services\
    // ChiffrementFichiers). Clé de 32 octets « base64:… », créée par
    // `php artisan gec:cle-fichiers`, à conserver EN LIEU SÛR hors du
    // serveur : la perdre rend tous les fichiers illisibles. Pour changer de
    // clé : nouvelle clé + nouvel identifiant, l'ancienne passe dans
    // GEC_FICHIERS_CLES_PRECEDENTES (« 1=base64:…,2=base64:… »).
    'chiffrement' => [
        'cle' => env('GEC_FICHIERS_CLE'),
        'cle_id' => (int) env('GEC_FICHIERS_CLE_ID', 1),
        'cles_precedentes' => env('GEC_FICHIERS_CLES_PRECEDENTES'),
    ],

];
