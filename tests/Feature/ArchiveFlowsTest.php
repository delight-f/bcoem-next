<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * P5.6 archive + purge flows against an ISOLATED database
 * (bcoem_test_p56): the shared bcoem_test database must never be
 * renamed/truncated by RENAME TABLE/TRUNCATE statements, so setUp clones
 * every baseline_ structure into a throwaway database, swaps the default
 * connection to it before any request, and tearDown drops it. Controllers
 * themselves stay on the boring default connection — production behavior
 * needs no configuration.
 */
final class ArchiveFlowsTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'archive.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9401;

    private const ENTRANT_EMAIL = 'archive.entrant@brewingcompetitions.com';

    private const ENTRANT_ID = 9402;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const TEST_DB = 'bcoem_test_p56';

    protected function setUp(): void
    {
        parent::setUp();

        // Build the isolated clone of every baseline_ structure.
        $base = DB::connection('mysql');
        $base->statement('DROP DATABASE IF EXISTS '.self::TEST_DB);
        $base->statement('CREATE DATABASE '.self::TEST_DB);

        $tables = array_map(
            fn ($row) => substr((string) $row->table_name, strlen('baseline_')),
            array_filter(
                $base->select(
                    "SELECT table_name FROM information_schema.tables WHERE table_schema = 'bcoem_test'",
                ),
                fn ($row) => str_starts_with((string) $row->table_name, 'baseline_')
                    && ! str_starts_with((string) $row->table_name, 'baseline_migrations'),
            ),
        );

        foreach ($tables as $table) {
            $base->statement(
                'CREATE TABLE '.self::TEST_DB.'.`'.$table.'` LIKE bcoem_test.`baseline_'.$table.'`',
            );
        }

        // Swap the default connection: controllers keep using the default,
        // so requests below hit the isolated database untouched.
        Config::set('database.connections.p56', array_merge(
            config('database.connections.mysql'),
            [
                'database' => self::TEST_DB,
                'prefix' => '',
            ],
        ));
        Config::set('database.default', 'p56');
        DB::purge('p56');

        self::seedConfigRows();
        self::seedUser(self::ADMIN_ID, self::ADMIN_EMAIL, '1');
        $this->post('/login', [
            'loginUsername' => self::ADMIN_EMAIL,
            'loginPassword' => 'bcoem',
        ]);
    }

    protected function tearDown(): void
    {
        Config::set('database.default', 'mysql');
        DB::purge('p56');
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS '.self::TEST_DB);

        parent::tearDown();
    }

    private function seedConfigRows(): void
    {
        DB::table('contest_info')->insert(['id' => 1, 'contestID' => '12345', 'contestName' => 'P56 Test Comp']);
        DB::table('preferences')->insert(['id' => 1, 'prefsStyleSet' => 'BJCP2021']);
        DB::table('judging_preferences')->insert(['id' => 1]);
    }

    private function seedUser(int $id, string $email, string $level): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'user_name' => $email,
            'password' => self::HASH,
            'userLevel' => $level,
            'userCreated' => '2024-01-01 00:00:01',
        ]);
        DB::table('brewer')->insert([
            'id' => $id,
            'uid' => $id,
            'brewerFirstName' => $level === '1' ? 'Archive' : 'Entry',
            'brewerLastName' => 'User'.$id,
            'brewerEmail' => $email,
        ]);
    }

    /**
     * One entry with children across every eid-keyed child table.
     *
     * @return int entry id
     */
    private function seedEntry(int $id, string $updated, bool $paid = true): int
    {
        DB::table('brewing')->insert([
            'id' => $id,
            'brewName' => 'Entry '.$id,
            'brewPaid' => $paid ? '1' : '0',
            'brewConfirmed' => '1',
            'brewUpdated' => $updated,
            'brewBrewerID' => (string) self::ENTRANT_ID,
        ]);
        DB::table('judging_scores')->insert(['eid' => $id, 'bid' => self::ADMIN_ID, 'scorePlace' => '1']);
        DB::table('judging_scores_bos')->insert(['eid' => $id, 'bid' => self::ADMIN_ID, 'scoreEntry' => $id, 'scorePlace' => '1']);
        DB::table('special_best_data')->insert(['eid' => $id, 'bid' => self::ADMIN_ID, 'sbd_place' => '1']);
        DB::table('evaluation')->insert(['eid' => $id, 'uid' => self::ADMIN_ID]);

        return $id;
    }

    public function test_rename_recreate_preserves_history_and_resets_live(): void
    {
        $this->seedEntry(501, '2030-06-01 00:00:00');
        DB::table('staff')->insert(['id' => 11, 'uid' => self::ADMIN_ID]);

        $response = $this->post('/admin/archive', [
            'archiveSuffix' => 'p56a',
            'confirm' => 'yes',
        ]);
        $response->assertRedirect('/admin/archive');

        // History preserved in siblings…
        self::assertSame(1, DB::table('brewing_p56a')->count());
        self::assertSame(1, DB::table('judging_scores_p56a')->count());
        self::assertSame(1, DB::table('staff_p56a')->count());
        self::assertSame(501, DB::table('brewing_p56a')->value('id'));

        // …live tables empty but structurally identical (CREATE TABLE LIKE)…
        self::assertSame(0, DB::table('brewing')->count());
        self::assertSame(0, DB::table('judging_scores')->count());
        self::assertSame(0, DB::table('staff')->count());

        // …and AUTO_INCREMENT restarted: the next entry gets id 1.
        DB::table('brewing')->insert(['brewName' => 'next season']);
        self::assertSame(1, (int) DB::table('brewing')->where('brewName', 'next season')->value('id'));

        // Pin 6: contestID nulled, archive registered.
        self::assertNull(DB::table('contest_info')->where('id', 1)->value('contestID'));
        self::assertSame('p56a', DB::table('archive')->where('archiveSuffix', 'p56a')->value('archiveSuffix'));
    }

    public function test_keep_flags_preserve_live_data_and_copy_history(): void
    {
        $this->seedUser(self::ENTRANT_ID, self::ENTRANT_EMAIL, '2');
        $this->seedEntry(601, '2030-06-01 00:00:00');
        DB::table('special_best_info')->insert(['id' => 21, 'sbi_name' => 'Best P56']);
        DB::table('style_types')->insert([['id' => 1, 'styleTypeName' => 'Stock'], ['id' => 20, 'styleTypeName' => 'Custom P56']]);
        DB::table('sponsors')->insert(['id' => 31, 'sponsorName' => 'Sponsor']);
        DB::table('drop_off')->insert(['id' => 41, 'dropLocationName' => 'Dropoff']);
        DB::table('judging_locations')->insert(['id' => 51, 'judgingLocName' => 'Loc']);

        $this->post('/admin/archive', [
            'archiveSuffix' => 'p56k',
            'confirm' => 'yes',
            'keepParticipants' => '1',
            'keepSpecialBest' => '1',
            'keepSponsors' => '1',
            'keepDropoff' => '1',
            'keepLocations' => '1',
            'keepStyleTypes' => '1',
            'keepEvaluations' => '1',
        ]);

        // Kept participants: copied to archives, LIVE untouched (pin 2 keep branch).
        self::assertSame(2, DB::table('users_p56k')->count());
        self::assertSame(2, DB::table('users')->count());
        self::assertTrue(DB::table('users')->where('id', self::ENTRANT_ID)->exists());

        // Kept special-best: info copied + kept live; DATA renamed away either way.
        self::assertSame(1, DB::table('special_best_info_p56k')->count());
        self::assertSame(1, DB::table('special_best_info')->count());

        self::assertSame(1, DB::table('special_best_data_p56k')->count());
        self::assertSame(0, DB::table('special_best_data')->count());

        // Kept style types: customs survive in live (pin 5 inverse).
        self::assertSame(2, DB::table('style_types_p56k')->count());
        self::assertTrue(DB::table('style_types')->where('id', 20)->exists());

        // Kept evaluations: copy only, live left entirely alone (pin 4).
        self::assertSame(1, DB::table('evaluation_p56k')->count());
        self::assertSame(1, DB::table('evaluation')->count());

        // Kept truncate-list tables are untouched (pin 3).
        self::assertSame(1, DB::table('sponsors')->count());
        self::assertSame(1, DB::table('drop_off')->count());
        self::assertSame(1, DB::table('judging_locations')->count());
    }

    public function test_default_archive_destroys_per_retention_statement(): void
    {
        $this->seedUser(self::ENTRANT_ID, self::ENTRANT_EMAIL, '2');
        $this->seedEntry(602, '2030-06-01 00:00:00');
        DB::table('special_best_info')->insert(['id' => 22, 'sbi_name' => 'Best P56']);
        DB::table('style_types')->insert([['id' => 1, 'styleTypeName' => 'Stock'], ['id' => 20, 'styleTypeName' => 'Custom P56']]);
        DB::table('sponsors')->insert(['id' => 32, 'sponsorName' => 'Sponsor']);
        DB::table('drop_off')->insert(['id' => 42, 'dropLocationName' => 'Dropoff']);
        DB::table('judging_locations')->insert(['id' => 52, 'judgingLocName' => 'Loc']);

        $this->post('/admin/archive', [
            'archiveSuffix' => 'p56d',
            'confirm' => 'yes',
        ]);

        // Retention item 4: every entrant gone, history in the renamed sibling.
        self::assertSame(0, DB::table('users')->count() - 1); // minus performing admin
        self::assertTrue(DB::table('users_p56d')->where('id', self::ENTRANT_ID)->exists());
        self::assertFalse(DB::table('users')->where('id', self::ENTRANT_ID)->exists());

        // Retention item 3: drop-off/sponsor/location config destroyed.
        self::assertSame(0, DB::table('sponsors')->count());
        self::assertSame(0, DB::table('drop_off')->count());
        self::assertSame(0, DB::table('judging_locations')->count());

        // Retention item 2: custom style types (id >= 16) deleted from live
        // after being copied to the archive; stock types untouched.
        self::assertFalse(DB::table('style_types')->where('id', 20)->exists());
        self::assertTrue(DB::table('style_types')->where('id', 1)->exists());
        self::assertSame(2, DB::table('style_types_p56d')->count());

        // Evaluations not kept: emptied via the rename path (pin 4).
        self::assertSame(0, DB::table('evaluation')->count());
        self::assertSame(1, DB::table('evaluation_p56d')->count());
    }

    public function test_current_admin_survives_participant_purge(): void
    {
        $this->seedUser(self::ENTRANT_ID, self::ENTRANT_EMAIL, '2');
        $this->seedEntry(603, '2030-06-01 00:00:00');

        $this->post('/admin/archive', [
            'archiveSuffix' => 'p56s',
            'confirm' => 'yes',
        ]);

        self::assertTrue(DB::table('users')->where('id', self::ADMIN_ID)->exists());
        self::assertTrue(DB::table('brewer')->where('uid', self::ADMIN_ID)->exists());
        self::assertSame(self::ADMIN_ID, (int) DB::table('users')->max('id'));

        // The authenticated session still resolves: admin surface reachable.
        $this->get('/admin/archive')->assertOk();

        // The purge dashboard renders against the post-archive schema.
        $this->get('/admin/purge')->assertOk();
    }

    public function test_purge_stale_entries_removes_children(): void
    {
        $this->seedEntry(701, '2020-01-01 00:00:00'); // stale
        $this->seedEntry(702, '2030-01-01 00:00:00'); // fresh

        $this->post('/admin/purge/entries', [
            'confirm' => 'yes',
            'dateThreshold' => '2025-01-01',
        ])->assertRedirect('/admin/purge');

        self::assertFalse(DB::table('brewing')->where('id', 701)->exists());
        foreach (['judging_scores', 'judging_scores_bos', 'special_best_data', 'evaluation'] as $table) {
            self::assertFalse(DB::table($table)->where('eid', 701)->exists(), $table.' child survived');
        }

        self::assertTrue(DB::table('brewing')->where('id', 702)->exists());
        self::assertTrue(DB::table('judging_scores')->where('eid', 702)->exists());
    }

    public function test_purge_unpaid_entries(): void
    {
        $this->seedEntry(801, '2030-06-01 00:00:00');
        $this->seedEntry(802, '2030-06-01 00:00:00', paid: false);

        $this->post('/admin/purge/unpaid', ['confirm' => 'yes'])->assertRedirect('/admin/purge');

        self::assertTrue(DB::table('brewing')->where('id', 801)->exists());
        self::assertFalse(DB::table('brewing')->where('id', 802)->exists());
    }

    public function test_confirmation_gate_blocks_mutation_without_confirm(): void
    {
        $this->seedEntry(901, '2030-06-01 00:00:00');

        $this->post('/admin/archive', ['archiveSuffix' => 'p56x'])->assertRedirect('/admin/archive');
        $this->post('/admin/purge/unpaid')->assertRedirect('/admin/purge');

        self::assertTrue(DB::table('brewing')->where('id', 901)->exists());
        self::assertFalse(DB::connection('mysql')->selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = '".self::TEST_DB."' AND table_name = 'brewing_p56x'",
        )->c > 0);
        self::assertSame(1, DB::table('brewing')->count()); // unpaid purge did not run either
    }

    /**
     * Pin 7 SINGLE-mode variant: DELETE by comp_id instead of TRUNCATE.
     * The standalone port selects this via config (legacy: SINGLE constant +
     * $_SESSION['comp_id']); the isolated clone gets comp_id columns added.
     */
    public function test_single_mode_variant_deletes_by_comp_id(): void
    {
        foreach (['judging_scores', 'judging_scores_bos', 'special_best_data'] as $table) {
            DB::statement('ALTER TABLE `'.$table.'` ADD COLUMN comp_id INT NOT NULL DEFAULT 1');
        }
        Config::set('bcoem.single_mode', true);
        Config::set('bcoem.single_comp_id', 1);

        DB::table('judging_scores')->insert([['eid' => 1, 'comp_id' => 1], ['eid' => 2, 'comp_id' => 2]]);
        DB::table('judging_scores_bos')->insert([['eid' => 1, 'scoreEntry' => 1, 'comp_id' => 1], ['eid' => 2, 'scoreEntry' => 2, 'comp_id' => 2]]);
        DB::table('special_best_data')->insert([['eid' => 1, 'comp_id' => 1], ['eid' => 2, 'comp_id' => 2]]);

        $this->post('/admin/purge/scores', ['confirm' => 'yes'])->assertRedirect('/admin/purge');

        // comp 1 wiped, comp 2 untouched — DELETE-by-comp_id semantics, not TRUNCATE.
        self::assertSame(0, DB::table('judging_scores')->where('comp_id', 1)->count());
        self::assertSame(1, DB::table('judging_scores')->where('comp_id', 2)->count());
        self::assertSame(0, DB::table('judging_scores_bos')->where('comp_id', 1)->count());
        self::assertSame(1, DB::table('judging_scores_bos')->where('comp_id', 2)->count());
        self::assertSame(0, DB::table('special_best_data')->where('comp_id', 1)->count());
        self::assertSame(1, DB::table('special_best_data')->where('comp_id', 2)->count());
    }

    public function test_invalid_suffix_is_rejected(): void
    {
        $this->seedEntry(910, '2030-06-01 00:00:00');

        $this->post('/admin/archive', [
            'archiveSuffix' => 'bad suffix!',
            'confirm' => 'yes',
        ])->assertSessionHasErrors('archiveSuffix');

        self::assertSame(1, DB::table('brewing')->count());
        self::assertTrue(DB::table('brewing')->where('id', 910)->exists());
    }
}
