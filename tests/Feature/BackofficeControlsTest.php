<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * A-class gap fix: legacy control set on /backoffice/participants (+ judge/
 * steward filters) and /backoffice/entries — matrix rows 50-53
 * (run-20260826-213414).
 *
 * Pins: Register/Assign/Print/email/status controls render with legacy
 * labels; filtered participants gain Assigned to Table(s) /
 * Has Entries In... / Updated columns from real rows; the Admin Actions
 * mark-all POST updates every brewing row and redirects with the legacy
 * msg code (headers.inc.php 642-656); print targets stay disabled links
 * (no port output route yet).
 */
final class BackofficeControlsTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p56.admin@brewingcompetitions.com';

    private const ENTRANT_EMAIL = 'p56.entrant@brewingcompetitions.com';

    private const ADMIN_ID = 9601;

    private const ENTRANT_ID = 9602;

    private const JUDGE_ID = 9603;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::ADMIN_EMAIL, self::ENTRANT_EMAIL] as $email) {
            DB::table('users')->where('user_name', $email)->delete();
        }
        DB::table('users')->insert([
            ['id' => self::ADMIN_ID, 'user_name' => self::ADMIN_EMAIL, 'password' => self::HASH, 'userLevel' => '0', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['id' => self::ENTRANT_ID, 'user_name' => self::ENTRANT_EMAIL, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-02-02 03:04:05', 'userAdminObfuscate' => 0],
            ['id' => self::JUDGE_ID, 'user_name' => 'p56.judge@brewingcompetitions.com', 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-03-03 00:00:01', 'userAdminObfuscate' => 0],
        ]);
        DB::table('brewer')->insert([
            ['uid' => self::ENTRANT_ID, 'brewerFirstName' => 'P56', 'brewerLastName' => 'Entrant', 'brewerEmail' => self::ENTRANT_EMAIL, 'brewerClubs' => 'P56 Club', 'brewerJudge' => null],
            ['uid' => self::JUDGE_ID, 'brewerFirstName' => 'P56', 'brewerLastName' => 'Judge', 'brewerEmail' => 'p56.judge@brewingcompetitions.com', 'brewerClubs' => null, 'brewerJudge' => 'Y'],
        ]);

        DB::table('judging_tables')->insert([
            'tableName' => 'P56 Main table', 'tableNumber' => 7,
        ]);
        $tableId = (int) DB::table('judging_tables')->max('id');
        DB::table('judging_assignments')->insert([
            'bid' => self::JUDGE_ID, 'assignment' => 'J', 'assignTable' => $tableId,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->whereIn('id', $this->entries !== [] ? $this->entries : [0])->delete();
        DB::table('judging_assignments')->where('bid', self::JUDGE_ID)->delete();
        DB::table('judging_tables')->where('tableName', 'P56 Main table')->delete();
        DB::table('brewer')->whereIn('uid', [self::ENTRANT_ID, self::JUDGE_ID])->delete();
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::ENTRANT_ID, self::JUDGE_ID])->delete();

        parent::tearDown();
    }

    private function login(): void
    {
        $this->post('/login', ['loginUsername' => self::ADMIN_EMAIL, 'loginPassword' => 'bcoem']);
    }

    /** @return int brewing.id */
    private function makeEntry(array $overrides = []): int
    {
        DB::table('brewing')->insert(array_merge([
            'brewName' => 'P56 Entry',
            'brewCategorySort' => '01',
            'brewCategory' => '1',
            'brewSubCategory' => 'A',
            'brewBrewerID' => self::ENTRANT_ID,
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => 0,
        ], $overrides));
        $id = (int) DB::table('brewing')->max('id');
        $this->entries[] = $id;

        return $id;
    }

    public function test_participants_page_renders_legacy_control_set(): void
    {
        $this->makeEntry();
        $this->login();

        $response = $this->get('/backoffice/participants');

        $response->assertOk()
            // Register... dropdown (labels verbatim, targets per 1deb35c).
            ->assertSee('Register...')
            ->assertSee('A Participant')
            ->assertSee('A Judge (Standard)')
            ->assertSee('A Judge (Quick)')
            ->assertSee(url('/register/judge').'?view=quick', false)
            ->assertSee(url('/register/steward').'?view=quick', false)
            // Assign/Unassign... dropdown.
            ->assertSee('Assign/Unassign...')
            ->assertSee('BOS Judges')
            ->assertSee('Judges/Stewards to Tables')
            // Print + modals + status column header.
            ->assertSee('Print Current View...')
            ->assertSee('All Participants Email Addresses')
            ->assertSee('Participant Status')
            // BS3 marker retired (backlog P4): the port uses BS5 d-print-none.
            ->assertSee('<th class="d-print-none">Updated</th>', false);
    }

    public function test_judges_filter_renders_table_and_entry_columns(): void
    {
        $this->makeEntry(['brewBrewerID' => self::JUDGE_ID]);
        $this->login();

        $response = $this->get('/backoffice/participants?filter=judges');

        $response->assertOk()
            ->assertSee('Assigned to Table(s)')
            ->assertSee('Has Entries In...')
            // table_assignments cell: "tableNumber - tableName".
            ->assertSee('7 - P56 Main table')
            // judge_entries cell: category+subcategory linked to the
            // entries admin filtered by sort.
            ->assertSee('>1A</a>', false)
            ->assertSee('filter=01', false)
            // BS3 marker retired (backlog P4): the port uses BS5 d-print-none.
            ->assertSee('<th class="d-print-none">Updated</th>', false);
    }

    public function test_quick_register_target_resolves(): void
    {
        $this->login();

        $this->get('/register/judge?view=quick')->assertOk();
        $this->get('/register/steward?view=quick')->assertOk();
    }

    public function test_email_modal_lists_filtered_participant_addresses(): void
    {
        $this->login();

        $judges = $this->get('/backoffice/participants?filter=judges');
        $judges->assertOk()->assertSee('All Available Judges Email Addresses');
        $this->assertStringContainsString(
            'p56.judge@brewingcompetitions.com',
            (string) $judges->getContent(),
        );

        // The entrant-only email must not leak into the judges modal list…
        // it lives on the unfiltered page instead.
        $all = $this->get('/backoffice/participants');
        $all->assertOk()->assertSee(self::ENTRANT_EMAIL);
    }

    public function test_entries_page_renders_legacy_control_set(): void
    {
        $this->makeEntry(['brewPaid' => 1]);
        $this->makeEntry(['brewPaid' => 0]);
        $this->login();

        $response = $this->get('/backoffice/entries');

        $response->assertOk()
            // Participant jump select.
            ->assertSee('Add an Entry For...')
            ->assertSee('Entrant, P56')
            // Print dropdowns link the ported entries print view
            // (outputs entries_print: 5 psort orders, all view modes).
            ->assertSee('Print Current View...')
            ->assertSee('By Entry Number')
            ->assertSee('entries_print', false)
            // Admin Actions dropdown.
            ->assertSee('Admin Actions')
            ->assertSee('Mark All as Paid')
            ->assertSee('Un-Mark All as Paid')
            ->assertSee('Confirm All Entries')
            // Status modal trigger + rows.
            ->assertSee('All Entry Status')
            ->assertSee('Confirmed Entries')
            // Copy/paste email modals.
            ->assertSee('All Participants with Entries Email Addresses')
            ->assertSee('All Participants with Paid Entries Email Addresses')
            ->assertSee(self::ENTRANT_EMAIL);

        // Per-row printer icon prints the entry's bottle labels — the
        // QR-bearing sheet (legacy entry-form-multi → bottle_label.output.php).
        $this->assertStringContainsString(
            '/admin/output/bottle_label?',
            (string) $response->getContent(),
        );
    }

    public function test_mark_all_as_paid_updates_every_row_and_redirects_with_legacy_msg(): void
    {
        $paidId = $this->makeEntry(['brewPaid' => 1]);
        $unpaidId = $this->makeEntry(['brewPaid' => 0]);
        $this->login();

        $response = $this->post('/backoffice/entries/mark-all', ['action' => 'unpaid']);

        // Legacy process_brewing.inc.php:1013: msg=34, WHOLE table reset.
        $response->assertRedirect('/backoffice/entries?msg=34');
        $this->assertSame(0, (int) DB::table('brewing')->where('id', $paidId)->value('brewPaid'));
        $this->assertSame(0, (int) DB::table('brewing')->where('id', $unpaidId)->value('brewPaid'));

        $paid = $this->post('/backoffice/entries/mark-all', ['action' => 'paid']);
        $paid->assertRedirect('/backoffice/entries?msg=20');
        $this->assertSame(1, (int) DB::table('brewing')->where('id', $unpaidId)->value('brewPaid'));

        // Success alert renders the legacy copy for the msg code.
        $this->get('/backoffice/entries?msg=20')
            ->assertOk()
            ->assertSee('All entries have been marked as paid.');
    }

    public function test_mark_all_rejects_unknown_action_and_non_admin(): void
    {
        $this->makeEntry();
        $this->login();

        $this->post('/backoffice/entries/mark-all', ['action' => 'nuke'])
            ->assertRedirect('/backoffice/entries');
        $this->assertSame(0, (int) DB::table('brewing')->where('id', $this->entries[0])->value('brewPaid'));

        $this->post('/logout');
        $this->post('/backoffice/entries/mark-all', ['action' => 'paid'])
            ->assertRedirect('/login');
    }
}
