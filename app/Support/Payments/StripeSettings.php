<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stripe platform keys for this install (issue #47 follow-up: in-app setup).
 *
 * The Connect flow needs a platform OAuth client id and secret key. Those
 * used to be env-only (STRIPE_CLIENT_ID / STRIPE_SECRET), which left the
 * Payment Setup screen telling the organizer to edit a file it cannot reach.
 * They are now saved in preferences.prefsStripe as JSON, alongside the
 * Connect values StripeConnectController already keeps there, with the
 * secret encrypted at rest through Laravel Crypt (APP_KEY). Nothing saved
 * falls back to the services.stripe env block, so an install configured the
 * old way keeps working unchanged.
 *
 * On-disk shape (shared with the Connect keys):
 *   {"account_id":"acct_…","webhook_secret":"whsec_…","currency":"usd",
 *    "client_id":"ca_…","secret":"<ciphertext>"}
 */
final class StripeSettings
{
    public const COLUMN = 'prefsStripe';

    /**
     * Resolved platform keys: the saved row when present, otherwise env.
     * 'source' tells the admin screen which one is live.
     *
     * @return array{client_id: string, secret: string, source: string}
     */
    public static function get(): array
    {
        $stored = self::column();
        $clientId = (string) ($stored['client_id'] ?? '');

        if ($clientId !== '') {
            return [
                'client_id' => $clientId,
                'secret' => self::decrypt((string) ($stored['secret'] ?? '')),
                'source' => 'database',
            ];
        }

        return self::fromEnv();
    }

    public static function hasClientId(): bool
    {
        return self::get()['client_id'] !== '';
    }

    /** Whether a usable secret exists (lets the form accept a blank). */
    public static function hasSecret(): bool
    {
        return self::get()['secret'] !== '';
    }

    /**
     * Persist admin-entered platform keys. A blank $secret keeps whatever is
     * already stored, so the form never has to echo the secret back.
     */
    public static function save(string $clientId, string $secret): void
    {
        $secret = $secret !== '' ? $secret : self::existingSecret();

        self::merge([
            'client_id' => $clientId,
            'secret' => $secret === '' ? '' : Crypt::encryptString($secret),
        ]);
    }

    /** Remove the saved platform keys (reverts to env, or disables Connect). */
    public static function forget(): void
    {
        self::merge(['client_id' => '', 'secret' => '']);
    }

    /**
     * Raw stored Connect/platform JSON (account_id, webhook_secret, currency,
     * client_id, secret) — the single reader for preferences.prefsStripe.
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return self::column();
    }

    /**
     * Patch the stored JSON; the patch wins on conflicting keys.
     *
     * @param  array<string, mixed>  $patch
     */
    public static function merge(array $patch): void
    {
        DB::table('preferences')->where('id', 1)->update([
            self::COLUMN => json_encode(array_merge(self::config(), $patch), JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array{client_id: string, secret: string, source: string}
     */
    private static function fromEnv(): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = (array) Config::get('services.stripe', []);

        return [
            'client_id' => (string) ($cfg['client_id'] ?? ''),
            'secret' => (string) ($cfg['secret'] ?? ''),
            'source' => 'env',
        ];
    }

    private static function existingSecret(): string
    {
        $stored = self::decrypt((string) (self::column()['secret'] ?? ''));

        // Fall back to the env-configured secret: hasSecret() counts env, so
        // a blank submit on an env-configured install must not wipe it.
        return $stored !== '' ? $stored : self::fromEnv()['secret'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function column(): array
    {
        try {
            $raw = DB::table('preferences')->where('id', 1)->value(self::COLUMN);
        } catch (Throwable) {
            return []; // console / no-DB contexts
        }

        $decoded = json_decode((string) ($raw ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return ''; // rotated/missing APP_KEY: treat as unset, never crash
        }
    }
}
