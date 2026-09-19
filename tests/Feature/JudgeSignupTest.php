<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Public judge signup (spec §6 P4.3): preference capture identical to
 * legacy pub/judge.pub.php + pub/judge_info.pub.php, and closed-state
 * gating via the Slice A window conventions (WindowStates/Windows).
 */
final class JudgeSignupTest extends PublicSurfaceTestCase
{
    private const EMAIL = 'judge.signup@example.com';

    private const UID_OFFSET = 9400;

    /** @var list<int> */
    private array $createdUsers = [];

    /** @var array<string, mixed> */
    private array $origContest = [];

    protected function setUp(): void
    {
        parent::setUp();

        $row = DB::table('contest_info')->where('id', 1)->first();
        $this->origContest = collect((array) $row)->except(['id'])->all();

        DB::table('contest_info')->where('id', 1)->update([
            'contestRegistrationOpen' => '946684800',
            'contestRegistrationDeadline' => '4102444800',
            'contestJudgeOpen' => '946684800',
            'contestJudgeDeadline' => '4102444800',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $id) {
            DB::table('staff')->where('uid', $id)->delete();
            DB::table('judging_assignments')->where('bid', $id)->delete();
            DB::table('brewer')->where('uid', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        }

        DB::table('contest_info')->where('id', 1)->update($this->origContest);

        parent::tearDown();
    }

    private function seedUser(string $email = self::EMAIL): int
    {
        static $seq = 0;
        $uid = self::UID_OFFSET + ++$seq;

        DB::table('users')->insertGetId([
            'id' => $uid,
            'user_name' => $email,
            'userLevel' => '2',
            'password' => app('hash')->make('correct-horse-battery'),
            'userQuestion' => 'q?',
            'userQuestionAnswer' => app('hash')->make('a'),
            'userCreated' => now()->format('Y-m-d H:i:s'),
            'userAdminObfuscate' => 1,
        ]);

        DB::table('brewer')->insert([
            'uid' => $uid,
            'brewerFirstName' => 'Judge',
            'brewerLastName' => 'Signup',
            'brewerEmail' => $email,
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
            'brewerStaff' => 'N',
        ]);

        DB::table('staff')->insert(['uid' => $uid]);

        $this->createdUsers[] = $uid;

        $this->loginWithEmail($email);

        return $uid;
    }

    private function closeJudgeWindow(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestJudgeOpen' => '946684800',
            'contestJudgeDeadline' => '946684900',
        ]);
    }

    public function test_preference_capture_round_trip(): void
    {
        $uid = $this->seedUser();

        $response = $this->get('/judge');
        $response->assertOk();
        $response->assertSee('BJCP Judge ID');
        $response->assertSee('Non-BJCP');

        $this->post('/judge', [
            'brewerJudgeID' => 'd5678',
            'brewerJudgeMead' => 'Y',
            'brewerJudgeRank' => ['Certified', 'Professional Brewer'],
            'brewerJudgeLikes' => ['7', '11'],
            'brewerJudgeDislikes' => ['23'],
        ])->assertRedirect('/judge');

        $brewer = (array) DB::table('brewer')->where('uid', $uid)->first();
        self::assertSame('Y', $brewer['brewerJudge']);
        self::assertSame('D5678', $brewer['brewerJudgeID']); // uppercased like the profile form
        self::assertSame('Y', $brewer['brewerJudgeMead']);
        // Rank CSV follows the legacy constant order, not submission order.
        self::assertSame('Certified,Professional Brewer', $brewer['brewerJudgeRank']);
        self::assertSame('7,11', $brewer['brewerJudgeLikes']);
        self::assertSame('23', $brewer['brewerJudgeDislikes']);

        self::assertSame(1, (int) DB::table('staff')->where('uid', $uid)->value('staff_judge'));
    }

    public function test_form_prefills_existing_preferences(): void
    {
        $this->seedUser();
        $styleId = (int) DB::table('styles')->where('brewStyleActive', 'Y')->value('id');
        DB::table('brewer')->where('uid', $this->createdUsers[0])->update([
            'brewerJudgeLikes' => (string) $styleId,
            'brewerJudgeMead' => 'Y',
            'brewerJudgeRank' => 'Certified',
        ]);

        $response = $this->get('/judge');
        $response->assertOk();

        $html = $response->getContent() ?: '';
        self::assertStringContainsString('name="brewerJudgeLikes[]" value="'.$styleId.'" checked', $html);
        self::assertStringContainsString('name="brewerJudgeMead" value="Y" checked', $html);
        self::assertStringContainsString('name="brewerJudgeRank[]" value="Certified" checked', $html);
    }

    public function test_closed_window_shows_closed_page_and_refuses_write(): void
    {
        $uid = $this->seedUser();
        $this->closeJudgeWindow();

        $response = $this->get('/judge');
        $response->assertOk();
        $response->assertSee('closed');

        $this->post('/judge', [
            'brewerJudgeID' => 'D9999',
            'brewerJudgeMead' => 'Y',
        ])->assertRedirect('/judge');

        $brewer = (array) DB::table('brewer')->where('uid', $uid)->first();
        self::assertSame('N', $brewer['brewerJudge']);
        self::assertNull($brewer['brewerJudgeID']);
    }
}
