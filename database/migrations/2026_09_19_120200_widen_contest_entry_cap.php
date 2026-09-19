<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contest_info.contestEntryCap widened from int to decimal(8,2) (audit A2-05).
 *
 * The admin Fee Cap input and FeeCalculator treat the cap as currency
 * ("maximum amount for each entrant", step .01), but the column was an
 * integer, so a fractional cap such as 25.50 was rejected by the save rule
 * and could never be stored. Deliberate divergence from legacy, which
 * stored whole currency.
 *
 * Idempotent: a re-imported baseline dump already defines DECIMAL(8,2), so
 * the ALTER is a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->resize('decimal');
    }

    public function down(): void
    {
        $this->resize('integer');
    }

    private function resize(string $type): void
    {
        if (! Schema::hasColumn('contest_info', 'contestEntryCap')) {
            return;
        }

        Schema::table('contest_info', function (Blueprint $table) use ($type): void {
            if ($type === 'decimal') {
                $table->decimal('contestEntryCap', 8, 2)->nullable()->change();

                return;
            }

            $table->integer('contestEntryCap')->nullable()->change();
        });
    }
};
