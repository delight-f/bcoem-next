<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\EntriesController;
use App\Support\Payments\PaymentEvent;
use App\Support\Payments\PaymentResult;
use App\Support\Payments\PaymentService;
use Illuminate\Support\Facades\DB;

/**
 * P5.5 back-office: participants / payments / entries admin + by_style
 * and by_substyle reports. Fixture prefix 'P55' everywhere.
 *
 * Pins:
 *  - admin gate on all five screens (guest → login, entrant → ?msg=99);
 *  - payment convergence (spec §8.3/D7): manual marking via HTTP and a
 *    Stripe success applied through PaymentService produce IDENTICAL
 *    payments + brewing rows modulo provider-only columns;
 *  - entry style re-assignment normalization (ledger/styles.md pins 1-4);
 *  - participants delete destroys the full legacy cascade;
 *  - entries delete removes the brewing row + one judging_scores row
 *    (legacy quirk: getOne, first match only);
 *  - count-by-style aggregation matches the legacy predicates on a
 *    seeded fixture.
 */
final class BackofficeTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p55.admin@brewingcompetitions.com';

    private const ENTRANT_EMAIL = 'p55.entrant@brewingcompetitions.com';

    private const ADMIN_ID = 9551;

    private const ENTRANT_ID = 9552;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $entries = [];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::ADMIN_EMAIL, self::ENTRANT_EMAIL] as $email) {
            DB::table('users')->where('user_name', $email)->delete();
        }
        DB::table('users')->insert([
            ['id' => self::ADMIN_ID, 'user_name' => self::ADMIN_EMAIL, 'password' => self::HASH, 'userLevel' => '1', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['id' => self::ENTRANT_ID, 'user_name' => self::ENTRANT_EMAIL, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
        ]);
        DB::table('brewer')->insert([
            'uid' => self::ENTRANT_ID,
            'brewerFirstName' => 'P55',
            'brewerLastName' => 'Entrant',
            'brewerEmail' => self::ENTRANT_EMAIL,
        ]);

        foreach (['prefsStyleSet', 'prefsSelectedStyles'] as $key) {
            $orig = DB::table('preferences')->where('id', 1)->value($key);
            if ($orig !== null) {
                $this->origPrefs[$key] = $orig;
            }
        }
        DB::table('preferences')->where('id', 1)->update([
            // Real BJCP2021 rows of the baseline set: 01/A (id 453) and
            // C1/A (id 578) — the two groups the fixtures count against.
            'prefsStyleSet' => 'BJCP2021',
            'prefsSelectedStyles' => '{"453":1,"578":1}',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->whereIn('id', $this->entries !== [] ? $this->entries : [0])->delete();
        DB::table('payments')->whereIn('entrant_uid', [self::ADMIN_ID, self::ENTRANT_ID])->delete();
        DB::table('judging_assignments')->where('bid', self::ENTRANT_ID)->delete();
        DB::table('staff')->where('uid', self::ENTRANT_ID)->delete();
        DB::table('brewer')->where('uid', self::ENTRANT_ID)->delete();
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::ENTRANT_ID])->delete();
        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

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
            'brewName' => 'P55 Entry',
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

    public function test_guest_and_entrant_are_rejected_on_all_screens(): void
    {
        $screens = [
            '/backoffice/participants', '/admin/payments', '/backoffice/entries',
            '/backoffice/count-by-style', '/backoffice/count-by-substyle',
        ];

        foreach ($screens as $screen) {
            $this->get($screen)->assertRedirect('/login');
        }

        $this->login(self::ENTRANT_EMAIL);
        foreach ($screens as $screen) {
            $this->get($screen)->assertRedirect('/?msg=99');
        }
    }

    public function test_payment_marking_converges_with_stripe_path(): void
    {
        $manualEntry = $this->makeEntry(['brewName' => 'P55 Converge Manual']);
        $stripeEntry = $this->makeEntry(['brewName' => 'P55 Converge Stripe']);
        $feeRow = DB::table('contest_info')->where('id', 1)->value('contestEntryFee');
        $fee = is_numeric((string) $feeRow) && (float) $feeRow > 0 ? (string) $feeRow : '8.00';

        // Path A — admin marks paid through the HTTP surface (the same
        // route the payments back office links to).
        $this->login(self::ADMIN_EMAIL);
        $this->post('/admin/payments/mark', [
            'entry_ids' => [$manualEntry],
            'pay_method' => 'check',
            'reference' => '#P55',
        ])->assertRedirect('/admin/payments/mark?msg=marked');

        // Path B — verified Stripe success applied through PaymentService
        // (exactly what the webhook/callback path does).
        app(PaymentService::class)->apply(
            new PaymentResult(PaymentEvent::Paid, 'evt_p55_stripe', 'pay_p55_stripe', $fee),
            [$stripeEntry],
            self::ENTRANT_ID,
            'stripe',
            $fee,
        );

        // Brewing rows: identical final flag state on both paths.
        $m = (array) DB::table('brewing')->where('id', $manualEntry)->first(['brewPaid', 'brewConfirmed']);
        $s = (array) DB::table('brewing')->where('id', $stripeEntry)->first(['brewPaid', 'brewConfirmed']);
        self::assertSame(1, (int) $m['brewPaid']);
        self::assertSame(1, (int) $m['brewConfirmed']);
        self::assertSame($m, $s);

        // Payments rows: shared columns byte-identical; only transport
        // columns differ (method/provider identity/manual audit fields).
        $pm = (array) DB::table('payments')->whereJsonContains('entry_ids', $manualEntry)->sole();
        $ps = (array) DB::table('payments')->whereJsonContains('entry_ids', $stripeEntry)->sole();
        foreach (['entrant_uid', 'amount', 'currency', 'status'] as $col) {
            self::assertSame($pm[$col], $ps[$col], "column {$col} diverges");
        }
        self::assertSame('paid', $pm['status']);

        // Both ledger rows render on the payments back office screen.
        $this->get('/admin/payments')
            ->assertOk()
            ->assertSee('Entry Fees')
            ->assertSee('paid');
    }

    public function test_entry_style_reassignment_normalizes_categories(): void
    {
        $entry = $this->makeEntry();
        $this->login(self::ADMIN_EMAIL);

        // Pin 2: numeric single-digit cat pads sort to '0X'.
        $this->put('/backoffice/entries/'.$entry, [
            'brewName' => 'P55 Normalized',
            'brewStyle' => '1-B',
            'brewPaid' => '1',
        ])->assertRedirect('/backoffice/entries?msg=updated');

        $row = (array) DB::table('brewing')->where('id', $entry)->first();
        self::assertSame('American Lager', $row['brewStyle']);
        self::assertSame('1', (string) $row['brewCategory']);
        self::assertSame('01', (string) $row['brewCategorySort']);
        self::assertSame('B', $row['brewSubCategory']);
        self::assertSame(1, (int) $row['brewPaid']);
        self::assertNotSame('', (string) $row['brewUpdated']);

        // Pin 3: alpha categories never padded.
        $this->put('/backoffice/entries/'.$entry, ['brewName' => 'P55 Alpha', 'brewStyle' => 'C1-A'])
            ->assertRedirect('/backoffice/entries?msg=updated');
        $row = (array) DB::table('brewing')->where('id', $entry)->first();
        self::assertSame('C1', (string) $row['brewCategorySort']);
        self::assertSame('C1', (string) $row['brewCategory']);
        self::assertSame('A', $row['brewSubCategory']);

        // Pin 4 choice: zero-padded input is NORMALIZED — legacy would
        // store an inconsistent wide brewCategorySort ('0002'); the port
        // trims first then pads ('01'). See EntriesController docblock.
        $this->put('/backoffice/entries/'.$entry, ['brewName' => 'P55 Padded', 'brewStyle' => '001-C'])
            ->assertRedirect('/backoffice/entries?msg=updated');
        $row = (array) DB::table('brewing')->where('id', $entry)->first();
        self::assertSame('1', (string) $row['brewCategory']);
        self::assertSame('01', (string) $row['brewCategorySort']);

        // Pin 1: a subcategory containing '-' cannot be posted — the code
        // must match an active styles row exactly.
        $this->from('/backoffice/entries/'.$entry.'/edit')
            ->put('/backoffice/entries/'.$entry, ['brewName' => 'P55 Bad', 'brewStyle' => '1-A-X'])
            ->assertSessionHasErrors('brewStyle');
    }

    public function test_participant_delete_cascades_like_legacy(): void
    {
        $entryId = $this->makeEntry();
        DB::table('judging_scores')->insert(['eid' => $entryId, 'bid' => self::ENTRANT_ID, 'scoreTable' => 1, 'scoreEntry' => $entryId]);
        DB::table('staff')->insert(['uid' => self::ENTRANT_ID, 'staff_judge' => 1]);
        DB::table('judging_assignments')->insert(['bid' => self::ENTRANT_ID, 'assignment' => 'J']);
        // Payments rows survive the cascade (same as legacy).
        DB::table('payments')->insert([
            'entrant_uid' => self::ENTRANT_ID, 'entry_ids' => json_encode([$entryId]),
            'amount' => '8.00', 'currency' => 'USD', 'method' => 'manual',
            'provider_ref' => '', 'event_id' => 'evt_p55_cascade', 'status' => 'paid',
        ]);

        $this->login(self::ADMIN_EMAIL);

        // Both screens render with the fixture visible.
        $this->get('/backoffice/participants')
            ->assertOk()
                        // Legacy renders "Last, First" + city/state small line.
            ->assertSee('Entrant, P55')
            ->assertSee('Participant Status');
        $this->get('/backoffice/participants?filter=judges')
            ->assertOk()
            ->assertSee('Available Judges');
        $this->get('/backoffice/participants/'.self::ENTRANT_ID.'/edit')
            ->assertOk()
            ->assertSee(self::ENTRANT_EMAIL);
        // Account security section (P4 Slice 4 residual): the edit form
        // carries the Change Security Question/Answer + Reset Password
        // fields legacy renders (brewer_form_0.pub.php:154-187).
        $editHtml = (string) $this->get('/backoffice/participants/'.self::ENTRANT_ID.'/edit')->getContent();
        self::assertStringContainsString('Change Security Question/Answer?', $editHtml);
        self::assertStringContainsString('name="userQuestion"', $editHtml);
        self::assertStringContainsString('name="userQuestionAnswer"', $editHtml);
        self::assertStringContainsString('name="password"', $editHtml);

        // PUT with changeSecurity=Y + a new password updates the user row
        // (process_brewer.inc.php:709-732, process_users.inc.php
        // change_user_password).
        $this->put('/backoffice/participants/'.self::ENTRANT_ID, [
            'brewerFirstName' => 'Entrant',
            'brewerLastName' => 'P55',
            'brewerEmail' => self::ENTRANT_EMAIL,
            'changeSecurity' => 'Y',
            'userQuestion' => 'What is the name of your first pet?',
            'userQuestionAnswer' => 'rex',
            'password' => 'new-pass-123',
        ])->assertRedirect('/backoffice/participants?msg=updated');
        self::assertSame('What is the name of your first pet?', DB::table('users')->where('id', self::ENTRANT_ID)->value('userQuestion'));
        self::assertTrue(app('hash')->check('rex', (string) DB::table('users')->where('id', self::ENTRANT_ID)->value('userQuestionAnswer')));
        self::assertTrue(app('hash')->check('new-pass-123', (string) DB::table('users')->where('id', self::ENTRANT_ID)->value('password')));
        // Self-delete guard.
        $this->delete('/backoffice/participants/'.self::ADMIN_ID)
            ->assertRedirect('/backoffice/participants?msg=self');

        $this->delete('/backoffice/participants/'.self::ENTRANT_ID)
            ->assertRedirect('/backoffice/participants?msg=deleted');

        self::assertSame(0, (int) DB::table('users')->where('id', self::ENTRANT_ID)->count());
        self::assertSame(0, (int) DB::table('brewer')->where('uid', self::ENTRANT_ID)->count());
        self::assertSame(0, (int) DB::table('brewing')->where('id', $entryId)->count());
        self::assertSame(0, (int) DB::table('judging_scores')->where('eid', $entryId)->count());
        self::assertSame(0, (int) DB::table('judging_scores_bos')->where('eid', $entryId)->count());
        self::assertSame(0, (int) DB::table('judging_assignments')->where('bid', self::ENTRANT_ID)->count());
        self::assertSame(0, (int) DB::table('staff')->where('uid', self::ENTRANT_ID)->count());
        // Financial record intentionally survives.
        self::assertSame(1, (int) DB::table('payments')->where('event_id', 'evt_p55_cascade')->count());
    }

    public function test_entries_delete_removes_brewing_and_first_score_row(): void
    {
        $entryId = $this->makeEntry();
        DB::table('judging_scores')->insert(['eid' => $entryId, 'bid' => self::ENTRANT_ID, 'scoreTable' => 1, 'scoreEntry' => $entryId]);

        $this->login(self::ADMIN_EMAIL);
        $this->delete('/backoffice/entries/'.$entryId)->assertRedirect('/backoffice/entries?msg=deleted');

        self::assertSame(0, (int) DB::table('brewing')->where('id', $entryId)->count());
        self::assertSame(0, (int) DB::table('judging_scores')->where('eid', $entryId)->count());

        // The participant itself is untouched (entries delete ≠ brewer delete).
        self::assertSame(1, (int) DB::table('brewer')->where('uid', self::ENTRANT_ID)->count());
    }

    public function test_count_by_style_aggregates_seeded_fixture(): void
    {
        // Group 01/A: two logged, one also paid+received.
        // Group C1/A: one logged only. Group 02 entry unconfirmed → ignored.
        $this->makeEntry(['brewCategorySort' => '01', 'brewSubCategory' => 'A']);
        $this->makeEntry(['brewCategorySort' => '01', 'brewSubCategory' => 'A', 'brewPaid' => 1, 'brewReceived' => 1]);
        $this->makeEntry(['brewCategorySort' => 'C1', 'brewSubCategory' => 'A']);
        $this->makeEntry(['brewCategorySort' => '02', 'brewSubCategory' => 'Z', 'brewConfirmed' => '0']);

        $this->login(self::ADMIN_EMAIL);

        // Sibling suites mutate the SHARED preferences row concurrently,
        // so re-pin our style set immediately before the request.
        DB::table('preferences')->where('id', 1)->update([
            'prefsStyleSet' => 'BJCP2021',
            'prefsSelectedStyles' => '{"453":1,"578":1}',
        ]);
        $response = $this->get('/backoffice/count-by-style');
        $response->assertOk()->assertSee('entry count broken down by style')
            ->assertSee('01 - Standard American Beer')
            ->assertSee('C1 - Standard Cider and Perry');

        // Expected values derived from the same DB state (legacy
        // predicates verbatim) rather than hardcoded, because sibling
        // suites may add brewing rows between our seed and this assert.
        $logged01 = (int) DB::table('brewing')->where('brewCategorySort', '01')->where('brewConfirmed', '1')->count();
        $pr01 = (int) DB::table('brewing')->where('brewCategorySort', '01')->where('brewConfirmed', '1')->where('brewPaid', '1')->where('brewReceived', '1')->count();
        $content = $response->getContent() ?: '';
        self::assertSame(1, substr_count($content, '<td>'.($logged01 + 1).'</td>'), 'C1 logged = 01 logged + 1');
        self::assertStringContainsString('<td>'.$logged01.'</td>', $content);
        self::assertGreaterThanOrEqual(1, substr_count($content, '<td>'.$pr01.'</td>'));

        // Sub-style breakdown renders one row per selected style.
        DB::table('preferences')->where('id', 1)->update([
            'prefsStyleSet' => 'BJCP2021',
            'prefsSelectedStyles' => '{"453":1,"578":1}',
        ]);
        $sub = $this->get('/backoffice/count-by-substyle');
        $sub->assertOk()->assertSee('American Light Lager')->assertSee('New World Cider');
    }

    public function test_entries_list_filters_by_view_category_and_participant(): void
    {
        $paid = $this->makeEntry(['brewPaid' => 1]);
        $unpaid = $this->makeEntry(['brewName' => 'P55 Unpaid', 'brewPaid' => 0]);

        $this->login(self::ADMIN_EMAIL);

        $all = $this->get('/backoffice/entries')->getContent() ?: '';
        self::assertStringContainsString(EntriesController::entryNumber($paid), $all);
        self::assertStringContainsString(EntriesController::entryNumber($unpaid), $all);

        $onlyPaid = $this->get('/backoffice/entries?view=paid')->getContent() ?: '';
        self::assertStringContainsString(EntriesController::entryNumber($paid), $onlyPaid);
        self::assertStringNotContainsString(EntriesController::entryNumber($unpaid), $onlyPaid);

        $byCat = $this->get('/backoffice/entries?filter=01&bid='.self::ENTRANT_ID)->getContent() ?: '';
        self::assertStringContainsString('P55 Entry', $byCat);
    }
}
