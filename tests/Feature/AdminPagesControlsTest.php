<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A-class gap batch 2: sponsor logo upload screen (/admin/upload — legacy
 * go=upload), legacy-shape payments records page at /admin/payments, and
 * the Assign Flights to Rounds sub-screen (/admin/judging/flights/rounds).
 *
 * Pins:
 *  - uploads land in public/user_images with clean_filename+lowercase,
 *    rejected types bounce with legacy msg=30, delete with msg=31;
 *  - /admin/payments renders the transaction-records table (legacy
 *    admin/payments.admin.php shape) and per-record delete removes the row;
 *  - round reassignment follows process_judging_flights.inc.php
 *    action=assign semantics: assignments of the OLD round are deleted only
 *    when the round changes, then every judging_flights row of the
 *    table/flight moves to the new round.
 */
final class AdminPagesControlsTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p57.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9701;

    private const ENTRANT_ID = 9702;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<string> files this test created in public/user_images */
    private array $uploaded = [];

    /** @var list<int> */
    private array $flights = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->where('user_name', self::ADMIN_EMAIL)->delete();
        // The upload screen lists real files in public/user_images; the
        // listing test touches a fixture there, so guarantee the dir.
        $uploadDir = public_path('user_images');
        if (! is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '0',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        DB::table('brewer')->insert([
            'uid' => self::ENTRANT_ID,
            'brewerFirstName' => 'P57',
            'brewerLastName' => 'Entrant',
            'brewerEmail' => 'p57.entrant@brewingcompetitions.com',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->uploaded as $name) {
            $path = public_path('user_images/'.$name);
            if (is_file($path)) {
                unlink($path);
            }
        }

        DB::table('judging_assignments')->where('bid', self::ENTRANT_ID)->delete();
        DB::table('judging_flights')->whereIn('id', $this->flights !== [] ? $this->flights : [0])->delete();
        DB::table('judging_tables')->whereIn('tableName', ['P57 Main table', 'P57 Side table'])->delete();
        DB::table('payments')->where('event_id', 'evt_p57')->delete();
        DB::table('brewer')->where('uid', self::ENTRANT_ID)->delete();
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    private function login(): void
    {
        $this->post('/login', ['loginUsername' => self::ADMIN_EMAIL, 'loginPassword' => 'bcoem']);
    }

    private function trackUpload(string $name): void
    {
        if (! in_array($name, $this->uploaded, true)) {
            $this->uploaded[] = $name;
        }
    }

    // ── go=upload: sponsor logo images ──

    public function test_upload_page_renders_controls_and_listing(): void
    {
        $this->login();

        touch(public_path('user_images/p57-fixture.png'));
        $this->trackUpload('p57-fixture.png');

        $response = $this->get('/admin/upload');

        $response->assertOk()
            ->assertSee('Files in the Directory')
            ->assertSee('<th>File Name</th>', false)
            ->assertSee('<th>Date/Time Uploaded</th>', false)
            ->assertSee('p57-fixture.png');

        // Single-file variant (legacy action=html) renders its own labels;
        // the multi variant links to it as the "single image upload
        // function" instead.
        $this->get('/admin/upload?action=html')
            ->assertOk()
            ->assertSee('Upload Logo Image')
            ->assertSee('enhanced image upload function');
        $this->get('/admin/upload')
            ->assertOk()
            ->assertSee('single image upload function');
    }

    public function test_image_upload_stores_clean_lowercase_file(): void
    {
        $this->login();

        $upload = UploadedFile::fake()->image('My Sponsor Logo.PNG');
        $response = $this->post('/admin/upload', ['file' => [$upload]]);

        // handle.php + clean_filename: lowercased, scrubbed, dashes.
        $response->assertRedirect('/admin/upload?msg=29');
        $this->assertFileExists(public_path('user_images/my-sponsor-logo.png'));
        $this->trackUpload('my-sponsor-logo.png');

        // Rejected extension bounces with msg=30 and stores nothing.
        $bad = UploadedFile::fake()->create('evil.php', 10);
        $this->post('/admin/upload', ['file' => [$bad]])->assertRedirect('/admin/upload?msg=30');
    }

    public function test_image_delete_removes_file_with_legacy_msg(): void
    {
        $this->login();

        copy(public_path('favicon.ico'), public_path('user_images/p57-del.png'));
        $this->trackUpload('p57-del.png');
        $this->assertFileExists(public_path('user_images/p57-del.png'));

        $this->post('/admin/upload/delete', ['file' => 'p57-del.png'])
            ->assertRedirect('/admin/upload?msg=31');
        $this->assertFileDoesNotExist(public_path('user_images/p57-del.png'));
    }

    public function test_sponsors_page_links_to_upload_screen(): void
    {
        $this->login();

        $this->get('/admin/sponsors')
            ->assertOk()
            ->assertSee('Upload Sponsor Logo Images')
            ->assertSee('href="'.url('/admin/upload').'"', false);
    }

    // ── go=payments: transaction records page ──

    public function test_payments_records_table_renders_and_deletes(): void
    {
        $paymentId = $this->makePayment();
        $this->login();

        $response = $this->get('/admin/payments');

        $response->assertOk()
            // PayPal retired (payments plan W3): heading no longer carries
            // the legacy "PayPal Payments" label.
            ->assertSee(': Payments</p>', false)
            ->assertDontSee('PayPal Payments')
            // BS3 markers retired (backlog P4): view now uses d-none d-md-* per the BS5 port.
            ->assertSee('<th nowrap>Payer <span class="d-none d-lg-inline">Name</span></th>', false)
            ->assertSee('<th class="d-none d-md-table-cell">Item</th>', false)
            ->assertSee('<th>Am<span class="d-none d-md-inline">ount</span></th>', false)
            ->assertSee('<th>St<span class="d-none d-md-inline">atus</span></th>', false)
            ->assertSee('<th nowrap><span class="d-none d-md-inline">Transaction</span> ID</th>', false)
            ->assertSee('Entrant, P57')
            // Legacy cell format: entry ids as %06s.
            ->assertSee('000123, 000124')
            ->assertSee('p57-txn-1');

        $this->delete('/admin/payments/'.$paymentId)->assertRedirect('/admin/payments?msg=deleted');
        $this->assertSame(0, DB::table('payments')->where('event_id', 'evt_p57')->count());
    }

    // ── Assign Flights to Rounds ──

    /**
     * Seed 2 tables × entries in judging_flights. Table A has flights 1-2
     * already assigned to rounds 1-2; table B one flight, unassigned.
     */
    /** @param array<string, mixed> $overrides */
    private function seedFlightTables(array $overrides = []): void
    {
        foreach (['A' => 'P57 Main table', 'B' => 'P57 Side table'] as $key => $name) {
            DB::table('judging_tables')->insert([
                'tableName' => $name, 'tableNumber' => $key === 'A' ? 8 : 9,
                'tableLocation' => $overrides['locationId'] ?? null,
            ]);
        }
    }

    private function insertFlight(int $tableId, int $number, int $entryId, string|int|null $round): int
    {
        DB::table('judging_flights')->insert([
            'flightTable' => $tableId, 'flightNumber' => $number,
            'flightEntryID' => $entryId, 'flightRound' => $round,
        ]);

        return (int) DB::table('judging_flights')->max('id');
    }

    public function test_rounds_screen_lists_tables_flights_and_locations(): void
    {
        // Legacy hides the per-flight selects until the table has a location.
        DB::table('judging_locations')->insert([
            'judgingLocName' => 'P57 Hall', 'judgingDate' => '1700000000', 'judgingRounds' => 2,
        ]);
        $locId = (int) DB::table('judging_locations')->max('id');

        $this->seedFlightTables(['locationId' => $locId]);
        $a = (int) DB::table('judging_tables')->where('tableName', 'P57 Main table')->value('id');
        $b = (int) DB::table('judging_tables')->where('tableName', 'P57 Side table')->value('id');
        $this->flights[] = $this->insertFlight($a, 1, 501, 2);
        $this->flights[] = $this->insertFlight($b, 1, 502, 0); // legacy '' landed as 0 on the INT column

        $this->login();

        // The Assign Flights to Rounds button lives on the flights index;
        // legacy's rounds sub-screen itself only shows All Tables +
        // Add/Edit Flights.
        $this->get('/admin/judging/flights')->assertOk()->assertSee('Assign Flights to Rounds');

        $response = $this->get('/admin/judging/flights/rounds');

        $response->assertOk()
            ->assertSee('Table 8 &ndash; P57 Main table', false)
            ->assertSee('Not Assigned to a Round')
            ->assertSee('>Round 1</option>', false)
            ->assertSee('>Round 2</option>', false);

        DB::table('judging_locations')->where('id', $locId)->delete();
    }

    public function test_assigning_rounds_moves_flights_and_drops_old_round_assignments(): void
    {
        $this->seedFlightTables([]);
        $a = (int) DB::table('judging_tables')->where('tableName', 'P57 Main table')->value('id');
        $f1 = $this->insertFlight($a, 1, 501, 1);
        $f2 = $this->insertFlight($a, 1, 502, 1); // same table/flight, second row
        $this->flights = [$f1, $f2];

        // Judge assignment pinned to flight 1 round 1 — must be deleted on change.
        DB::table('judging_assignments')->insert([
            'bid' => self::ENTRANT_ID, 'assignment' => 'J',
            'assignTable' => $a, 'assignFlight' => 1, 'assignRound' => 1,
        ]);
        $assignmentId = (int) DB::table('judging_assignments')->max('id');

        $this->login();

        // Flight 1 of table A moves from round 1 to round 2.
        $response = $this->post('/admin/judging/flights/rounds', [
            'rounds' => [$a => [1 => '2']],
        ]);
        $response->assertRedirect('/admin/judging/flights/rounds');

        // Every flight row of the table/flight moved.
        $this->assertSame([2, 2], DB::table('judging_flights')->whereIn('id', [$f1, $f2])->orderBy('id')->pluck('flightRound')->all());
        // Old-round assignment deleted.
        $this->assertFalse(DB::table('judging_assignments')->where('id', $assignmentId)->exists());

        // Un-changed round is a no-op (posting the same value back).
        $before = DB::table('judging_flights')->whereIn('id', [$f1, $f2])->orderBy('id')->pluck('flightRound')->all();
        $this->post('/admin/judging/flights/rounds', ['rounds' => [$a => [1 => '2']]])->assertRedirect('/admin/judging/flights/rounds');
        $this->assertSame($before, DB::table('judging_flights')->whereIn('id', [$f1, $f2])->orderBy('id')->pluck('flightRound')->all());
    }

    public function test_unassigning_flight_clears_round(): void
    {
        $this->seedFlightTables([]);
        $a = (int) DB::table('judging_tables')->where('tableName', 'P57 Main table')->value('id');
        $this->flights[] = $this->insertFlight($a, 1, 501, 3);

        $this->login();

        // Choosing "Not Assigned to a Round" writes an empty round.
        $this->post('/admin/judging/flights/rounds', ['rounds' => [$a => [1 => '']]])
            ->assertRedirect('/admin/judging/flights/rounds');

        $this->assertSame(0, (int) DB::table('judging_flights')->where('id', $this->flights[0])->value('flightRound'));
    }

    // helpers used by payments test

    private function makePayment(): int
    {
        DB::table('payments')->insert([
            'entrant_uid' => self::ENTRANT_ID,
            'entry_ids' => json_encode([123, 124]),
            'amount' => '12.00',
            'currency' => 'USD',
            'method' => 'stripe',
            'provider_ref' => 'p57-txn-1',
            'event_id' => 'evt_p57',
            'status' => 'paid',
            'created_at' => now(),
        ]);

        return (int) DB::table('payments')->max('id');
    }
}
