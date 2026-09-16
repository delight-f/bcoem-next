<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment tab "Checkout Fees Paid by Entrant" (prefsTransFee) was a bare Y/N
 * switch with no rate to apply. These two columns hold the rate FeeCalculator
 * adds to the checkout/manual total when the switch is on: a percentage of
 * the subtotal plus a fixed amount (e.g. 2.90% + 0.30).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline dump wipes the migrations
        // bookkeeping table, so the migrator replays this file. Tolerate
        // columns that already exist.
        Schema::table('preferences', function (Blueprint $table): void {
            if (! Schema::hasColumn('preferences', 'prefsTransFeePercent')) {
                $table->decimal('prefsTransFeePercent', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('preferences', 'prefsTransFeeFixed')) {
                $table->decimal('prefsTransFeeFixed', 9, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            if (Schema::hasColumn('preferences', 'prefsTransFeePercent')) {
                $table->dropColumn('prefsTransFeePercent');
            }
            if (Schema::hasColumn('preferences', 'prefsTransFeeFixed')) {
                $table->dropColumn('prefsTransFeeFixed');
            }
        });
    }
};
