<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Installation\InstallationService;
use Illuminate\Support\Facades\DB;

/**
 * Landing-page feature coverage against the CI baseline schema (anon-base
 * fixture data). Pins the Slice A observable contract: identity, window
 * sections with correct states, contacts gating, and the results block's
 * reveal gating.
 */
final class HomePageTest extends PublicSurfaceTestCase
{
    public function test_landing_renders_contest_identity(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee($this->contestName(), false);
        // Rules section renders only while future judging sessions remain
        // (index.pub.php); the fixture may have none.
        if ($this->futureJudgingSessions() > 0) {
            $response->assertSee('Competition Rules');
        }
    }

    public function test_footer_reports_the_shipped_version_rather_than_a_frozen_string(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $html = (string) $response->getContent();

        // The footer used to carry the literal "3.1.0" frozen at fork time, so a
        // 4.x release still announced 3.1.0 and the string never moved when the
        // site was upgraded.
        self::assertStringContainsString('BCOE&amp;M '.InstallationService::versionIn(base_path()), $html);
        self::assertStringNotContainsString('BCOE&amp;M 3.1.0', $html);
    }

    /**
     * Issue #57: the organising club renders on its own line so a long host
     * name cannot orphan a single word of the interest sentence.
     */
    public function test_landing_salutation_puts_host_on_its_own_line(): void
    {
        $snap = $this->snapshotFixture();

        try {
            $host = 'Homebrewers Club of McDowell and Surrounding Counties';
            DB::table('contest_info')->where('id', 1)->update([
                'contestHost' => $host,
                'contestHostWebsite' => null,
                'contestHostLocation' => null,
            ]);

            $response = $this->get('/');
            $response->assertOk();
            $body = (string) $response->getContent();

            // The interest <p> closes before the host <p> begins.
            self::assertMatchesRegularExpression(
                '/landing-page-salutation[^>]*>.*?<\/p>\s*<p[^>]*landing-page-host/s',
                $body,
            );
            self::assertStringContainsString($host, $body);
        } finally {
            $this->restoreFixture($snap);
        }
    }

    public function test_window_sections_render_dates_or_not_set(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        if ($this->futureJudgingSessions() > 0) {
            $response->assertSee('Opens:');
            $response->assertSee('Closes:');
        } else {
            self::assertStringNotContainsString('Opens:', (string) $response->getContent());
        }
    }

    public function test_undated_pending_windows_never_render_a_dangling_date(): void
    {
        $snap = $this->snapshotFixture();

        try {
            DB::table('contest_info')->where('id', 1)->update([
                'contestRegistrationOpen' => null,
                'contestRegistrationDeadline' => null,
                'contestEntryOpen' => null,
                'contestEntryDeadline' => null,
            ]);

            $response = $this->get('/');
            $response->assertOk();
            $html = (string) $response->getContent();

            // The pre-modernisation bug: an undated window still emitted
            // "…will open ." with an empty date.
            self::assertStringNotContainsString('will open .', $html);
            self::assertStringContainsString(__('site.glance_dates_tba'), $html);
        } finally {
            $this->restoreFixture($snap);
        }
    }

    public function test_future_windows_are_not_labelled_closed(): void
    {
        $snap = $this->snapshotFixture();

        try {
            $now = time();
            DB::table('contest_info')->where('id', 1)->update([
                'contestRegistrationOpen' => (string) ($now + 864000),
                'contestRegistrationDeadline' => (string) ($now + 1728000),
                'contestEntryOpen' => (string) ($now + 864000),
                'contestEntryDeadline' => (string) ($now + 1728000),
            ]);

            $response = $this->get('/');
            $response->assertOk();
            $html = (string) $response->getContent();

            // A window that has not opened yet reads as neutral, not failed.
            self::assertStringContainsString(__('site.state_before'), $html);
        } finally {
            $this->restoreFixture($snap);
        }
    }

    public function test_results_hidden_before_reveal(): void
    {
        // anon-base dump: judging dates in the future relative to its
        // creation; the strict reveal gate keeps results off the page.
        if ($this->revealPassed()) {
            $this->markTestSkipped('fixture window has already revealed');
        }

        $response = $this->get('/');
        $response->assertOk();
        self::assertStringNotContainsStringIgnoringCase('Best of Show Winners', (string) $response->getContent());
    }

