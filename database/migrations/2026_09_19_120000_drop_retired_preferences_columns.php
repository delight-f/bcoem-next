<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retired preference/style columns, dropped in one pass.
 *
 * Audit `docs/unwired-features-audit.md` A1-05/A1-06, A2-04 and B1-04:
 *
 *   preferences.prefsSEF           — the Search Engine Friendly URLs toggle;
 *                                    Laravel serves clean URLs unconditionally.
 *   preferences.prefsAutoPurge     — the legacy cron purge switch; replaced by
 *                                    the on-demand "Purge stale entries now".
 *   preferences.prefsPaypal        — IPN-era PayPal flag; the IPN receiver was
 *   preferences.prefsPaypalAccount   never ported and live PayPal reads
 *   preferences.prefsPaypalIPN       prefsPaypalConfig instead.
 *   styles.brewStyleAtLimit        — inert "Restrict Entries" flag; per-style
 *                                    caps run through EntryLimits/prefsStyleLimits.
 *
 * Their controls, writers and readers are removed in the same change, so the
 * columns hold nothing the application can use. Each drop is guarded by
 * Schema::hasColumn so a re-imported baseline dump (which no longer defines
 * them) and a partially-migrated install both replay cleanly.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PREFERENCE_COLUMNS = [
        'prefsSEF',
        'prefsAutoPurge',
        'prefsPaypal',
        'prefsPaypalAccount',
        'prefsPaypalIPN',
    ];

    public function up(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            foreach (self::PREFERENCE_COLUMNS as $column) {
                if (Schema::hasColumn('preferences', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('styles', 'brewStyleAtLimit')) {
            Schema::table('styles', function (Blueprint $table): void {
                $table->dropColumn('brewStyleAtLimit');
            });
        }
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            if (! Schema::hasColumn('preferences', 'prefsSEF')) {
                $table->char('prefsSEF', 1)->nullable();
            }
            if (! Schema::hasColumn('preferences', 'prefsAutoPurge')) {
                $table->boolean('prefsAutoPurge')->nullable();
            }
            if (! Schema::hasColumn('preferences', 'prefsPaypal')) {
                $table->char('prefsPaypal', 1)->nullable();
            }
            if (! Schema::hasColumn('preferences', 'prefsPaypalAccount')) {
                $table->string('prefsPaypalAccount')->nullable();
            }
            if (! Schema::hasColumn('preferences', 'prefsPaypalIPN')) {
                $table->boolean('prefsPaypalIPN')->nullable();
            }
        });

        if (! Schema::hasColumn('styles', 'brewStyleAtLimit')) {
            Schema::table('styles', function (Blueprint $table): void {
                $table->integer('brewStyleAtLimit')->nullable();
            });
        }
    }
};
