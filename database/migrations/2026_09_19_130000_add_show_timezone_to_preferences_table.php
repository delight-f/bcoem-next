<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Show Time Zone" switch for the site-preferences Localization block.
 *
 * Every rendered date goes through DateFmt::dateTime(), whose $withZone
 * argument decides whether a zone name (", AEST") is appended — but the
 * argument was a per-call-site literal, so the same kind of timestamp showed
 * a zone on one screen and not on another (and on /admin/dates the grey
 * placeholder carried a zone the field's own value never did). This column
 * makes it one site-wide policy.
 *
 *   prefsShowTimezone — 'Y' on, 'N' off, NULL = on (an install that has never
 *   saved the tab keeps the previous always-show behaviour)
 *
 * Read through TenantContext::showTimezone(). Same port-added-column pattern
 * as prefsEmailVerify (see 2026_09_18_000000_add_email_verify_to_preferences_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline dump wipes the migrations
        // bookkeeping table, so the migrator replays this file. Tolerate a
        // column that already exists.
        Schema::table('preferences', function (Blueprint $table): void {
            if (! Schema::hasColumn('preferences', 'prefsShowTimezone')) {
                $table->text('prefsShowTimezone')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            if (Schema::hasColumn('preferences', 'prefsShowTimezone')) {
                $table->dropColumn('prefsShowTimezone');
            }
        });
    }
};