    public function test_contacts_respect_prefs_contact_gate(): void
    {
        $mode = (string) DB::table('preferences')->where('id', 1)->value('prefsContact');

        $response = $this->get('/');
        $response->assertOk();

        if ($mode === 'X') {
            $response->assertSee('disabled by the site administrators', false);
        } else {
            $count = DB::table('contacts')->count();
            if ($count === 0) {
                $response->assertSee('No contacts have been listed', false);
            } elseif ($mode === 'Y') {
                // Issue #54: form mode exposes the contact form here too.
                $response->assertSee('Use the form below to contact a competition official', false);
            } else {
                $response->assertSee('Use the links below to contact individuals involved', false);
            }
        }
    }

    /**
     * The at-a-glance deck must line up: every status pill sits inside the
     * shared header wrapper, so the title + pill occupy one row and each card's
     * body starts at the same height. Wrapping the pill to its own line (the
     * old flex-wrap) staggered the pills and the text across the deck.
     */
    public function test_at_a_glance_cards_share_one_header_row(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $heads = substr_count($html, 'class="glance-card-head ');
        self::assertGreaterThan(0, $heads, 'the deck must render its card headers');
        self::assertSame($heads, substr_count($html, 'class="glance-status-pill '));
    }

    public function test_list_redirects_anonymous_like_legacy(): void
    {
        $response = $this->get('/list');

        self::assertSame(302, $response->status());
    }

    /**
     * Registration window cards only — Judging and Awards (which need judging
     * sessions / a started schedule) stay absent.
     *
     * The shared fixture is mutated by siblings mid-run, so this snapshot &
     * restores the rows it touches rather than trusting the ambient state.
     */
    public function test_glance_cards_render_registration_windows(): void
    {
        $snap = $this->snapshotFixture();

        try {
            $now = time();
            $past = (string) ($now - 864000);
            $future = (string) ($now + 864000);

            DB::table('preferences')->where('id', 1)->update([
                'prefsProEdition' => '1',
                'prefsShipping' => '1',
            ]);
            DB::table('contest_info')->where('id', 1)->update([
                'contestRegistrationOpen' => $past,
                'contestRegistrationDeadline' => $future,
                'contestEntryOpen' => $past,
                'contestEntryDeadline' => $future,
                'contestJudgeOpen' => $past,
                'contestJudgeDeadline' => $future,
                'contestDropoffOpen' => $past,
                'contestDropoffDeadline' => $future,
                'contestShippingOpen' => $past,
                'contestShippingDeadline' => $future,
                'contestShippingAddress' => '123 Main St, Anytown',
                'contestAwardsLocName' => null,
                'contestAwardsLocation' => null,
                'contestAwardsLocDate' => null,
                'contestAwardsLocTime' => null,
            ]);
            // No judging sessions: Judging and Awards cards stay hidden.
            DB::table('judging_locations')->delete();

            $response = $this->get('/');
            $response->assertOk();
            self::assertSame([
                'Entry Registration',
                'Account Registration',
                'Volunteer Registration',
                'Entry Drop-Off',
                'Entry Shipping',
            ], $this->glanceHeaders((string) $response->getContent()));
        } finally {
            $this->restoreFixture($snap);
        }
    }

