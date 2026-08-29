<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Distinct change-email page (legacy ?section=user&action=username,
 * pub/user.pub.php:35-80 + process_users.inc.php go=username).
 * PARITY-007 restoration: self-service + admin-change-another-user,
 * taken-email rejection, users.user_name + brewer.brewerEmail sync.
 */
final class ChangeEmailPageTest extends PublicSurfaceTestCase
{
    private const OLD_EMAIL = 'p2-change-old@brewingcompetitions.com';

    private const NEW_EMAIL = 'p2-change-new@brewingcompetitions.com';

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (int) DB::table('users')->insertGetId([
            'user_name' => self::OLD_EMAIL,
            'password' => password_hash('x', PASSWORD_BCRYPT),
            'userLevel' => 2,
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        DB::table('brewer')->insert([
            'uid' => $this->userId,
            'brewerFirstName' => 'Changey',
            'brewerLastName' => 'McEmailson',
            'brewerEmail' => self::OLD_EMAIL,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('brewer')->where('uid', $this->userId)->delete();
        DB::table('users')->where('id', $this->userId)->delete();
        parent::tearDown();
    }

    public function test_page_renders_for_self_service(): void
    {
        $this->actingAs(User::query()->findOrFail($this->userId));

        $this->get('/user/username')
            ->assertOk()
            ->assertSee('Change Your Email Address (User Name)', false)
            ->assertSee(self::OLD_EMAIL)
            ->assertSee('Are You Sure?');
    }

    public function test_legacy_url_redirects(): void
    {
        $this->get('/index.php?section=user&go=account&action=username')
            ->assertRedirect('/user/username');
    }

    public function test_post_updates_both_tables(): void
    {
        $this->actingAs(User::query()->findOrFail($this->userId));

        $this->post('/user/username', [
            'user_name' => self::NEW_EMAIL,
            'sure' => 'Y',
            'old_email' => self::OLD_EMAIL,
        ])->assertRedirect('/list?msg=3');

        self::assertSame(
            self::NEW_EMAIL,
            DB::table('users')->where('id', $this->userId)->value('user_name'),
        );
        self::assertSame(
            self::NEW_EMAIL,
            DB::table('brewer')->where('uid', $this->userId)->value('brewerEmail'),
        );
    }

    public function test_taken_email_rejected(): void
    {
        $this->actingAs(User::query()->findOrFail($this->userId));

        $response = $this->post('/user/username', [
            // The baseline admin fixture already owns this address.
            'user_name' => 'user.baseline@brewingcompetitions.com',
            'sure' => 'Y',
            'old_email' => self::OLD_EMAIL,
        ]);

        // Either the validation-style back() or the msg=1 redirect is a
        // faithful legacy outcome; the invariant is: nothing changed.
        self::assertNotSame(
            'user.baseline@brewingcompetitions.com',
            DB::table('users')->where('id', $this->userId)->value('user_name'),
        );
    }
}
