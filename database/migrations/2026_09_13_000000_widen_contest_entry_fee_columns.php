<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entry-fee money columns widened to DECIMAL(9,2) — upstream 3.1.0's change
 * "Entry fee amounts now support more foreign currency formats without
 * being cut off" (update/run_update.php:4960-4972, guarded there by "is the
 * column already DECIMAL (246)?").
 *
 * Legacy installs held `float(6,2)` — at most 9999.99 — so a larger foreign
 * currency amount (a ₩15,000 Korean Won entry fee, say) was stored cut off
 * at the old ceiling. Upstream's exact new width is DECIMAL(9,2), i.e. up
 * to 9,999,999.99, and it covers the three fee columns:
 *
 *   contestEntryFee, contestEntryFee2, contestEntryFeePasswordNum
 *
 * (contestEntryFeeDiscountNum stays char(4) — that one is an entry count,
 * not an amount.)
 *
 * The column width is a no-op on a schema already carrying DECIMAL(9,2);
 * the ALTER exists for installs still at float(6,2), the same condition
 * upstream checks.
 *
 * Idempotent: a re-imported baseline SQL dump already contains these
 * columns but wipes the migrations bookkeeping table, so the migrator
 * replays this file. Tolerate missing/already-correct columns.
 */
return new class extends Migration
{
    /** @var list<string> upstream widened exactly these (run_update.php:4965-4967) */
    private const FEE_COLUMNS = [
        'contestEntryFee',
        'contestEntryFee2',
        'contestEntryFeePasswordNum',
    ];

    public function up(): void
    {
        $this->resize(9);
    }

    public function down(): void
    {
        // Pre-3.1.0 width, for a clean rollback of this file alone.
        $this->resize(6);
    }

    private function resize(int $precision): void
    {
        foreach (self::FEE_COLUMNS as $column) {
            if (! Schema::hasColumn('contest_info', $column)) {
                continue;
            }

            Schema::table('contest_info', function (Blueprint $table) use ($column, $precision): void {
                // Re-declare the whole column: MySQL CHANGE needs it, and the
                // baseline dump defines these as `decimal(…,2) DEFAULT NULL`.
                $table->decimal($column, $precision, 2)->nullable()->change();
            });
        }
    }
};
