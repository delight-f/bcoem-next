<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central clubs list (issue #22, Part B, Task B.1).
 *
 * The legacy baseline has no `clubs` table: clubs exist today only as a
 * `contestClubs` JSON array on `contest_info` and as free-text
 * `brewer.brewerClubs` values. This adds the local mirror of the published
 * upstream list so the two can be merged (see App\Support\Brewer\Clubs).
 *
 *   clubs.name             the club name as published upstream
 *   clubs.name_normalized  lowercased + trimmed, indexed: the sync matches
 *                          on this, so it never needs a LOWER() scan
 *   clubs.source           'upstream' (synced from the central list) or
 *                          'local' (added by a competition admin)
 *   clubs.last_seen_at     bumped each sync the club appears in; a
 *                          source='upstream' row older than the last sync
 *                          dropped off the list and is surfaced for review
 *
 * `brewer.brewerClubId` is the nullable reference the plan calls for. It is
 * added WITHOUT a foreign-key constraint on purpose: the legacy `brewer`
 * table is MyISAM (24 of the 25 baseline tables are), which MySQL does not
 * enforce FKs for, and an ALTER that tries would fail on real installs. The
 * column plus its index gives the reference; backfilling it from the
 * free-text `brewerClubs` is a deliberate separate follow-up, not silently
 * attempted here.
 *
 * `clubs_sync_state` is the single-row marker (Task B.3): the content-derived
 * version and timestamp of the last successful sync. A dedicated table keeps
 * it out of `preferences`, which is competition display config.
 *
 * Idempotent: a re-imported baseline dump wipes the migrations bookkeeping
 * table, so this file may be replayed against a schema that already has the
 * additions. Every step is guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clubs')) {
            Schema::create('clubs', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('name_normalized')->index();
                $table->string('source', 16)->default('upstream');
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('clubs_sync_state')) {
            Schema::create('clubs_sync_state', function (Blueprint $table): void {
                $table->id();
                $table->string('version', 64)->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('brewer', 'brewerClubId')) {
            Schema::table('brewer', function (Blueprint $table): void {
                $table->unsignedBigInteger('brewerClubId')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('brewer', 'brewerClubId')) {
            Schema::table('brewer', function (Blueprint $table): void {
                $table->dropColumn('brewerClubId');
            });
        }

        Schema::dropIfExists('clubs_sync_state');
        Schema::dropIfExists('clubs');
    }
};
