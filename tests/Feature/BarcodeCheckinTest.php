<?php

declare(strict_types=1);

namespace Tests\Feature;

use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Barcode check-in (P4.5, ticket 05). Pins the flag semantics of legacy
 * includes/process/process_barcode_check_in.inc.php against a seeded
 * brewing fixture:
 *  - known scan (by judging number, and by bare entry id) writes
 *    brewReceived='1' and touches NOTHING else (ledger #12 — paid and
 *    received are independent; brewConfirmed/brewJudgingNumber intact);
 *  - unknown scan flags "not found" and writes nothing;
 *  - duplicate scan is idempotent: an already-received entry stays '1'
 *    (legacy re-wrote '1' on every successful scan — there is NO undo
 *    path in the check-in module), and a judging number duplicated
 *    across entries (storable per ledger #9) is refused without any
 *    write — legacy's flag_jnum refusal;
 *  - undo: none exists in legacy; pinned here as "check-in never writes
 *    brewReceived=0" via the gating + duplicate cases.
 */
final class BarcodeCheckinTest extends PublicSurfaceTestCase
{
    private const ADMIN = 'admin.checkin@brewingcompetitions.com';

    private const ENTRANT = 'entrant.checkin@brewingcompetitions.com';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();

        MySqlTestCase::ensureMigrated();

        foreach ([self::ADMIN, self::ENTRANT] as $email) {
            DB::table('users')->where('user_name', $email)->delete();
        }

        DB::table('users')->insert([
            ['id' => 9301, 'user_name' => self::ADMIN, 'password' => self::HASH, 'userLevel' => '1', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['id' => 9302, 'user_name' => self::ENTRANT, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
        ]);
        DB::table('brewer')->insert([
            'uid' => 9302,
            'brewerFirstName' => 'Checkin',
            'brewerLastName' => 'Entrant',
            'brewerEmail' => self::ENTRANT,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->whereIn('id', $this->entries !== [] ? $this->entries : [0])->delete();
        DB::table('users')->whereIn('id', [9301, 9302])->delete();
        DB::table('brewer')->where('uid', 9302)->delete();

        parent::tearDown();
    }

    private function login(string $email): void
    {
        $this->post('/login', ['loginUsername' => $email, 'loginPassword' => 'bcoem']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return int brewing.id
     */
    private function makeEntry(array $overrides = []): int
    {
        DB::table('brewing')->insert(array_merge([
            'brewName' => 'Checkin Fixture',
            'brewCategorySort' => '10',
            'brewCategory' => '10',
            'brewSubCategory' => 'A',
            'brewBrewerID' => 9302,
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => 0,
            'brewJudgingNumber' => sprintf('%06d', random_int(1, 999999)),
        ], $overrides));
        $id = (int) DB::table('brewing')->max('id');
        $this->entries[] = $id;

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(int $id): array
    {
        return (array) DB::table('brewing')->where('id', $id)->sole();
    }

    public function test_guest_and_entrant_are_rejected(): void
    {
        $this->get('/admin/judging/checkin')->assertRedirect('/login');
        $this->post('/admin/judging/checkin', ['scan' => '111111'])->assertRedirect('/login');

        // Entrant (userLevel=2): in-controller gate bounces like legacy's
        // userLevel>1 → 403.php.
        $this->login(self::ENTRANT);
        $this->get('/admin/judging/checkin')->assertRedirect('/?msg=99');
        $this->post('/admin/judging/checkin', ['scan' => '111111'])->assertRedirect('/?msg=99');
    }

    public function test_known_scan_by_judging_number_marks_received_and_nothing_else(): void
    {
        $id = $this->makeEntry(['brewPaid' => 1]);
        $jnum = (string) $this->entryRow($id)['brewJudgingNumber'];
        $before = $this->entryRow($id);
        self::assertSame(0, (int) $before['brewReceived']);

        $this->login(self::ADMIN);
        $this->get('/admin/judging/checkin')
            ->assertOk()
            ->assertSee('Check-In Entries with a Barcode Reader/Scanner');

        $this->followingRedirects()
            ->post('/admin/judging/checkin', ['scan' => $jnum])
            ->assertOk()
            ->assertSee('checked in');

        $after = $this->entryRow($id);
        self::assertSame(1, (int) $after['brewReceived']);
        // Only the received flag moved — paid/confirmed/judging number are
        // untouched (ledger #12).
        self::assertSame($before['brewPaid'], $after['brewPaid']);
        self::assertSame($before['brewConfirmed'], $after['brewConfirmed']);
        self::assertSame($before['brewJudgingNumber'], $after['brewJudgingNumber']);

        // Session list accumulates checked-in scans like legacy's
        // barcode_entry_list.
        $this->get('/admin/judging/checkin')->assertSee($jnum);
    }

    public function test_known_scan_by_bare_entry_id_also_checks_in(): void
    {
        $id = $this->makeEntry();

        $this->login(self::ADMIN);
        $this->followingRedirects()
            ->post('/admin/judging/checkin', ['scan' => (string) $id])
            ->assertOk()
            ->assertSee('checked in');

        self::assertSame(1, (int) $this->entryRow($id)['brewReceived']);
    }

    public function test_unknown_scan_reports_not_found_and_writes_nothing(): void
    {
        $id = $this->makeEntry();

        $this->login(self::ADMIN);
        $this->followingRedirects()
            ->post('/admin/judging/checkin', ['scan' => '999999'])
            ->assertOk()
            ->assertSee('not found');

        self::assertSame(0, (int) $this->entryRow($id)['brewReceived']);
    }

    public function test_duplicate_rescan_is_idempotent_never_unreceives(): void
    {
        $id = $this->makeEntry();
        $jnum = (string) $this->entryRow($id)['brewJudgingNumber'];

        $this->login(self::ADMIN);
        $endpoint = '/admin/judging/checkin';

        // First scan checks in; second scan of the same label reports
        // already-checked-in and leaves the flag at '1'. Check-in has no
        // write path to brewReceived=0 — that is the whole undo story.
        $this->post($endpoint, ['scan' => $jnum]);
        $this->followingRedirects()
            ->post($endpoint, ['scan' => $jnum])
            ->assertOk()
            ->assertSee('already checked in');
        self::assertSame(1, (int) $this->entryRow($id)['brewReceived']);
    }

    public function test_scan_matching_duplicate_judging_numbers_is_refused(): void
    {
        // brewJudgingNumber has no unique index (ledger #9): two rows may
        // carry the same number. A scan then matches both rows and must be
        // refused without any write (legacy flag_jnum semantics).
        $a = $this->makeEntry(['brewJudgingNumber' => '888888']);
        $b = $this->makeEntry(['brewJudgingNumber' => '888888']);

        $this->login(self::ADMIN);
        $this->followingRedirects()
            ->post('/admin/judging/checkin', ['scan' => '888888'])
            ->assertOk()
            ->assertSee('already been assigned');

        self::assertSame(0, (int) $this->entryRow($a)['brewReceived']);
        self::assertSame(0, (int) $this->entryRow($b)['brewReceived']);
    }
}
