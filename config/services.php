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

    // SIAKAD ⇄ CMS integration (v5 D31, docs/req/siakad-api-spec.md). Off while the URL or secret is empty.
    'cms' => [
        // e.g. https://admin.pondok.example/api/webhooks/siakad
        'url' => env('CMS_WEBHOOK_URL'),
        // Same value as SIAKAD_WEBHOOK_SECRET in the CMS; at least 32 random characters.
        'secret' => env('CMS_WEBHOOK_SECRET'),
        // The Bearer token the CMS sends to SIAKAD (SIAKAD_API_KEY in the CMS).
        'api_key' => env('SIAKAD_API_KEY'),
        // "article" once the CMS knows that type; "research" until then (v5 D29).
        'article_type' => env('CMS_ARTICLE_TYPE', 'article'),
        // Signs Tanya Hukum answers (v5 D30).
        'answered_by' => env('CMS_ANSWERED_BY', "Dewan Asatidz Ma'had Aly"),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
