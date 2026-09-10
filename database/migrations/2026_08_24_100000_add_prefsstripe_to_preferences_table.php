<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-competition Stripe Connect settings (P3.5b). The legacy
 * `preferences` row (id=1) is the competition's settings record and has no
 * free column for gateway credentials, so this adds one JSON column:
 *
 *   {"account_id":"acct_...","webhook_secret":"whsec_..."}
 *
 * — the Standard connected account written by the admin OAuth flow, plus
 * the webhook endpoint signing secret pasted from the Stripe dashboard.
 * Same pattern as the other port-added JSON columns on this table
 * (prefsStyleLimits, prefsLanguageOptions). Documented in
 * .scratch/bcoem-next/ledger/payments.md under "P3.5b storage decision".
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline SQL dump already contains
        // `prefsStripe` but wipes the migrations bookkeeping table, so the
        // migrator replays this file. Tolerate the existing column.
        if (Schema::hasColumn('preferences', 'prefsStripe')) {
            return;
        }
        Schema::table('preferences', function (Blueprint $table): void {
            $table->text('prefsStripe')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('preferences', function (Blueprint $table): void {
            $table->dropColumn('prefsStripe');
        });
    }
};
