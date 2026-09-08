<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            } else {
                $response->assertSee('Use the links below to contact individuals involved', false);
            }
        }
    }

    public function test_list_redirects_anonymous_like_legacy(): void
    {
        $response = $this->get('/list');

        self::assertSame(302, $response->status());
    }

    /**
     * The pro-edition at-a-glance deck (at-a-glance.pub.php): registration
     * window cards only — the amateur Entries deck, plus Judging and Awards
     * (which need judging sessions / a started schedule), stay absent.
     *
     * The shared fixture is mutated by siblings mid-run, so this snapshot &
     * restores the rows it touches rather than trusting the ambient state.
     */
    public function test_glance_cards_match_legacy_pro_deck(): void
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
                'Judge Registration',
                'Steward Registration',
                'Entry Drop-Off',
                'Entry Shipping',
            ], $this->glanceHeaders((string) $response->getContent()));
        } finally {
            $this->restoreFixture($snap);
        }
    }

    /**
     * Amateur edition deck with judging sessions, awards, drop-off and
     * shipping all configured renders the full legacy card set in order;
     * unsetting the optional data hides exactly those cards.
     */
    public function test_glance_cards_seeded_and_hidden_like_legacy(): void
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
                'Entries',
                'Awards',
                'Judging',
                'Entry Registration',
                'Account Registration',
                'Judge Registration',
                'Steward Registration',
                'Entry Drop-Off',
                'Entry Shipping',
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
                'Entries',
                'Entry Registration',
                'Account Registration',
                'Judge Registration',
                'Steward Registration',
            ], $this->glanceHeaders((string) $response->getContent()));
        } finally {
            $this->restoreFixture($snap);
        }
    }

    /** Card titles in the #at-a-glance section, in render order. */
    private function glanceHeaders(string $html): array
    {
        $start = strpos($html, '<section id="at-a-glance"');
        $end = strpos($html, '</section>', (int) $start);

        self::assertNotFalse($start, 'at-a-glance section not found');
        $section = substr($html, (int) $start, (int) $end - (int) $start);

        preg_match_all('/<h5 class="card-title[^"]*">([^<]+)<\/h5>/', $section, $m);

        return $m[1];
    }

    /** @return array{contest: array, prefs: array, judging: array} */
    private function snapshotFixture(): array
    {
        return [
            'contest' => (array) DB::table('contest_info')->where('id', 1)->first(),
            'prefs' => (array) DB::table('preferences')->where('id', 1)->first(),
            'judging' => DB::table('judging_locations')->get()
                ->map(static fn (object $r): array => (array) $r)->all(),
        ];
    }

    /** @param array{contest: array, prefs: array, judging: array} $snap */
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