    /**
     * Full deck with judging sessions, awards, drop-off and shipping all
     * configured renders every card in order; unsetting the optional data
     * hides exactly those cards.
     */
    public function test_glance_cards_seeded_and_trimmed(): void
    {
        $snap = $this->snapshotFixture();

        try {
            $now = time();
            $past = (string) ($now - 864000);
            $future = (string) ($now + 864000);

            DB::table('preferences')->where('id', 1)->update([
                'prefsProEdition' => '0',
                'prefsShipping' => '1',
                // The results-block reveal gate ($now > prefsWinnerDelay) must
                // stay closed so the all-closed landing shows cards, not results.
                'prefsWinnerDelay' => (string) ($now + 31536000),
                'prefsDisplayWinners' => 'Y',
            ]);
            DB::table('contest_info')->where('id', 1)->update([
                'contestRegistrationOpen' => $past,
                'contestRegistrationDeadline' => $future,
                'contestEntryOpen' => $past,
                'contestEntryDeadline' => $future,
                'contestJudgeOpen' => $past,
                'contestJudgeDeadline' => $future,
                'contestDropoffOpen' => $past,
                'contestDropoffDeadline' => $future,
                'contestShippingOpen' => $past,
                'contestShippingDeadline' => $future,
                'contestShippingAddress' => '123 Main St, Anytown',
                'contestAwardsLocName' => 'Awards Hall',
                'contestAwardsLocation' => '200 E Colfax Ave, Denver',
                'contestAwardsLocDate' => (string) ($now + 1728000),
                'contestAwardsLocTime' => (string) ($now + 1728000),
            ]);
            // One judging session (type 1 = session), already concluded.
            DB::table('judging_locations')->insert([
                'judgingLocType' => 1,
                'judgingDate' => $past,
                'judgingDateEnd' => (string) ($now - 86400),
            ]);

            $response = $this->get('/');
            $response->assertOk();
            self::assertSame([
                'Entry Registration',
                'Account Registration',
                'Volunteer Registration',
                'Entry Drop-Off',
                'Entry Shipping',
                'Judging',
                'Awards',
            ], $this->glanceHeaders((string) $response->getContent()));

            // Drop the optional gating data: the conditional cards disappear
            // while the core window cards survive.
            DB::table('contest_info')->where('id', 1)->update([
                'contestDropoffOpen' => null,
                'contestDropoffDeadline' => null,
                'contestShippingOpen' => null,
                'contestShippingDeadline' => null,
                'contestShippingAddress' => null,
                'contestAwardsLocName' => null,
                'contestAwardsLocation' => null,
                'contestAwardsLocDate' => null,
                'contestAwardsLocTime' => null,
            ]);
            DB::table('judging_locations')->delete();

            $response = $this->get('/');
            $response->assertOk();
            self::assertSame([
                'Entry Registration',
                'Account Registration',
                'Volunteer Registration',
            ], $this->glanceHeaders((string) $response->getContent()));
        } finally {
            $this->restoreFixture($snap);
        }
    }

    /** Card titles in the #at-a-glance section, in render order.
     * @return list<string>
     */
    private function glanceHeaders(string $html): array
    {
        $start = strpos($html, '<section id="at-a-glance"');
        $end = strpos($html, '</section>', (int) $start);

        self::assertNotFalse($start, 'at-a-glance section not found');
        $section = substr($html, (int) $start, (int) $end - (int) $start);

        preg_match_all('/<h5 class="card-title[^"]*">([^<]+)<\/h5>/', $section, $m);

        return $m[1];
    }

    /** @return array{contest: array<string, mixed>, prefs: array<string, mixed>, judging: list<array<string, mixed>>} */
    private function snapshotFixture(): array
    {
        $contestRow = DB::table('contest_info')->where('id', 1)->first();
        $prefsRow = DB::table('preferences')->where('id', 1)->first();

        /** @var array<string, mixed> $contest */
        $contest = $contestRow === null ? [] : (array) $contestRow;
        /** @var array<string, mixed> $prefs */
        $prefs = $prefsRow === null ? [] : (array) $prefsRow;
        /** @var list<array<string, mixed>> $judging */
        $judging = DB::table('judging_locations')->get()
            ->map(static fn (object $r): array => (array) $r)
            ->all();

        return ['contest' => $contest, 'prefs' => $prefs, 'judging' => $judging];
    }

    /** @param array{contest: array<string, mixed>, prefs: array<string, mixed>, judging: list<array<string, mixed>>} $snap */
    private function restoreFixture(array $snap): void
    {
        DB::table('contest_info')->where('id', 1)->update($snap['contest']);
        DB::table('preferences')->where('id', 1)->update($snap['prefs']);
        DB::table('judging_locations')->delete();
        foreach ($snap['judging'] as $row) {
            DB::table('judging_locations')->insert($row);
        }
    }

    private function contestName(): string
    {
        return (string) (DB::table('contest_info')->where('id', 1)->value('contestName') ?? '');
    }

    private function futureJudgingSessions(): int
    {
        return (int) DB::table('judging_locations')->where('judgingDate', '>=', time())->count();
    }

    /** Strict legacy gate: every judging session in the past AND delay passed. */
    private function revealPassed(): bool
    {
        $future = (int) DB::table('judging_locations')->where('judgingDate', '>=', time())->count();
        $delay = (int) (DB::table('preferences')->where('id', 1)->value('prefsWinnerDelay') ?: 0);

        return $future === 0 && time() > $delay;
    }
}
