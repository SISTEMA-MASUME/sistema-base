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

    // Conciliación de OC de clientes con IA (Responses API). Solo se lee de .env, nunca en código.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'modelo' => env('IA_MODELO') ?: 'gpt-6-luna',
        'modelo_imagenes' => env('IA_MODELO_IMAGENES') ?: 'gpt-6-sol',
        // USD por millón de tokens [entrada, salida]
        'precios' => [
            'gpt-6-luna' => ['entrada' => 0.10, 'salida' => 0.50],
            'gpt-6-sol' => ['entrada' => 2.00, 'salida' => 10.00],
        ],
        'tope_mensual_usd' => (float) (env('IA_TOPE_MENSUAL_USD') ?: 10),
    ],

    // Programa de la PC de Daniel que envía el catálogo de SICAR (token Bearer, solo en .env).
    'sicar' => [
        'token' => env('SICAR_SYNC_TOKEN'),
    ],

    // Calendario de calendario@ejemplo.com (vacaciones): Apps Script publicado como aplicación web. Ver docs/CALENDARIO-VACACIONES.md.
    'calendario' => [
        'url' => env('CALENDARIO_URL'),
        'token' => env('CALENDARIO_TOKEN'),
    ],

];
