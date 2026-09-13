<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PayPal credentials for this install (issue #24 follow-up: in-app setup).
 *
 * Saved in preferences.prefsPaypalConfig as JSON with the client secret
 * encrypted at rest through Laravel Crypt (APP_KEY). When nothing has been
 * saved, resolution falls back to the services.paypal env block, so an
 * install configured the old way keeps working unchanged.
 *
 * On-disk shape:
 *   {"mode":"sandbox","client_id":"...","client_secret":"<ciphertext>","webhook_id":"..."}
 */
final class PayPalSettings
{
    public const COLUMN = 'prefsPaypalConfig';

    /**
     * Resolved settings: the saved row when present, otherwise env. 'source'
     * tells the admin screen which one is live.
     *
     * @return array{mode: string, client_id: string, client_secret: string, webhook_id: string, source: string}
     */
    public static function get(): array
    {
        return self::stored() ?? self::fromEnv();
    }

    /** True when client id, secret and webhook id are all present. */
    public static function configured(): bool
    {
        $settings = self::get();

        return $settings['client_id'] !== ''
            && $settings['client_secret'] !== ''
            && $settings['webhook_id'] !== '';
    }

    /** Whether a usable secret already exists (lets the form accept a blank). */
    public static function hasSecret(): bool
    {
        return self::get()['client_secret'] !== '';
    }

    /**
     * Persist admin-entered credentials. A blank $clientSecret keeps whatever
     * is already stored, so the form never has to echo the secret back.
     *
     * @param  array{mode: string, client_id: string, client_secret: string, webhook_id: string}  $data
     */
    public static function save(array $data): void
    {
        $secret = $data['client_secret'] !== '' ? $data['client_secret'] : self::existingSecret();

        DB::table('preferences')->where('id', 1)->update([
            self::COLUMN => json_encode([
                'mode' => $data['mode'] === 'live' ? 'live' : 'sandbox',
                'client_id' => $data['client_id'],
                'client_secret' => $secret === '' ? '' : Crypt::encryptString($secret),
                'webhook_id' => $data['webhook_id'],
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /** Remove the saved credentials, reverting to env (or disabling PayPal). */
    public static function forget(): void
    {
        DB::table('preferences')->where('id', 1)->update([self::COLUMN => null]);
    }

    /**
     * @return array{mode: string, client_id: string, client_secret: string, webhook_id: string, source: string}|null
     */
    private static function stored(): ?array
    {
        $data = self::column();

        if (! is_array($data) || (string) ($data['client_id'] ?? '') === '') {
            return null;
        }

        return [
            'mode' => ($data['mode'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox',
            'client_id' => (string) ($data['client_id'] ?? ''),
            'client_secret' => self::decrypt((string) ($data['client_secret'] ?? '')),
            'webhook_id' => (string) ($data['webhook_id'] ?? ''),
            'source' => 'database',
        ];
    }

    /**
     * @return array{mode: string, client_id: string, client_secret: string, webhook_id: string, source: string}
     */
    private static function fromEnv(): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = (array) Config::get('services.paypal', []);

        return [
            'mode' => ($cfg['mode'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox',
            'client_id' => (string) ($cfg['client_id'] ?? ''),
            'client_secret' => (string) ($cfg['client_secret'] ?? ''),
            'webhook_id' => (string) ($cfg['webhook_id'] ?? ''),
            'source' => 'env',
        ];
    }

    private static function existingSecret(): string
    {
        $data = self::column();
        $stored = is_array($data) ? self::decrypt((string) ($data['client_secret'] ?? '')) : '';

        // Fall back to the env-configured secret: hasSecret() counts env, so
        // a blank submit on an env-configured install must not wipe it.
        return $stored !== '' ? $stored : self::fromEnv()['client_secret'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function column(): ?array
    {
        try {
            $raw = DB::table('preferences')->where('id', 1)->value(self::COLUMN);
        } catch (Throwable) {
            return null; // console / no-DB contexts
        }

        $decoded = json_decode((string) ($raw ?? ''), true);

        return is_array($decoded) ? $decoded : null;
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
