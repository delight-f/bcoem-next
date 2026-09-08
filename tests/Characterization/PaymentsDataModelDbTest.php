<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use BCOEM\Tests\Integration\MySqlTestCase;

/**
 * Characterization: payments data model against the baseline schema (P1.4).
 *
 * Findings pinned here:
 *   1. The `payments` table does NOT exist in the baseline schema — yet
 *      ppv.php sets $save_log_file = TRUE unconditionally and INSERTs into
 *      `$prefix."payments"` on every IPN hit. The insert fails silently
 *      (result unchecked), so legacy has NO working payment ledger.
 *   2. The only durable payment effect is brewing.brewPaid=1 +
 *      brewUpdated=NOW on each entry id from custom[1]; repeated IPNs are
 *      idempotent ON BREWING but there is no dedup surface at all.
 *
 * APPROVED DEVIATION (D2, spec P3.5a): the port designs a REAL payments
 * ledger — migration 2026_08_24_000000_create_payments_table.php creates
 * it. test_payments_table_exists_with_pinned_columns pins that the table
 * now EXISTS (with the designed columns) wherever the port runs; see
 * "Port decisions" in .scratch/bcoem-next/ledger/payments.md.
 */
final class PaymentsDataModelDbTest extends MySqlTestCase
{
    /** @var list<int> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            self::db()->where('id', $id)->delete('brewing');
        }
    }

    public function test_payments_table_exists_with_pinned_columns(): void
    {
        self::ensureMigrated();

        // MysqliDb returns assoc rows (Tables_in_...), not indexed.
        $tables = array_map(
            static fn ($row): string => (string) reset($row),
            self::db()->rawQuery("SHOW TABLES LIKE '%payments'"),
        );
        self::assertNotEmpty($tables, 'payments table missing after migrate');
        // MysqliDb prefixes table names even in raw queries.
        $columns = array_column(
            self::db()->rawQuery('SHOW COLUMNS FROM `payments`'),
            'Field',
        );
        foreach ([
            'id', 'entrant_uid', 'entry_ids', 'amount', 'currency', 'method',
            'provider_ref', 'event_id', 'status', 'note', 'admin_uid',
            'created_at', 'updated_at',
        ] as $column) {
            self::assertContains($column, $columns);
        }
    }

    public function test_ipn_entry_update_is_idempotent_on_brewing(): void
    {
        // ppv.php:153-160 write shape: brewPaid=1 + brewUpdated=NOW per id.
        self::db()->insert('brewing', [
            'brewName' => 'IPN Fixture',
            'brewCategorySort' => '15',
            'brewCategory' => '15',
            'brewSubCategory' => 'A',
            'brewBrewerID' => '9002',
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => 0,
        ]);
        $id = self::db()->getInsertId();
        if (! is_int($id)) {
            self::fail('insert failed');
        }
        $this->created[] = $id;

        // First notification...
        self::db()->where('id', $id)->update('brewing', [
            'brewPaid' => 1,
            'brewUpdated' => date('Y-m-d H:i:s', time()),
        ]);
        // ...duplicate notification (PayPal retries; no dedup exists).
        self::db()->where('id', $id)->update('brewing', [
            'brewPaid' => 1,
            'brewUpdated' => date('Y-m-d H:i:s', time()),
        ]);

        $row = self::db()->where('id', $id)->getOne('brewing');
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['brewPaid']);
    }
}
