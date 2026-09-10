<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email transport selection for the site-preferences email tab.
 *
 * The legacy `preferences` row already models SMTP (prefsEmailHost/Port/
 * Encrypt/Username/Password) but has no column naming *which* transport to
 * use — and shared hosts (cPanel and friends) commonly block outbound SMTP
 * while still relaying mail through the local sendmail binary, so SMTP
 * cannot be the only option. Two columns:
 *
 *   prefsEmailTransport — 'smtp' | 'sendmail' | 'resend' | 'postmark' | 'log'
 *   prefsEmailApiKey    — bearer key for the HTTPS providers (resend/postmark)
 *
 * Kept separate from prefsEmailPassword so switching between SMTP and an
 * API provider does not clobber the other transport's credential. NULL
 * means "not configured" — the mailer then falls back to the .env settings.
 * Same port-added-column pattern as prefsStripe (see
 * 2026_08_24_100000_add_prefsstripe_to_preferences_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline dump wipes the migrations
        // bookkeeping table, so the migrator replays this file. Tolerate
        // columns that already exist.
        Schema::table('preferences', function (Blueprint $table): void {
            if (! Schema::hasColumn('preferences', 'prefsEmailTransport')) {
                $table->text('prefsEmailTransport')->nullable();
            }
            if (! Schema::hasColumn('preferences', 'prefsEmailApiKey')) {
                $table->text('prefsEmailApiKey')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            if (Schema::hasColumn('preferences', 'prefsEmailTransport')) {
                $table->dropColumn('prefsEmailTransport');
            }
            if (Schema::hasColumn('preferences', 'prefsEmailApiKey')) {
                $table->dropColumn('prefsEmailApiKey');
            }
        });
    }
};
