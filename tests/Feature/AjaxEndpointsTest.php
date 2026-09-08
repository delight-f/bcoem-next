<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * AJAX endpoints (ticket 17): username, valid_email, account_checks,
 * save, count_records. The response envelopes wrap the legacy HTML
 * fragments byte-for-byte (see AjaxController contract notes); save keeps
 * legacy's session/userLevel gates in the controller so an anonymous hit
 * gets status "9", not a redirect.
 */
final class AjaxEndpointsTest extends PublicSurfaceTestCase
{
    private const LOGIN = 'user.baseline@brewingcompetitions.com';

    private const OTHER_LOGIN = 'ajax.other@example.com';

    /** @var array<string, mixed> */
    private array $origContest = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1,
                'user_name' => self::LOGIN,
                'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
                'userLevel' => '0',
                'userCreated' => '2024-01-01 00:00:01',
                'userAdminObfuscate' => 0,
            ]);
            DB::table('brewer')->insert([
                'uid' => 1,
                'brewerFirstName' => 'Default',
                'brewerLastName' => 'Admin',
                'brewerEmail' => self::LOGIN,
            ]);
        } else {
            DB::table('users')->where('id', 1)->update(['userLevel' => '0']);
        }

        if (! DB::table('users')->where('user_name', self::OTHER_LOGIN)->exists()) {
            DB::table('users')->insert([
                'user_name' => self::OTHER_LOGIN,
                'password' => app('hash')->make('secret'),
                'userLevel' => '2',
                'userCreated' => '2024-01-01 00:00:01',
                'userAdminObfuscate' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->where('brewName', 'like', 'Ajax%')->delete();
        DB::table('users')->where('user_name', self::OTHER_LOGIN)->delete();
        DB::table('brewing')->whereIn('brewBrewerID', [999001, 999002])->delete();

        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }

        parent::tearDown();
    }

    private function login(string $level = '0'): void
    {
        DB::table('users')->where('id', 1)->update(['userLevel' => $level]);
        $this->actingAs(User::query()->findOrFail(1));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEntry(array $overrides = []): int
    {
        return (int) DB::table('brewing')->insertGetId(array_merge([
            'brewName' => 'Ajax Test Entry',
            'brewStyle' => 'American Amber Ale',
            'brewCategory' => '10',
            'brewCategorySort' => '10',
            'brewSubCategory' => 'A',
            'brewBrewerID' => 999001,
            'brewPaid' => 0,
            'brewReceived' => 0,
            'brewConfirmed' => '1',
        ], $overrides), 'id');
    }

    // -----------------------------------------------------------------
    // username
    // -----------------------------------------------------------------

    public function test_username_reports_available_email_with_legacy_fragment(): void
    {
        $response = $this->post('/ajax/username', ['user_name' => 'free.ajax@example.com']);

        $response->assertOk()->assertExactJson([
            'status' => '1',
            'message' => '<span class="text-success"><i class="fa fa-check-circle"></i> Congratulations! The email address you entered is not in use.</span>',
            'errors' => '',
        ]);
    }

    public function test_username_reports_taken_email_with_legacy_fragment(): void
    {
        $response = $this->post('/ajax/username', ['user_name' => strtoupper(self::LOGIN)]);

        $response->assertOk()->assertExactJson([
            'status' => '1',
            'message' => sprintf(
                '<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> %s</span>',
                self::t('site.alert_email_in_use'),
            ),
            'errors' => '',
        ]);
    }

    public function test_username_rejects_short_input(): void
    {
        $this->post('/ajax/username', ['user_name' => 'ab'])
            ->assertOk()
            ->assertExactJson(['status' => '0', 'message' => 'No username provided.', 'errors' => 'No POST variable provided.']);
    }

    public function test_username_degrades_invalid_email_to_empty_post_variable(): void
    {
        // Legacy queried with `false` and fell through to the empty branch.
        $this->post('/ajax/username', ['user_name' => 'not-an-email'])
            ->assertOk()
            ->assertExactJson(['status' => '0', 'message' => 'No username provided.', 'errors' => 'POST variable empty.']);
    }

    // -----------------------------------------------------------------
    // valid_email
    // -----------------------------------------------------------------

    public function test_valid_email_accepts_valid_address(): void
    {
        $this->post('/ajax/valid-email', ['email' => 'someone.example+tag@mail.org'])
            ->assertOk()
            ->assertExactJson([
                'status' => '1',
                'message' => '<span class="text-success"><i class="fa fa-check-circle"></i> Email format is valid.</span>',
                'errors' => '',
            ]);
    }

    public function test_valid_email_rejects_bad_format(): void
    {
        $this->post('/ajax/valid-email', ['email' => 'nope@'])
            ->assertOk()
            ->assertExactJson([
                'status' => '0',
                'message' => '<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> Email format is not valid.</span>',
                'errors' => '',
            ]);
    }

    public function test_valid_email_rejects_missing_input(): void
    {
        $this->post('/ajax/valid-email')
            ->assertOk()
            ->assertJsonPath('status', '0');
    }

    // -----------------------------------------------------------------
    // account_checks
    // -----------------------------------------------------------------

    public function test_account_checks_username_default_flags_duplicate(): void
    {
        $this->post('/ajax/account-checks', ['action' => 'username', 'go' => 'default', 'user_name' => self::LOGIN])
            ->assertOk()
            ->assertExactJson([
                'status' => '1',
                'message' => sprintf(
                    '<p class="text-danger"><i class="fas fa-exclamation-triangle pe-2"></i><strong>%s</strong></p>',
                    self::t('site.alert_email_in_use'),
                ),
                'errors' => '',
            ]);
    }

    public function test_account_checks_username_default_passes_free_email(): void
    {
        $response = $this->post('/ajax/account-checks', [
            'action' => 'username', 'go' => 'default', 'user_name' => 'fresh.check@example.com',
        ]);

        $response->assertOk()->assertJsonPath('status', '1')
            ->assertJsonPath('message', sprintf(
                '<p class="text-success"><i class="fas fa-check-circle pe-2"></i><strong>%s</strong></p>',
                self::t('site.alert_email_not_in_use'),
            ));
    }

    public function test_account_checks_email_action_validates(): void
    {
        $good = '<p class="text-success"><i class="fas fa-check-circle pe-2"></i><strong>Email format is valid.</strong></p>';
        $bad = '<p class="text-danger"><i class="fas fa-exclamation-triangle pe-2"></i><strong>Email format is not valid.</strong></p>';

        $this->post('/ajax/account-checks', ['action' => 'email', 'email' => 'ok.check@example.com'])
            ->assertOk()->assertExactJson(['status' => '1', 'message' => $good, 'errors' => '']);

        $this->post('/ajax/account-checks', ['action' => 'email', 'email' => 'broken@@example'])
            ->assertOk()->assertExactJson(['status' => '0', 'message' => $bad, 'errors' => '']);
    }

    public function test_account_checks_unknown_action_is_inert(): void
    {
        // go=forgot / check_answer are superseded by the P3.1c routes.
        $this->post('/ajax/account-checks', ['action' => 'check_answer'])
            ->assertOk()
            ->assertExactJson(['status' => '0', 'message' => '', 'errors' => '']);
    }

    // -----------------------------------------------------------------
    // save
    // -----------------------------------------------------------------

    public function test_save_requires_a_session_and_reports_status_9(): void
    {
        $eid = $this->makeEntry();

        $this->post('/ajax/save?action=brewing&go=brewPaid&id='.$eid, ['brewPaid' => '1'])
            ->assertOk()
            ->assertExactJson(['status' => '9', 'query' => '', 'post' => '0', 'input' => '', 'id' => (string) $eid, 'error_type' => '0']);

        $this->assertSame(0, (int) DB::table('brewing')->where('id', $eid)->value('brewPaid'));
    }

    public function test_save_ignores_entrant_level_users(): void
    {
        $eid = $this->makeEntry();
        $this->login('2');

        $this->post('/ajax/save?action=brewing&go=brewReceived&id='.$eid, ['brewReceived' => '1'])
            ->assertOk()
            ->assertJsonPath('status', '0');

        $this->assertSame(0, (int) DB::table('brewing')->where('id', $eid)->value('brewReceived'));
    }

    public function test_save_updates_admin_field_and_echoes_legacy_envelope(): void
    {
        $eid = $this->makeEntry();
        $this->login('0');

        $this->post('/ajax/save?action=brewing&go=brewBoxNum&id='.$eid, ['brewBoxNum' => 'A12'])
            ->assertOk()
            ->assertExactJson(['status' => '1', 'query' => '', 'post' => '0', 'input' => 'A12', 'id' => (string) $eid, 'error_type' => '0']);

        $row = (array) DB::table('brewing')->where('id', $eid)->first();
        $this->assertSame('A12', $row['brewBoxNum']);
        $this->assertNotNull($row['brewUpdated']);
    }

    public function test_save_writes_null_for_zero_and_empty_per_legacy_shape(): void
    {
        $eid = $this->makeEntry(['brewBoxNum' => 'Z9']);
        $this->login('0');

        // "0" → NULL.
        $this->post('/ajax/save?action=brewing&go=brewBoxNum&id='.$eid, ['brewBoxNum' => '0'])->assertOk();
        $this->assertNull(DB::table('brewing')->where('id', $eid)->value('brewBoxNum'));

        // Empty + rid2=text-col → '' (admin notes are text columns).
        $eid2 = $this->makeEntry(['brewAdminNotes' => 'note']);
        $this->post('/ajax/save?action=brewing&go=brewAdminNotes&rid2=text-col&id='.$eid2, ['brewAdminNotes' => ''])
            ->assertOk()->assertJsonPath('status', '1');
        $this->assertSame('', DB::table('brewing')->where('id', $eid2)->value('brewAdminNotes'));

        // Empty without rid2 → NULL.
        $eid3 = $this->makeEntry(['brewStaffNotes' => 'note']);
        $this->post('/ajax/save?action=brewing&go=brewStaffNotes&id='.$eid3, ['brewStaffNotes' => ''])
            ->assertOk()->assertJsonPath('status', '1');
        $this->assertNull(DB::table('brewing')->where('id', $eid3)->value('brewStaffNotes'));
    }

    public function test_save_normalizes_judging_number_like_legacy(): void
    {
        $eid = $this->makeEntry();
        $this->login('0');

        $this->post('/ajax/save?action=brewing&go=brewJudgingNumber&id='.$eid, ['brewJudgingNumber' => 'A^B^1'])
            ->assertOk()
            ->assertJsonPath('input', 'a-b-1');

        $this->assertSame('a-b-1', DB::table('brewing')->where('id', $eid)->value('brewJudgingNumber'));
    }

    public function test_save_rejects_disallowed_columns_without_writing(): void
    {
        $eid = $this->makeEntry();
        $this->login('0');

        // Legacy let the arbitrary column fail SQL (error_type 3); the port fails fast.
        $this->post('/ajax/save?action=brewing&go=brewName&id='.$eid, ['brewName' => 'hacked'])
            ->assertOk()
            ->assertJsonPath('error_type', '3');

        $this->assertSame('Ajax Test Entry', DB::table('brewing')->where('id', $eid)->value('brewName'));
    }

    // -----------------------------------------------------------------
    // count_records
    // -----------------------------------------------------------------

    public function test_count_records_counts_brewing_rows_by_column_filters(): void
    {
        $this->makeEntry(['brewBrewerID' => 999001, 'brewPaid' => 1]);
        $this->makeEntry(['brewBrewerID' => 999001, 'brewPaid' => 0]);
        $this->makeEntry(['brewBrewerID' => 999002, 'brewPaid' => 1]);

        $this->post('/ajax/count-records?section=brewing&p1=brewPaid&c1=1')
            ->assertOk()
            ->assertJsonFragment(['success' => true, 'count' => 2]);

        // Compound filter: paid AND received for one brewer.
        $this->makeEntry(['brewBrewerID' => 999001, 'brewPaid' => 1, 'brewReceived' => 1]);
        $this->post('/ajax/count-records?section=brewing&p1=brewPaid&c1=1&p2=brewReceived&c2=1&p3=brewBrewerID&c3=999001')
            ->assertOk()
            ->assertJsonFragment(['success' => true, 'count' => 1]);

        // Unfiltered count.
        $this->post('/ajax/count-records?section=brewing')
            ->assertOk()
            ->assertJsonFragment(['success' => true, 'count' => 4]);
    }

    public function test_count_records_rejects_unknown_sections(): void
    {
        $this->post('/ajax/count-records?section=nope')
            ->assertOk()
            ->assertExactJson(['success' => false, 'count' => 0, 'message' => 'Not Authorized.']);
    }

    public function test_count_records_total_fees_matches_flat_fee_model(): void
    {
        $row = (array) DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = collect($row)->except(['id'])->all();

        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryFee' => '8',
            'contestEntryFeeDiscount' => 'N',
            'contestEntryCap' => '0',
        ]);

        // Legacy totals per users.id via brewBrewerID, so entries must hang
        // off real user rows to contribute.
        $uid1 = (int) DB::table('users')->where('user_name', self::LOGIN)->value('id');
        $uid2 = (int) DB::table('users')->where('user_name', self::OTHER_LOGIN)->value('id');

        $this->makeEntry(['brewBrewerID' => $uid1, 'brewPaid' => 1]);
        $this->makeEntry(['brewBrewerID' => $uid1, 'brewPaid' => 0]);
        $this->makeEntry(['brewBrewerID' => $uid2, 'brewPaid' => 0]);

        $this->post('/ajax/count-records?section=brewing&p1=total-fees')
            ->assertOk()
            ->assertJsonFragment(['success' => true, 'count' => '24.00']);

        $this->post('/ajax/count-records?section=brewing&p1=total-fees-paid')
            ->assertOk()
            ->assertJsonFragment(['success' => true, 'count' => '8.00']);
    }

    public function test_count_records_updated_display_returns_timestamp(): void
    {
        $response = $this->post('/ajax/count-records?section=updated-display');

        $response->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('count', $response->json('updated'));
    }

    // -----------------------------------------------------------------
    // CSRF hardening (port deviation from legacy — document per ticket)
    // -----------------------------------------------------------------

    public function test_endpoints_are_post_only(): void
    {
        foreach (['username', 'valid-email', 'account-checks', 'save', 'count-records'] as $endpoint) {
            $this->get("/ajax/{$endpoint}")->assertStatus(405);
        }
    }

    public function test_requests_without_csrf_token_are_rejected(): void
    {
        $mw = new class($this->app, $this->app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            // Feature tests bypass token checks via runningUnitTests(); turn
            // that off here to exercise the real rejection path.
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $request = Request::create('/ajax/username', 'POST', ['user_name' => 'x@y.com']);
        $request->setLaravelSession(Session::driver());

        $this->expectException(TokenMismatchException::class);
        $mw->handle($request, fn () => response('ok'));
    }

    public function test_requests_with_matching_session_token_pass_csrf(): void
    {
        $mw = new class($this->app, $this->app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $store = Session::driver();
        $store->regenerate(); // ensure a real session token exists

        $request = Request::create('/ajax/username', 'POST', ['user_name' => 'csrf.ok@example.com', '_token' => $store->token()]);
        $request->setLaravelSession($store);

        $response = $mw->handle($request, fn (): Response => response('ok'));
        $this->assertSame('ok', $response->getContent());
    }

    private static function t(string $key): string
    {
        $value = trans($key);

        return is_string($value) ? $value : '';
    }
}
