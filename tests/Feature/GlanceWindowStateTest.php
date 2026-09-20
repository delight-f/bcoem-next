<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * At-a-glance deck window states (issue #60).
 *
 * Pinned contract: a window whose open or close date is unset must render as
 * CLOSED, never Open with a blank "Closes/Opens" line. Two bugs produced that
 * broken card:
 *   - Windows::derive()'s W2 rule forced drop-off/shipping Open whenever either
 *     date was blank ("Open / Closes not set / Opens not set").
 *   - PublicController::listGlanceCards()'s $fmt closure turned a null epoch
 *     into the literal string "not set", defeating windowBody()'s both-null
 *     branch.
 * The deck is built by listGlanceCards(); the homepage glanceCards() deck is
 * out of scope here.
 */
final class GlanceWindowStateTest extends PublicSurfaceTestCase
{
    private const LOGIN = 'user.baseline@brewingcompetitions.com';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var array<string, mixed> */
    private array $origContest = [];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    /** @var array<string, mixed> */
    private array $origBrewer = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1,
                'user_name' => self::LOGIN,
                'password' => self::HASH,
                'userLevel' => '2',
                'userCreated' => '2024-01-01 00:00:01',
                'userAdminObfuscate' => 0,
            ]);
        }

        // Force a non-volunteer so the judging card — with its own "not set"
        // fmt — never joins the deck under test.
        $brewer = DB::table('brewer')->where('uid', 1)->first();
        if ($brewer !== null) {
            $this->origBrewer = (array) $brewer;
            DB::table('brewer')->where('uid', 1)->update([
                'brewerJudge' => 'N',
                'brewerSteward' => 'N',
                'brewerEmail' => self::LOGIN,
            ]);
        }

        $contest = DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = $contest === null ? [] : (array) $contest;

        $prefs = DB::table('preferences')->where('id', 1)->first();
        $this->origPrefs = $prefs === null ? [] : (array) $prefs;

        // Entry + drop-off open by default; each test blanks the dates it
        // cares about.
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => Date::now()->subDays(2)->getTimestamp(),
            'contestEntryDeadline' => Date::now()->addDays(7)->getTimestamp(),
            'contestDropoffOpen' => Date::now()->subDays(1)->getTimestamp(),
            'contestDropoffDeadline' => Date::now()->addDays(14)->getTimestamp(),
        ]);

        // Shipping off by default so it cannot contribute a second window
        // state to the deck; the drop-off tests turn that switch on themselves.
        DB::table('preferences')->where('id', 1)->update([
            'prefsDropOff' => 0,
            'prefsShipping' => 0,
        ]);

        $this->loginWithEmail(self::LOGIN);
    }

    protected function tearDown(): void
    {
        if ($this->origContest !== []) {
            DB::table('contest_info')->where('id', 1)->update($this->origContest);
        }

        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        if ($this->origBrewer !== []) {
            DB::table('brewer')->where('uid', 1)->update($this->origBrewer);
        }

        parent::tearDown();
    }

    public function test_dropoff_with_no_dates_renders_closed_not_not_set(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestDropoffOpen' => null,
            'contestDropoffDeadline' => null,
        ]);
        DB::table('preferences')->where('id', 1)->update(['prefsDropOff' => 1]);

        $html = (string) $this->get('/list')->assertOk()->getContent();

        $card = $this->cardHtml($html, __('site.drop_off'));
        $this->assertStringContainsString('glance-status-pill--secondary', $card);
        $this->assertStringContainsString(__('site.state_closed'), $card);
        // The both-null branch wins: no blank dates masquerading as open.
        $this->assertStringContainsString(__('site.glance_dates_tba'), $card);

        $this->assertStringNotContainsString(__('site.not_set'), $html);
    }

    public function test_entry_window_with_no_dates_renders_closed_not_not_yet_open(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestEntryOpen' => null,
            'contestEntryDeadline' => null,
        ]);

        $html = (string) $this->get('/list')->assertOk()->getContent();

        $card = $this->cardHtml($html, __('site.entries_registration'));
        $this->assertStringContainsString('glance-status-pill--secondary', $card);
        $this->assertStringContainsString(__('site.state_closed'), $card);

        $this->assertStringNotContainsString(__('site.state_before'), $html);
    }

    public function test_dropoff_with_only_a_deadline_renders_closed(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestDropoffOpen' => null,
            'contestDropoffDeadline' => Date::now()->addDays(14)->getTimestamp(),
        ]);
        DB::table('preferences')->where('id', 1)->update(['prefsDropOff' => 1]);

        $html = (string) $this->get('/list')->assertOk()->getContent();

        $card = $this->cardHtml($html, __('site.drop_off'));
        $this->assertStringContainsString('glance-status-pill--secondary', $card);
        $this->assertStringContainsString(__('site.state_closed'), $card);
        // A single open date must never read as Open with a dangling close line.
        $this->assertStringNotContainsString('glance-status-pill--success', $card);
        $this->assertStringNotContainsString(__('site.not_set'), $card);
    }

    /** Isolate one glance card's markup by its rendered title. */
    private function cardHtml(string $html, string $title): string
    {
        foreach (explode('<div class="col">', $html) as $chunk) {
            if (str_contains($chunk, '>'.$title.'<')) {
                return $chunk;
            }
        }

        throw new \RuntimeException("Glance card '{$title}' not found in /list response.");
    }
}
