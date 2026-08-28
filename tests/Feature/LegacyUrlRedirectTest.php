<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Legacy URL redirect contract (HANDOVER §4.3): every canonical
 * role|legacy|port pair from tools/parity/urls.txt redirects off the bcoem
 * query-string shape onto the clean port URL; unknown sections fall back
 * to home; the login form's process.inc.php POST keeps method+body (307)
 * because the port login accepts the legacy field names verbatim.
 */
final class LegacyUrlRedirectTest extends PublicSurfaceTestCase
{
    /** Legacy $2a$ hash of md5('bcoem') — the baseline fixture's stored hash. */
    private const LEGACY_HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    protected function setUp(): void
    {
        parent::setUp();

        // Idempotent admin fixture (see AuthLoginTest): the shared test DB
        // may have had users truncated/rehashed by other suites.
        $exists = DB::table('users')->where('id', 1)->exists();
        if (! $exists) {
            DB::table('users')->insert([
                'id' => 1,
                'user_name' => 'user.baseline@brewingcompetitions.com',
                'password' => self::LEGACY_HASH,
                'userLevel' => '0',
                'userQuestion' => 'What is your favorite all-time beer to drink?',
                'userQuestionAnswer' => '$2a$08$gImDLllgw/nned4kVWDAD.394FXpXeoEip85oqEQ.fIy8s4U3lwx.',
                'userCreated' => '2024-01-01 00:00:01',
                'userFailedLogins' => 0,
                'userAdminObfuscate' => 0,
            ]);
        } else {
            DB::table('users')->where('id', 1)->update([
                'password' => self::LEGACY_HASH,
                'userLevel' => '0',
            ]);
        }
    }

    /**
     * Parse the canonical inventory: role|legacy_path[|port_path].
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function urlsProvider(): array
    {
        $lines = file(dirname(__DIR__, 2).'/tools/parity/urls.txt', FILE_IGNORE_NEW_LINES) ?: [];

        $cases = [];
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [, $legacy, $port] = array_pad(explode('|', $line), 3, null);
            if ($port === null || $port === '') {
                continue; // "/" itself — nothing to redirect.
            }
            if (str_contains($legacy, 'output.inc.php')) {
                // output.inc.php entries are PDF link targets (labels,
                // pullsheets, results) that exist in urls.txt purely as
                // linkmap map entries — they are not redirect pages.
                continue;
            }
            $cases[] = [$legacy, $port];
        }

        return $cases;
    }

    public function test_urls_txt_inventory_is_nonempty(): void
    {
        $this->assertGreaterThanOrEqual(49, count(self::urlsProvider()));
    }

    #[DataProvider('urlsProvider')]
    public function test_legacy_url_redirects_to_paired_port_url(string $legacy, string $port): void
    {
        $response = $this->get('/'.$legacy);

        $response->assertStatus(301);
        $this->assertSame($port, $response->headers->get('Location'));
    }

    public function test_register_view_param_is_preserved(): void
    {
        $response = $this->get('/index.php?section=register&go=judge&view=quick');

        $response->assertRedirect('/register/judge?view=quick');
    }

    public function test_participants_filter_is_preserved_beyond_inventory(): void
    {
        $response = $this->get('/index.php?section=admin&go=participants&filter=staff');

        $response->assertRedirect('/backoffice/participants?filter=staff');
    }

    public function test_list_msg_is_preserved(): void
    {
        $this->get('/index.php?section=list&msg=7')->assertRedirect('/list?msg=7');
    }

    public function test_redundant_params_are_not_forwarded(): void
    {
        $response = $this->get('/index.php?section=admin&go=sponsors&action=browse');

        $this->assertSame('/admin/sponsors', $response->headers->get('Location'));
    }

    public function test_brew_edit_id_maps_to_edit_route(): void
    {
        $response = $this->get('/index.php?section=brew&action=edit&id=42');

        $response->assertRedirect('/brew/42/edit');
    }

    public function test_admin_entry_edit_id_maps_to_backoffice(): void
    {
        $response = $this->get('/index.php?section=admin&go=entries&action=edit&id=42');

        $response->assertRedirect('/backoffice/entries/42/edit');
    }

    public function test_unknown_section_falls_back_to_home_render(): void
    {
        $this->get('/index.php?section=bogus')->assertOk();
    }

    public function test_port_msg_links_still_render_home(): void
    {
        $this->get('/?msg=99')->assertOk();
    }

    public function test_legacy_only_section_bounces_temporarily_home(): void
    {
        $response = $this->get('/index.php?section=sponsors');

        $response->assertStatus(302);
        $this->assertSame('/', $response->headers->get('Location'));
    }

    public function test_volunteers_and_contact_sections_redirect_to_standalone_pages(): void
    {
        $this->get('/index.php?section=volunteers')
            ->assertStatus(302)
            ->assertRedirect('/volunteers');

        $this->get('/index.php?section=contact')
            ->assertStatus(302)
            ->assertRedirect('/contact');
    }

    public function test_login_post_via_process_inc_is_307_to_login(): void
    {
        $credentials = [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ];

        $response = $this->post('/includes/process.inc.php?section=login&action=login', $credentials);

        $response->assertStatus(307);
        $this->assertSame('/login', $response->headers->get('Location'));
    }

    public function test_port_login_authenticates_the_same_legacy_payload(): void
    {
        // The 307 target replays method+body onto /login; follow the whole
        // chain (login → its post-login redirect → any legacy shape back
        // through this controller) to prove drop-in end-to-end.
        $this->followingRedirects()->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ])->assertOk();

        $this->assertAuthenticated();
    }

    public function test_other_process_targets_get_redirect_equivalents(): void
    {
        // Logout arrives as GET via the session modal's location.replace.
        $logout = $this->get('/includes/process.inc.php?section=logout&action=logout');
        $logout->assertStatus(302);
        $this->assertSame('/', $logout->headers->get('Location'));

        // Most process targets dispatch on ?action= alone.
        $purge = $this->post('/includes/process.inc.php?action=purge');
        $purge->assertStatus(302);
        $this->assertSame('/admin/purge', $purge->headers->get('Location'));

        $unknown = $this->post('/includes/process.inc.php?action=never_heard_of_it');
        $unknown->assertStatus(302);
        $this->assertSame('/', $unknown->headers->get('Location'));
    }
}
