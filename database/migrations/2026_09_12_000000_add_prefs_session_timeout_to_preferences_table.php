<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-configurable session (auto-logout) timeout (upstream 3.1.0, GitHub
 * issue #870). `preferences.prefsSessionTimeout` holds the minutes of
 * inactivity before auto-logout; NULL means "use the installation default"
 * (legacy `$session_expire_after` in config.php, i.e. Laravel's
 * config('session.lifetime')).
 *
 * Column name, type and comment are upstream's, verbatim:
 *   update/run_update.php:5203-5205   (ALTER TABLE + comment)
 *   setup/install_db.setup.php:727    (baseline schema)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline SQL dump already contains
        // `prefsSessionTimeout` but wipes the migrations bookkeeping table, so
        // the migrator replays this file. Tolerate the existing column.
        if (Schema::hasColumn('preferences', 'prefsSessionTimeout')) {
            return;
        }
        Schema::table('preferences', function (Blueprint $table): void {
            $table->integer('prefsSessionTimeout')->nullable()
                ->comment('Minutes of inactivity before auto-logout; NULL falls back to $session_expire_after in config.php');
        });
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            $table->dropColumn('prefsSessionTimeout');
        });
    }
};
