<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Admin Actions menu on /backoffice/entries (entries.admin.php:830-840):
 * purge flows (data_cleanup.inc.php go=unconfirmed / go=unpaid, level-0
 * only) and judging-number regeneration landing back on the entries list
 * (process.inc.php generate_judging_numbers with go=entries).
 */
final class EntriesToolbarPurgeRegenTest extends AdminScreensTestCase
{
    protected function tearDown(): void
    {
        DB::table('brewing')->where('brewName', 'like', 'ETP-%')->delete();
        DB::table('users')->where('id', 9412)->delete();
        DB::table('brewer')->where('uid', 9412)->delete();
        parent::tearDown();
    }

    public function test_purge_unpaid_deletes_only_unpaid_rows(): void
    {
        $paid = self::seedEntry('ETP-paid', ['brewPaid' => 1, 'brewConfirmed' => 1]);
        $unpaid = self::seedEntry('ETP-unpaid', ['brewPaid' => 0, 'brewConfirmed' => 1]);
        $nullPaid = self::seedEntry('ETP-null', ['brewPaid' => null, 'brewConfirmed' => 1]);

        $this->post('/backoffice/entries/purge', ['go' => 'unpaid'])
            ->assertRedirect('/backoffice/entries');

        self::assertNotNull(DB::table('brewing')->where('id', $paid)->value('id'));
        self::assertNull(DB::table('brewing')->where('id', $unpaid)->value('id'));
        self::assertNull(DB::table('brewing')->where('id', $nullPaid)->value('id'));
    }

    public function test_purge_unconfirmed_removes_unconfirmed_and_missing_special_info(): void
    {
        // brewConfirmed != 1 rows are removed...
        $unconfirmed = self::seedEntry('ETP-unconfirmed', ['brewConfirmed' => 0, 'brewPaid' => 1]);
        // ...as are entries whose style requires special-ingredient info but
        // have none (even when confirmed) — purge_entries("special").
        $style = DB::table('styles')
            ->where('brewStyleReqSpec', '1')
            ->where('brewStyleVersion', 'BJCP2021')
            ->first(['brewStyleGroup', 'brewStyleNum']);
        self::assertNotNull($style);

        $missing = self::seedEntry('ETP-special-missing', [
            'brewConfirmed' => 1,
            'brewPaid' => 1,
            'brewCategory' => (string) $style->brewStyleGroup,
            'brewCategorySort' => (string) $style->brewStyleGroup,
            'brewSubCategory' => (string) $style->brewStyleNum,
            'brewInfo' => null,
        ]);
        $withInfo = self::seedEntry('ETP-special-filled', [
            'brewConfirmed' => 1,
            'brewPaid' => 1,
            'brewCategory' => (string) $style->brewStyleGroup,
            'brewCategorySort' => (string) $style->brewStyleGroup,
            'brewSubCategory' => (string) $style->brewStyleNum,
            'brewInfo' => 'soured with brett',
        ]);
        $confirmed = self::seedEntry('ETP-keep', ['brewConfirmed' => 1, 'brewPaid' => 1]);

        $this->post('/backoffice/entries/purge', ['go' => 'unconfirmed'])
            ->assertRedirect('/backoffice/entries');

        self::assertNull(DB::table('brewing')->where('id', $unconfirmed)->value('id'));
        self::assertNull(DB::table('brewing')->where('id', $missing)->value('id'));
        self::assertNotNull(DB::table('brewing')->where('id', $withInfo)->value('id'));
        self::assertNotNull(DB::table('brewing')->where('id', $confirmed)->value('id'));
    }

    public function test_purge_rejects_unknown_go_and_level_one_users(): void
    {
        $keep = self::seedEntry('ETP-unknown', ['brewPaid' => 0, 'brewConfirmed' => 1]);

        // Unknown go: nothing deleted.
        $this->post('/backoffice/entries/purge', ['go' => 'nope'])
            ->assertRedirect('/backoffice/entries');
        self::assertNotNull(DB::table('brewing')->where('id', $keep)->value('id'));

        // Level 1 is not the data-cleanup level (data_cleanup.inc.php
        // requires userLevel == 0): entries survive and the request is
        // bounced like every other admin-only gate.
        $this->post('/logout');
        DB::table('users')->insert([
            'id' => 9412,
            'user_name' => 'etp.level1@brewingcompetitions.com',
            'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        $this->post('/login', [
            'loginUsername' => 'etp.level1@brewingcompetitions.com',
            'loginPassword' => 'bcoem',
        ]);
        $this->post('/backoffice/entries/purge', ['go' => 'unpaid'])
            ->assertRedirect('/?msg=99');
        self::assertNotNull(DB::table('brewing')->where('id', $keep)->value('id'));
    }

    public function test_regenerate_from_entries_lands_back_on_entries_list(): void
    {
        $id = self::seedEntry('ETP-regen', ['brewCategory' => '21', 'brewCategorySort' => '21', 'brewSubCategory' => 'A']);

        $this->post('/admin/judging/regenerate-numbers', ['method' => 'default', 'return_to' => 'entries'])
            ->assertRedirect('/backoffice/entries');

        self::assertMatchesRegularExpression(
            '/^[1-9]{6}$/',
            (string) DB::table('brewing')->where('id', $id)->value('brewJudgingNumber'),
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function seedEntry(string $name, array $extra = []): int
    {
        return (int) DB::table('brewing')->insertGetId(array_merge([
            'brewName' => $name,
            'brewCategory' => '21',
            'brewCategorySort' => '21',
            'brewSubCategory' => 'A',
            'brewPaid' => 0,
            'brewConfirmed' => 0,
            'brewInfo' => null,
        ], $extra));
    }
}
