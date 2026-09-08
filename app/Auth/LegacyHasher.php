<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Hashing\AbstractHasher;

/**
 * Legacy-aware password hasher for the `users` table.
 *
 * Legacy hashes (pre-1.3.0.0 era, still present in corpus dumps) are
 * phpass bcrypt over md5(plaintext): `password_hash` with cost 8,
 * `portable_hashes=false`, producing a `$2a$`-prefixed hash of
 * md5($password). Modern rows are plain `password_hash()` (`$2y$`).
 *
 * Verification tries modern first, then the legacy md5 branch — matching
 * legacy `password_verify_legacy()`. Because phpass with
 * `portable_hashes=false` emits standard bcrypt, verification is native
 * `password_verify()` in both branches: no phpass implementation is
 * vendored (spec §9: phpass not ported).
 *
 * `needsRehash()` returns true for `$2a$` legacy hashes so Laravel's
 * rehash-on-login (`Hash::needsRehash` + `$user->forceFill(...)->save()`)
 * transparently upgrades them to `$2y$` bcrypt on first successful login,
 * exactly like legacy `upgrade_legacy_password_hash()`.
 */
class LegacyHasher extends AbstractHasher implements HasherContract
{
    /**
     * @param  array{rounds?: int}  $options
     */
    public function make(#[\SensitiveParameter] $value, array $options = []): string
    {
        return password_hash($value, PASSWORD_BCRYPT, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if ($hashedValue === null || $hashedValue === '') {
            return false;
        }

        if (is_string($hashedValue) && str_starts_with($hashedValue, '$2a$')) {
            return password_verify(md5($value), $hashedValue);
        }

        return password_verify($value, (string) $hashedValue);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return is_string($hashedValue) && str_starts_with($hashedValue, '$2a$');
    }
}
