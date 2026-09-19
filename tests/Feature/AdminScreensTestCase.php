<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixture plumbing for the P5.4 admin screens suite: one top-level
 * admin user (userLevel=0), login per test, and snapshot/restore of the
 * three shared config rows every screen touches. Fixture names are prefixed
 * "P54" so parallel siblings never collide.
 */
abstract class AdminScreensTestCase extends PublicSurfaceTestCase
{
    protected const ADMIN_EMAIL = 'p54.admin@brewingcompetitions.com';

    protected const ADMIN_ID = 9401;

    /** @var array<string, mixed> */
    protected array $origPrefs = [];

    /** @var array<string, mixed> */
    protected array $origContest = [];

    /** @var array<string, mixed> */
    protected array $origJudging = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot shared config rows for EVERY test in this class: any
        // admin write can rebuild prefsSelectedStyles etc., and tearDown
        // restores whatever was captured. Removes the leak class entirely.
        $this->remember('preferences');
        $this->remember('contest_info');
        $this->remember('judging_preferences');
        DB::table('users')->whereIn('id', [self::ADMIN_ID])->delete();
        DB::table('users')->where('user_name', self::ADMIN_EMAIL)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
            'userLevel' => '0',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        DB::table('brewer')->where('uid', self::ADMIN_ID)->delete();
        DB::table('brewer')->insert([
            'uid' => self::ADMIN_ID,
            'brewerFirstName' => 'P54',
            'brewerLastName' => 'Admin',
            'brewerEmail' => self::ADMIN_EMAIL,
        ]);

        $this->loginWithEmail(self::ADMIN_EMAIL);
    }

    protected function tearDown(): void
    {
        foreach ($this->origPrefs as $key => $value) {
            DB::table('preferences')->where('id', 1)->update([$key => $value]);
        }
        foreach ($this->origContest as $key => $value) {
            DB::table('contest_info')->where('id', 1)->update([$key => $value]);
        }
        foreach ($this->origJudging as $key => $value) {
            DB::table('judging_preferences')->where('id', 1)->update([$key => $value]);
        }

        DB::table('brewer')->where('uid', self::ADMIN_ID)->delete();
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    /** Snapshot a whole config row before a write mutates it. */
    protected function remember(string $table): void
    {
        $row = (array) DB::table($table)->where('id', 1)->first();
        $snapshot = collect($row)->except(['id'])->all();

        if ($table === 'preferences') {
            $this->origPrefs = $this->origPrefs === [] ? $snapshot : $this->origPrefs;
        } elseif ($table === 'contest_info') {
            $this->origContest = $this->origContest === [] ? $snapshot : $this->origContest;
        } elseif ($table === 'judging_preferences') {
            $this->origJudging = $this->origJudging === [] ? $snapshot : $this->origJudging;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function prefs(): array
    {
        return (array) DB::table('preferences')->where('id', 1)->first();
    }

    protected function tenantTzOffset(): ?string
    {
        return TenantContext::load()->prefsStr('prefsTimeZone');
    }

    /** Expected UTC epoch for a wall-time string in the tenant tz. */
    protected function epoch(string $wallTime): int
    {
        return new \DateTimeImmutable($wallTime, new \DateTimeZone(DateFmt::tz($this->tenantTzOffset())))->getTimestamp();
    }
}
