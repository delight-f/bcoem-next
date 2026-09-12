<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in email verification (signup spam protection, Task 4).
 *
 * The legacy `users` table has no verification column; this adds the single
 * nullable `email_verified_at` Laravel's MustVerifyEmail contract expects.
 * The address verified is `user_name` — see User::getEmailForVerification().
 * The feature is gated behind EMAIL_VERIFICATION_ENABLED (default off), so
 * existing rows (all NULL) keep working untouched on a default install.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a re-imported baseline SQL dump wipes the migrations
        // bookkeeping table, so the migrator may replay this file.
        if (Schema::hasColumn('users', 'email_verified_at')) {
            return;
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable()->after('user_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'email_verified_at')) {
            return;
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
    }
};
