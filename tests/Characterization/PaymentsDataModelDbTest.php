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

    public function test_payments_table_is_absent_from_schema(): void
    {
        $tables = array_column(
            self::db()->rawQuery("SHOW TABLES LIKE '%payments'"),
            0,
        );
        self::assertSame([], $tables, 'payments table unexpectedly exists');
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
