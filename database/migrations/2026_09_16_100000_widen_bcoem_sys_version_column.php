<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `bcoem_sys.version` widened so a release version fits.
 *
 * The legacy schema declares it `varchar(12)`: enough for "3.1.0.0", not enough
 * for a version carrying a pre-release suffix. Writing "4.1.0-alpha.3" (13
 * characters) fails with SQLSTATE 22001 "Data too long for column 'version'" —
 * and it fails on the *last* step, after the migrations have already run, so an
 * upgrade lands applied-but-unmarked and has to be re-run. Found upgrading a
 * real 3.1.0.0 tenant to 4.1.0-alpha.3.
 *
 * 32 covers semver with a pre-release and build metadata with room to spare.
 *
 * Idempotent: a re-imported baseline dump resets the column to varchar(12) and
 * wipes the migrations bookkeeping table, so the migrator replays this file.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->resize(32);
    }

    public function down(): void
    {
        // The legacy width, for a clean rollback of this file alone.
        $this->resize(12);
    }

    private function resize(int $length): void
    {
        if (! Schema::hasColumn('bcoem_sys', 'version')) {
            return;
        }

        Schema::table('bcoem_sys', function (Blueprint $table) use ($length): void {
            $table->string('version', $length)->nullable()->change();
        });
    }
};
