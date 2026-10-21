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

];
