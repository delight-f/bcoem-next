<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PARITY-028 — safe equivalent of the legacy `custom_competition_info.pub.php`
 * deploy-time drop-in (index.pub.php:405 + nav "Other Info" link at
 * pub/nav.pub.php:109). The legacy hook is a file you FTP into place; this
 * port stores the block in the DB so a setup-from-scratch install can fill
 * it without touching the filesystem.
 *
 * `contestInfoExtra` is the optional HTML block rendered on the landing
 * page's competition-info surface (when non-empty) and gates the
 * "Other Info" nav item. NULL = absent (no section, no nav link), exactly
 * mirroring legacy's file_exists() gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline SQL dump already contains
        // `contestInfoExtra` but wipes the migrations bookkeeping table, so the
        // migrator replays this file. Tolerate the existing column.
        if (Schema::hasColumn('contest_info', 'contestInfoExtra')) {
            return;
        }
        Schema::table('contest_info', function (Blueprint $table): void {
            $table->mediumText('contestInfoExtra')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contest_info', function (Blueprint $table): void {
            $table->dropColumn('contestInfoExtra');
        });
    }
};
