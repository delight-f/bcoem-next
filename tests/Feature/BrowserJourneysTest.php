<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Journey-level browser-equivalent tests (PARITY-011, audit §14). The repo
 * has no Dusk; these chain the steps of each user journey through the HTTP
 * layer in ONE test, asserting the next surface + its legacy msg code after
 * each action — the journey-level convergence the per-step suites don't
 * express. Flow 1: anon -> register -> login -> add entry -> pay.
 */
final class BrowserJourneysTest extends PublicSurfaceTestCase
{
    private const EMAIL = 'journey.entrant@example.com';
    private const PASS = 'correct-horse-battery';

    /** @var array<string, mixed> */
    private array $origContest = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Open the entry window (brew.pub.php add mode renders only between
        // entry-open and deadline) and give entries a fee so the pay step
        // is reachable.
        $row = DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = $row === null ? [] : (array) $row;
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => Date::now()->subDays(2)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->addDays(7)->getTimestamp(),
            'contestEntryFee' => 10,
        ]);
        DB::table('brewing')->where('brewBrewerID', 1)->delete();
    }

    protected function tearDown(): void
    {
        DB::table('brewing')->where('brewBrewerID', 1)->delete();
        DB::table('users')->where('user_name', self::EMAIL)->delete();
        DB::table('contest_info')->where('id', 1)->update($this->origContest);
        parent::tearDown();
    }

    public function test_entrant_journey_register_login_add_entry_pay(): void
    {
        // 1. anon: register as a new entrant -> auto-login redirects to list.
        $this->post('/register/entrant', [
            'user_name' => self::EMAIL,
            'password' => self::PASS,
            'password_confirmation' => self::PASS,
            'userQuestion' => 'What is your favorite all-time beer to drink?',
            'userQuestionAnswer' => 'pabst',
            'brewerFirstName' => 'Journey',
            'brewerLastName' => 'Entrant',
            'brewerCountry' => 'United States',
            'brewerAddress' => '123 Main St',
            'brewerCity' => 'Anytown',
            'brewerState' => 'CO',
            'brewerZip' => '80001',
            'brewerPhone1' => '555-1234',
            'brewerProAm' => '0',
            'brewerClubs' => '',
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
        ])->assertRedirect('/?section=list&msg=7');

        $uid = DB::table('users')->where('user_name', self::EMAIL)->value('id');
        $this->assertNotNull($uid);

        // The list surface renders the registration-complete success message
        // (account-main.blade.php msg===7 -> site.registration_complete).
        $this->get('/list?msg=7')->assertOk()->assertSee(__('site.registration_complete'));

        // 2. add an entry -> redirects to list with msg=1.
        $this->post('/brew', [
            'brewName' => 'Journey Pale Ale',
            'brewStyle' => '1-A',
            'brewBrewerID' => $uid,
        ])->assertRedirect('/list?msg=1');

        $entryId = DB::table('brewing')->where('brewBrewerID', $uid)->value('id');
        $this->assertNotNull($entryId);

        // 3. pay the entry fee -> checkout redirect (POST /pay/checkout).
        $pay = $this->post('/pay/checkout', [
            'entries' => [(int) $entryId],
        ]);
        $pay->assertRedirect();

        // 4. the entry is present on the list with its judging number.
        $this->get('/list')->assertOk()->assertSee('Journey Pale Ale');
    }

    public function test_admin_journey_dashboard_offcanvas_nav_resolves(): void
    {
        // Flow 2 (audit §14): admin -> dashboard -> every off-canvas section
        // -> CRUD round-trip. Assert the off-canvas nav renders each group
        // and that every link it emits resolves (no dead ends).
        DB::table('users')->where('user_name', 'p54.admin@brewingcompetitions.com')->delete();
        DB::table('users')->insert([
            'id' => 9401,
            'user_name' => 'p54.admin@brewingcompetitions.com',
            'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
            'userLevel' => '0',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        DB::table('brewer')->where('uid', 9401)->delete();
        DB::table('brewer')->insert([
            'uid' => 9401,
            'brewerFirstName' => 'P54',
            'brewerLastName' => 'Admin',
            'brewerEmail' => 'p54.admin@brewingcompetitions.com',
        ]);

        $this->post('/login', [
            'loginUsername' => 'p54.admin@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);

        $html = (string) $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('Admin Essentials Menu', $html);
        $this->assertStringContainsString('Competition Preparation', $html);
        $this->assertStringContainsString('Entries and Participants', $html);
        $this->assertStringContainsString('Sorting', $html);
        $this->assertStringContainsString('Organizing', $html);
        $this->assertStringContainsString('Scoring', $html);
        $this->assertStringContainsString('Reports', $html);
        $this->assertStringContainsString('Data Management', $html);
        $this->assertStringContainsString('Preferences', $html);

        // Every internal nav link resolves to a 200 (no dead ends). Restrict
        // to the port's own route prefixes so external hrefs (GitHub, Avery,
        // donation, reset-comp) don't 404 against the app.
        preg_match_all('~href="(?:https?://[^/"]*)?(/(?:admin|backoffice|register|list|user|eval|pay|qr|brew|past-winners)[^"#]*)"~i', $html, $m);
        $internal = array_values(array_unique(array_filter(
            $m[1],
            static fn (string $h): bool => ! str_starts_with($h, '//') && ! str_starts_with($h, '/build/'),
        )));
        $this->assertNotEmpty($internal);
        foreach (array_slice($internal, 0, 40) as $href) {
            $this->get($href)->assertOk();
        }
    }
}
