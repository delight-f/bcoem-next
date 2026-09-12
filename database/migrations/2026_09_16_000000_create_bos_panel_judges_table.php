<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOS panel judge capture (issue #25 review, fix 4).
 *
 * The BJCP experience-point schedule caps the BOS Judge bonus per panel:
 * "5-14 entries, including beer = 3 BOS Judges", "3-14 meads and/or ciders
 * (only) = 3 BOS Judges", "15 or more entries of any type or combination =
 * 5 BOS Judges". The legacy schema records only the global
 * `staff.staff_judge_bos` flag, so there is no way to know who actually sat
 * which panel — the cap cannot be computed.
 *
 *   bos_panel_judges.bosType  style_types id the panel judged (Beer, Cider,
 *                             Mead, or the combined Mead/Cider row)
 *   bos_panel_judges.uid      brewer id of a judge on that panel
 *
 * The (bosType, uid) pair is unique: a judge sits a panel once. No foreign
 * key — the legacy baseline tables are MyISAM, which MySQL does not enforce
 * FKs for, and an ALTER that tried would fail on real installs.
 *
 * Idempotent: a re-imported baseline dump wipes the migrations bookkeeping
 * table, so this file may be replayed against a schema that already has the
 * table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bos_panel_judges')) {
            Schema::create('bos_panel_judges', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('bosType')->index();
                $table->unsignedBigInteger('uid')->index();
                $table->timestamps();

                $table->unique(['bosType', 'uid']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bos_panel_judges');
    }
};
