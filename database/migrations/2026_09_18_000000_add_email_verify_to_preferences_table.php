<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email verification switch for the site-preferences email tab.
 *
 * The feature shipped env-only (EMAIL_VERIFICATION_ENABLED), which left the
 * admin page able to describe it but not to turn it on: enabling it meant
 * editing .env on the server. It now has a column, read through
 * App\Support\Security\EmailVerificationGate the same way the Turnstile
 * switch reads prefsCAPTCHA — an explicit admin choice wins, and the env
 * value stays as the default for an install that never opened the tab.
 *
 *   prefsEmailVerify — '1' on, '0' off, NULL = fall back to .env
 *
 * Same port-added-column pattern as prefsEmailTransport (see
 * 2026_09_10_000000_add_email_transport_to_preferences_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline dump wipes the migrations
        // bookkeeping table, so the migrator replays this file. Tolerate a
        // column that already exists.
        Schema::table('preferences', function (Blueprint $table): void {
            if (! Schema::hasColumn('preferences', 'prefsEmailVerify')) {
                $table->text('prefsEmailVerify')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            if (Schema::hasColumn('preferences', 'prefsEmailVerify')) {
                $table->dropColumn('prefsEmailVerify');
            }
        });
    }
};
