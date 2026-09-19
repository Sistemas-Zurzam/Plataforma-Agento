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

    // Webhook de estado de motorizado hacia plataforma_zazu (sistema propio
    // de Zazu Express, ajeno a este repo) — ver
    // NotificarEstadoMotorizadoZazuService. Es específico de una sola
    // empresa, por eso va en config/env y no en una tabla: si mañana otra
    // empresa necesita algo similar, ahí sí se justifica moverlo a una
    // tabla por-empresa.
    'zazu' => [
        'empresa_id' => env('ZAZU_EMPRESA_ID'),
        'webhook_url' => env('ZAZU_WEBHOOK_URL'),
        // Misma clave configurada en plataforma_zazu como AGENTO_WEBHOOK_SECRET
        // — viaja en la cabecera X-Api-Key (mismo esquema que ya usan los
        // demás webhooks entrantes de plataforma_zazu).
        'webhook_secret' => env('ZAZU_WEBHOOK_SECRET'),
    ],

];
