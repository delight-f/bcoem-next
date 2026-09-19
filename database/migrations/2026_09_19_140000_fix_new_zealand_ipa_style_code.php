<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "New Zealand-Style India Pale Ale" was seeded sharing a style code with a
 * different style, so any lookup keyed on group + number cannot tell them
 * apart.
 *
 * Upstream corrected this in the Brewers Association catalog on 2026-09-19
 * (brewcompetitiononlineentry 25686a8): the row carried brewStyleNum '182',
 * identical to "New Zealand-Style Pale Ale", where it belongs at '183' —
 * styles_ba_2022_update.php seeded '183' originally, and the shipped
 * 3.0.X baseline dump carried the corrupted value.
 *
 * Left alone, StyleSets::findStyle() — group + num + version, no ORDER BY —
 * resolves to whichever row sorts first, and BrewController writes that
 * resolved name onto the entry's own brewStyle column, so an entry saved as
 * the India Pale Ale is stored and printed as the Pale Ale.
 *
 * Every matching row is retagged rather than one assumed row: an install that
 * re-ran the legacy style-update scripts can hold several duplicates, and
 * any one left behind keeps the collision alive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('styles')) {
            return;
        }

        DB::table('styles')
            ->where('brewStyleVersion', 'BA')
            ->where('brewStyleOwn', 'bcoe')
            ->where('brewStyleGroup', '06')
            ->where('brewStyleNum', '182')
            ->where('brewStyle', 'New Zealand-Style India Pale Ale')
            ->update(['brewStyleNum' => '183']);
    }

    /**
     * One-way: putting the code back to '182' would restore the collision
     * this migration exists to remove.
     */
    public function down(): void {}
};
