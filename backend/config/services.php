<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'ringcentral' => [
        'client_id' => env('RINGCENTRAL_CLIENT_ID'),
        'client_secret' => env('RINGCENTRAL_CLIENT_SECRET'),
        'server_url' => env('RINGCENTRAL_SERVER_URL', 'https://platform.ringcentral.com'),
        'jwt' => env('RINGCENTRAL_JWT'),
    ],

    // Clé partagée M2M : webhooks publics `clients/bulk-*` / `clients/
    // convert-*` / `clients/create-no-reservations` protégés par l'en-tête
    // `X-Api-Key` (App\Http\Middleware\VerifyExternalSystemKey). Même valeur
    // sur les deux VPS : le serveur qui reçoit (secret GitHub -> .env) et
    // l'appelant qui envoie. Absente = tout est refusé (échec fermé).
    'external_system' => [
        'key' => env('EXTERNAL_SYSTEM_API_KEY'),
    ],

];
