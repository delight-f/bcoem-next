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

    /*
| PayPal (issue #24). Per-install env configuration (D3): the provider is
| enabled only when client id, secret and webhook id are all present. PayPal
| needs no OAuth onboarding, unlike Stripe Connect.
|
| The mode selects the srmklive/paypal credential block (sandbox|live).
| PayPalGateway builds the SDK config array from these values directly, so the
| package's own config/paypal.php and its mode-specific env keys are
| deliberately not used.
*/
    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'currency' => env('PAYPAL_CURRENCY'),
        'app_id' => env('PAYPAL_APP_ID', ''),
    ],

    /*
| Cloudflare Turnstile (signup bot protection). The explicit `enabled`
| flag is deliberate: if it is on but the keys are blank/bad, that is a
| configuration mistake to surface (signups fail loudly), never a state
| the app silently reinterprets as "off". The keys are also read by the
| coderflex/laravel-turnstile package from config/turnstile.php.
*/
    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    /*
| Email verification of new signups. Only turn on where outbound mail
| actually works — otherwise the `verified` gate locks users out of entry
| submission and payment. Off by default.
*/
    'email_verification' => [
        'enabled' => env('EMAIL_VERIFICATION_ENABLED', false),
    ],

    /*
| Central clubs list (issue #22). The published JSON artifact produced by
| tools/clubs-sync/convert.js and mirrored into the public
| delight-f/clubs-list repo. The Laravel side only ever sees this URL and
| the agreed JSON shape; it has no knowledge of the upstream JavaScript.
| A failed fetch is a no-op, never a partial sync — see ClubsSyncService.
*/
    'clubs_list' => [
        'source_url' => env('CLUBS_LIST_URL', 'https://raw.githubusercontent.com/delight-f/clubs-list/main/dist/clubs.json'),
        'timeout_seconds' => (int) env('CLUBS_LIST_TIMEOUT', 10),
    ],

    /*
| GitHub Releases. The installer wizard's "update available" notice reads the
| latest published release for this repository. A restricted host with no
| outbound access simply gets no notice — see RemoteVersionChecker.
*/
    'github' => [
        'repository' => env('BCOEM_GITHUB_REPOSITORY', 'bcoem/bcoem-next'),
        'timeout_seconds' => (int) env('BCOEM_GITHUB_TIMEOUT', 5),
    ],

    /*
| Support contact shown on the upgrade failure screen. Pre-filled with the
| failure detail so a non-technical club member can relay it in one click.
*/
    'support' => [
        'email' => env('BCOEM_SUPPORT_EMAIL', 'support@brewingcompetitions.com'),
    ],
];
