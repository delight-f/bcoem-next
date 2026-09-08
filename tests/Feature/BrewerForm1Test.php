<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Brewer profile form 1 (ticket 06): clubs / Pro-Am / AHA / MHP edit at
 * /list/edit-clubs. Pins the legacy `brewerClubs` storage semantics from
 * process_brewer_info.inc.php:60-73 against the baseline_-prefixed schema.
 *
 * Fixture: users/brewer id=1 (seeded idempotently — see AuthLoginTest) plus
 * a second brewer row carrying the corpus club string, so the known-club
 * source (brewer rows + contest_info.contestClubs) is exercised. The
 * original brewer row and contestClubs value are restored on teardown; the
 * suite shares a persistent MySQL database.
 */
final class BrewerForm1Test extends PublicSurfaceTestCase
{
    private const LOGIN_EMAIL = 'user.baseline@brewingcompetitions.com';

    private const CORPUS_CLUB = 'Homebrewers Club of McDowell and Surrounding Counties';

    /** @var array<string, mixed> */
    private array $brewerBackup;

    /** @var mixed */
    private $contestClubsBackup;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite shares a persistent DB: earlier suites may have changed
        // the password (reset flow) or deleted the row, so reset the login
        // fixture every run (same pattern as AuthLoginTest).
        DB::table('users')->updateOrInsert(
            ['id' => 1],
            [
                'user_name' => self::LOGIN_EMAIL,
                'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
                'userLevel' => '2',
                'userQuestion' => 'q',
                'userQuestionAnswer' => '$2a$08$gImDLllgw/nned4kVWDAD.394FXpXeoEip85oqEQ.fIy8s4U3lwx.',
                'userCreated' => '2024-01-01 00:00:01',
                'userAdminObfuscate' => 0,
            ]
        );

        $this->brewerBackup = (array) DB::table('brewer')->where('uid', 1)->first();
        $this->contestClubsBackup = DB::table('contest_info')->where('id', 1)->value('contestClubs');

