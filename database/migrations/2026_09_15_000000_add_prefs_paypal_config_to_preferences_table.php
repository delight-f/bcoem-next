<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-entered PayPal credentials (issue #24 follow-up). The legacy
 * `preferences` row has prefsPaypal (a Y/N flag) but no free column for the
 * Orders v2 credentials, so this adds one JSON column:
 *
 *   {"mode":"sandbox","client_id":"...","client_secret":"<ciphertext>","webhook_id":"..."}
 *
 * The client secret is stored encrypted through Laravel Crypt (APP_KEY) —
 * see App\Support\Payments\PayPalSettings. Same port-added-column pattern as
 * prefsStripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline SQL dump wipes the migrations
        // bookkeeping table, so the migrator replays this file. Tolerate the
        // existing column.
        if (Schema::hasColumn('preferences', 'prefsPaypalConfig')) {
            return;
        }
        Schema::table('preferences', function (Blueprint $table): void {
            $table->text('prefsPaypalConfig')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            $table->dropColumn('prefsPaypalConfig');
        });
    }
};
