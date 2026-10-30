<?php

declare(strict_types=1);

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

    'active_collab' => [
        'connect_timeout' => (int) env('ACTIVECOLLAB_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('ACTIVECOLLAB_TIMEOUT', 30),
        'lease_seconds' => (int) env('ACTIVECOLLAB_SYNC_LEASE_SECONDS', 120),
        'base_url' => env('ACTIVECOLLAB_BASE_URL', 'https://your-activecollab.example.com/api/v1'),
        'project_id' => (int) env('ACTIVECOLLAB_PROJECT_ID', 10),
        'api_token' => env('ACTIVECOLLAB_API_TOKEN'),

        // Заполнить после получения реальных ID из ActiveCollab
        'task_lists' => [
            // 'backend'  => 101,
            // 'frontend' => 102,
            // 'design'   => 103,
            // 'content'  => 104,
        ],

        // Ключ — имя из колонки «Ответственный», значение — AC user ID
        'assignees' => [
            // 'Tony Yats' => 3,
        ],

        // Ключ — значение severity в нижнем регистре, значение — AC label ID
        'labels' => [
            // 'critical' => null,
            // 'major'    => null,
            // 'minor'    => null,
        ],
    ],

    'google_sheets' => [
        'integration_token' => env('GOOGLE_SHEETS_INTEGRATION_TOKEN'),
    ],

];
