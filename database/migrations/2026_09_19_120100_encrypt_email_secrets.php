<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt the stored mail credentials at rest (audit A3-03).
 *
 * prefsEmailPassword and prefsEmailApiKey held cleartext, unlike the
 * Stripe/PayPal secrets which go through Laravel Crypt (APP_KEY). They are
 * now encrypted on save (SitePreferencesController) and decrypted when the
 * mailer is configured (MailSettings).
 *
 * prefsEmailPassword is widened from varchar(255) to text first: a Crypt
 * ciphertext is longer than its plaintext, so a 255-character password would
 * otherwise be truncated (or rejected in strict mode).
 *
 * Existing rows are encrypted in place. Values that already decrypt (from a
 * replayed run) are left alone. No live installs carry these yet, so a
 * failure to decrypt is treated as "legacy plaintext" and encrypted too.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COLUMNS = ['prefsEmailPassword', 'prefsEmailApiKey'];

    public function up(): void
    {
        if (Schema::hasColumn('preferences', 'prefsEmailPassword')) {
            Schema::table('preferences', function (Blueprint $table): void {
                $table->text('prefsEmailPassword')->nullable()->change();
            });
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('preferences', $column)) {
                continue;
            }

            $rows = DB::table('preferences')->whereNotNull($column)->get(['id', $column]);
            foreach ($rows as $row) {
                $value = (string) $row->{$column};
                if ($value === '' || self::decryptable($value)) {
                    continue;
                }

                DB::table('preferences')->where('id', $row->id)->update([
                    $column => Crypt::encryptString($value),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('preferences', $column)) {
                continue;
            }

            $rows = DB::table('preferences')->whereNotNull($column)->get(['id', $column]);
            foreach ($rows as $row) {
                $value = (string) $row->{$column};
                if (! self::decryptable($value)) {
                    continue;
                }

                DB::table('preferences')->where('id', $row->id)->update([
                    $column => Crypt::decryptString($value),
                ]);
            }
        }

        if (Schema::hasColumn('preferences', 'prefsEmailPassword')) {
            Schema::table('preferences', function (Blueprint $table): void {
                $table->string('prefsEmailPassword', 255)->nullable()->change();
            });
        }
    }

    private static function decryptable(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