        // Second corpus brewer whose stored club feeds the known list.
        DB::table('brewer')->updateOrInsert(
            ['uid' => 906],
            [
                'brewerFirstName' => 'Corpus',
                'brewerLastName' => 'Brewer',
                'brewerEmail' => 'corpus.form1@test.example',
                'brewerClubs' => self::CORPUS_CLUB,
            ]
        );
    }

    protected function tearDown(): void
    {
        DB::table('brewer')->where('uid', 1)->update($this->brewerBackup);
        DB::table('brewer')->where('uid', 906)->delete();
        DB::table('contest_info')->where('id', 1)->update(['contestClubs' => $this->contestClubsBackup]);

        parent::tearDown();
    }

    private function login(): void
    {
        $this->post('/login', [
            'loginUsername' => self::LOGIN_EMAIL,
            'loginPassword' => 'bcoem',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'brewerClubs' => '',
            'brewerClubsOther' => '',
            'brewerAHA' => '',
            'brewerMHP' => '',
            'brewerProAm' => '0',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function brewerRow(): array
    {
        $row = DB::table('brewer')->where('uid', 1)->first();
        $this->assertNotNull($row);

        return (array) $row;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/list/edit-clubs')->assertRedirect('/login');
        $this->post('/list/edit-clubs', $this->payload())->assertRedirect('/login');
    }

    public function test_form_renders_with_saved_values(): void
    {
        DB::table('brewer')->where('uid', 1)->update([
            'brewerAHA' => '123456789',
            'brewerProAm' => '2',
        ]);

        $this->login();
        $this->get('/list/edit-clubs')
            ->assertOk()
            ->assertSee('123456789')
            ->assertSee('name="brewerMHP"', false)
            ->assertSee('Opt out');
    }

    public function test_corpus_club_round_trips_byte_for_byte(): void
    {
        $this->login();

        $this->post('/list/edit-clubs', $this->payload([
            'brewerClubs' => self::CORPUS_CLUB,
        ]))->assertRedirect('/list/edit-clubs');

        $this->assertSame(self::CORPUS_CLUB, $this->brewerRow()['brewerClubs']);
    }

    public function test_club_from_contest_list_round_trips(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestClubs' => '["Foam On The Range Test Club"]',
        ]);

        $this->login();

        $this->post('/list/edit-clubs', $this->payload([
            'brewerClubs' => 'Foam On The Range Test Club',
        ]))->assertRedirect('/list/edit-clubs');

        $this->assertSame('Foam On The Range Test Club', $this->brewerRow()['brewerClubs']);
    }

    public function test_unknown_club_is_cleared_to_null(): void
    {
        $this->login();

        $this->post('/list/edit-clubs', $this->payload([
            'brewerClubs' => 'Club That Nobody Stored',
        ]))->assertRedirect('/list/edit-clubs');

        $this->assertNull($this->brewerRow()['brewerClubs']);
    }

    public function test_other_with_text_stores_title_cased_text(): void
    {
        $this->login();

        $this->post('/list/edit-clubs', $this->payload([
            'brewerClubs' => 'Other',
            'brewerClubsOther' => 'my backyard brewing crew',
        ]))->assertRedirect('/list/edit-clubs');

        $this->assertSame('My Backyard Brewing Crew', $this->brewerRow()['brewerClubs']);
    }

    public function test_other_without_text_stores_the_other_literal(): void
    {
        $this->login();

        $this->post('/list/edit-clubs', $this->payload([
            'brewerClubs' => 'Other',
        ]))->assertRedirect('/list/edit-clubs');

        $this->assertSame('Other', $this->brewerRow()['brewerClubs']);
    }

    public function test_aha_mhp_and_pro_am_are_saved(): void
    {
        $this->login();

        $this->post('/list/edit-clubs', $this->payload([
            'brewerClubs' => self::CORPUS_CLUB,
            'brewerAHA' => 'T1234567',
            'brewerMHP' => '42',
            'brewerProAm' => '1',
        ]))->assertRedirect('/list/edit-clubs');

        $row = $this->brewerRow();
        $this->assertSame(self::CORPUS_CLUB, $row['brewerClubs']);
        $this->assertSame('T1234567', $row['brewerAHA']);
        $this->assertSame(42, (int) $row['brewerMHP']);
        $this->assertSame(1, (int) $row['brewerProAm']);
    }

    public function test_non_numeric_mhp_is_rejected_and_nothing_changes(): void
    {
        $this->login();
        $before = $this->brewerRow();

        $this->from('/list/edit-clubs')
            ->post('/list/edit-clubs', $this->payload(['brewerMHP' => 'twelve']))
            ->assertRedirect('/list/edit-clubs')
            ->assertSessionHasErrors('brewerMHP');

        $this->assertSame($before, $this->brewerRow());
    }

    public function test_invalid_pro_am_value_is_rejected(): void
    {
        $this->login();
        $before = $this->brewerRow();

        $this->from('/list/edit-clubs')
            ->post('/list/edit-clubs', $this->payload(['brewerProAm' => '9']))
            ->assertRedirect('/list/edit-clubs')
            ->assertSessionHasErrors('brewerProAm');

        $this->assertSame($before, $this->brewerRow());
    }

    public function test_clearing_fields_stores_nulls(): void
    {
        DB::table('brewer')->where('uid', 1)->update([
            'brewerClubs' => self::CORPUS_CLUB,
            'brewerAHA' => '123456789',
            'brewerMHP' => 7,
        ]);

        $this->login();

        $this->post('/list/edit-clubs', $this->payload([
            'brewerProAm' => '0',
        ]))->assertRedirect('/list/edit-clubs');

        $row = $this->brewerRow();
        $this->assertNull($row['brewerClubs']);
        $this->assertNull($row['brewerAHA']);
        $this->assertNull($row['brewerMHP']);
    }
}
