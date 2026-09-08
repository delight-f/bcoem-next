<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\LegacyHasher;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the legacy-aware password hasher as the application hasher so
 * `Hash::check`/`Hash::needsRehash` (used by the `eloquent` user provider
 * during login) verify legacy `$2a$` hashes and flag them for rehash.
 *
 * Config `hashing.driver = legacy`; the driver is registered here so
 * HashManager->driver('legacy') resolves to the legacy-aware hasher.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Hash::extend('legacy', fn () => new LegacyHasher);
    }
}
