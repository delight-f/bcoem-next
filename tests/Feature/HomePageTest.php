<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

use Illuminate\Testing\TestResponse;

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
        $response->assertSee('At a Glance');
        $response->assertSee('Competition Rules');
    }

    public function test_window_sections_render_dates_or_not_set(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Opens:');
        $response->assertSee('Closes:');
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
                $response->assertSee('choose from the following contacts', false);
            }
        }
    }

    public function test_list_redirects_anonymous_like_legacy(): void
    {
        $response = $this->get('/list');

        self::assertSame(302, $response->status());
    }

    public function test_past_winners_unknown_archive_is_empty_but_ok(): void
    {
        $response = $this->get('/past-winners/doesnotexist99');

        $response->assertOk();
        self::assertStringContainsStringIgnoringCase('Past Winners', (string) (string) $response->getContent());
    }

    private function contestName(): string
    {
        return (string) (DB::table('contest_info')->where('id', 1)->value('contestName') ?? '');
    }

    /** Strict legacy gate: every judging session in the past AND delay passed. */
    private function revealPassed(): bool
    {
        $future = (int) DB::table('judging_locations')->where('judgingDate', '>=', time())->count();
        $delay = (int) (DB::table('preferences')->where('id', 1)->value('prefsWinnerDelay') ?: 0);

        return $future === 0 && time() > $delay;
    }

}
