<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Sponsors public surfaces (legacy index.pub.php sponsors section +
 * sections/sponsors.sec.php standalone page; PARITY-012). Gate:
 * prefsSponsors=Y and at least one sponsor row; enabled sponsors only;
 * logo fallback to no_image.png when prefsSponsorLogos=Y.
 */
final class SponsorsPageTest extends PublicSurfaceTestCase
{
    private const SPONSOR_NAME = 'P2 Sponsors Test Brewery';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('preferences')->where('id', 1)->update([
            'prefsSponsors' => 'N',
            'prefsSponsorLogos' => 'N',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('sponsors')->where('sponsorName', self::SPONSOR_NAME)->delete();
        DB::table('preferences')->where('id', 1)->update([
            'prefsSponsors' => 'N',
            'prefsSponsorLogos' => 'N',
        ]);
        parent::tearDown();
    }

    public function test_sponsors_page_redirects_when_disabled(): void
    {
        $this->get('/sponsors')->assertRedirect('/');
    }

    public function test_sponsors_page_renders_enabled_sponsors_only(): void
    {
        $this->seedSponsors();

        $this->get('/sponsors')
            ->assertOk()
            ->assertSee(self::SPONSOR_NAME)
            ->assertSee('https://example.org')
            ->assertDontSee('P2 Disabled Sponsor');
    }

    public function test_landing_shows_sponsors_section_when_visible(): void
    {
        $this->get('/')->assertDontSee('id="sponsors"', false);

        $this->seedSponsors();

        $this->get('/')
            ->assertOk()
            ->assertSee('id="sponsors"', false)
            ->assertSee(self::SPONSOR_NAME);
    }

    public function test_logo_fallback_used_when_logos_enabled(): void
    {
        $this->seedSponsors();
        DB::table('preferences')->where('id', 1)->update(['prefsSponsorLogos' => 'Y']);

        $this->get('/sponsors')
            ->assertOk()
            ->assertSee('no_image.png');
    }

    private function seedSponsors(): void
    {
        DB::table('sponsors')->insert([
            ['sponsorName' => self::SPONSOR_NAME, 'sponsorURL' => 'https://example.org', 'sponsorLocation' => 'Testville', 'sponsorText' => 'Fine ales.', 'sponsorEnable' => '1', 'sponsorImage' => ''],
            ['sponsorName' => 'P2 Disabled Sponsor', 'sponsorURL' => '', 'sponsorLocation' => '', 'sponsorText' => '', 'sponsorEnable' => '0', 'sponsorImage' => ''],
        ]);
        DB::table('preferences')->where('id', 1)->update(['prefsSponsors' => 'Y']);
    }
}
