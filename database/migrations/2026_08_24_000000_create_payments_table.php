<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The real payment ledger (spec P3.5a). Approved D2 deviation: the
 * `payments` table does NOT exist in the legacy baseline schema — legacy
 * IPN inserts into it failed silently (payments ledger #1), so the port
 * designs the table instead of replicating its absence. Creates ONLY this
 * table.
 *
 * One row per settled event batch:
 *   entry_ids   JSON list of brewing.id values paid together.
 *   amount      decimal string of what was actually collected.
 *   method      'stripe' | 'manual' (PaymentService constants).
 *   provider_ref  gateway payment identifier (nullable for manual).
 *   event_id    UNIQUE — gateway event dedup key (ledger #7).
 *   status      'paid' | 'refunded'. No pending/cancelled rows are ever
 *               written today: rows appear only on verified success events
 *               (ledger #6), and cancelled checkouts have no local row to
 *               reverse. Column is a plain string so future states need no
 *               migration.
 *   admin_uid   users.id of the admin who marked payment manually; null
 *               for gateway-collected payments (audit field, ticket 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline SQL dump already contains
        // `payments` but wipes the migrations bookkeeping table, so the
        // migrator replays this file. Tolerate the existing table.
        if (Schema::hasTable('payments')) {
            return;
        }
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('entrant_uid')->index();
            $table->json('entry_ids');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('method');
            $table->string('provider_ref')->nullable()->index();
            $table->string('event_id')->unique();
            $table->string('status');
            $table->string('note')->nullable();
            $table->unsignedInteger('admin_uid')->nullable();
            // Manual-marking specifics (ticket 13): off-line collection
            // method and reference (check number etc.). Null otherwise.
            $table->string('pay_method')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
