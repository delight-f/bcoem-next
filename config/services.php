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

    /*
| Stripe Connect (P3.5b). Platform-level credentials only: the OAuth
| client id + secret key of the Stripe account hosting the Connect
| onboarding. Per-competition values (connected account id, webhook
| signing secret) live in preferences.prefsStripe (JSON), written by the
| admin Connect flow — see App\Support\Payments\StripeGateway::forTenant().
*/
    'stripe' => [
        'client_id' => env('STRIPE_CLIENT_ID'),
        'secret' => env('STRIPE_SECRET'),
    ],
];
