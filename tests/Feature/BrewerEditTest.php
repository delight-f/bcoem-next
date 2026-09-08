<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Brewer form 0 — account & contact edit round-trip (ticket 05).
 *
 * The bcoem_test DB is shared with the Characterization suite which
 * truncates users/brewer/brewing/staff — every test seeds what it needs
 * and cleans up after itself.
 */
final class BrewerEditTest extends PublicSurfaceTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $orphans = DB::table('users')->where('user_name', 'like', '%@example.com')->pluck('id');
        foreach ($orphans as $oid) {
            DB::table('staff')->where('uid', $oid)->delete();
            DB::table('brewer')->where('uid', $oid)->delete();
            DB::table('users')->where('id', $oid)->delete();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $id) {
            DB::table('brewer')->where('uid', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    /**
     * Seed an entrant account + brewer row and log in as them.
     *
     * @return int the users.id
     */
    private function loginAsEntrant(string $email = 'entrant@example.com'): int
    {
        $userId = (int) DB::table('users')->insertGetId([
            'user_name' => $email,
            'password' => app('hash')->make('bcoem'),
            'userLevel' => '2',
            'userQuestion' => 'What is your favorite all-time beer to drink?',
            'userQuestionAnswer' => app('hash')->make('pabst'),
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 1,
        ]);
        $this->createdUsers[] = $userId;

        DB::table('brewer')->insert([
            'uid' => $userId,
            'brewerFirstName' => 'Edna',
            'brewerLastName' => 'Entrant',
            'brewerAddress' => '1 Old Way',
            'brewerCity' => 'Oldtown',
            'brewerState' => 'CO',
            'brewerZip' => '80001',
            'brewerCountry' => 'United States',
            'brewerPhone1' => '555-0001',
            'brewerPhone2' => null,
            'brewerEmail' => $email,
            // Column this form must never touch — asserted untouched below.
            'brewerClubs' => 'Stale Club',
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
        ]);

        $this->post('/login', [
            'loginUsername' => $email,
            'loginPassword' => 'bcoem',
        ])->assertRedirect();

        return $userId;
    }

    /** @return array<string, mixed> */
    private function savePayload(string $email = 'entrant@example.com'): array
    {
        return [
            'brewerEmail' => $email,
            'brewerFirstName' => 'Edward',
            'brewerLastName' => 'Entrantson',
            'brewerAddress' => '42 New Ave',
            'brewerCity' => 'Newtown',
            'brewerState' => 'WY',
            'brewerZip' => '82001',
            'brewerCountry' => 'United States',
            'brewerPhone1' => '555-0002',
            'brewerPhone2' => '555-0003',
        ];
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $this->get('/list/edit-account')->assertRedirect('/login');
        $this->post('/list/edit-account', $this->savePayload())->assertRedirect('/login');
    }

    public function test_list_links_to_edit_account_when_authenticated(): void
    {
        $this->loginAsEntrant();

        $this->get('/list')
            ->assertOk()
            ->assertSee('/list/edit-account');
    }

    public function test_edit_page_prefills_from_brewer_row(): void
    {
        $this->loginAsEntrant();

        $this->get('/list/edit-account')
            ->assertOk()
            ->assertSee('Edna')
            ->assertSee('entrant@example.com');
    }

    public function test_save_round_trips_every_column_and_redirects_to_list(): void
    {
        $userId = $this->loginAsEntrant();

        $this->post('/list/edit-account', $this->savePayload())
            ->assertRedirect('/list?msg=2');

        $brewer = (array) DB::table('brewer')->where('uid', $userId)->first();
        $payload = $this->savePayload();

        foreach (['brewerFirstName', 'brewerLastName', 'brewerAddress', 'brewerCity',
            'brewerState', 'brewerZip', 'brewerPhone1', 'brewerPhone2'] as $field) {
            $this->assertSame($payload[$field], $brewer[$field], $field.' not saved');
        }

        // Columns outside form 0's scope survive untouched.
        $this->assertSame('Stale Club', $brewer['brewerClubs']);
        $this->assertSame('N', $brewer['brewerJudge']);
    }

    public function test_email_change_syncs_users_user_name(): void
    {
        $userId = $this->loginAsEntrant();

        $this->post('/list/edit-account', $this->savePayload('new.email@example.com'))
            ->assertRedirect('/list?msg=2');

        $this->assertSame(
            'new.email@example.com',
            DB::table('users')->where('id', $userId)->value('user_name')
        );
        $this->assertSame(
            'new.email@example.com',
            DB::table('brewer')->where('uid', $userId)->value('brewerEmail')
        );
    }

    public function test_changed_email_still_logs_in_after_sync(): void
    {
        $this->loginAsEntrant();

        $this->post('/list/edit-account', $this->savePayload('renamed@example.com'));

        $this->post('/logout');

        $this->post('/login', [
            'loginUsername' => 'renamed@example.com',
            'loginPassword' => 'bcoem',
        ])->assertRedirect('/list');
    }

    public function test_duplicate_email_is_rejected_and_nothing_changes(): void
    {
        $userId = $this->loginAsEntrant();
        $otherId = (int) DB::table('users')->insertGetId([
            'user_name' => 'taken@example.com',
            'password' => app('hash')->make('bcoem'),
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 1,
        ]);
        $this->createdUsers[] = $otherId;

        $response = $this->from('/list/edit-account')
            ->post('/list/edit-account', $this->savePayload('taken@example.com'));

        $response->assertRedirect('/list/edit-account');
        $response->assertSessionHasErrors('brewerEmail');

        $this->assertSame(
            'entrant@example.com',
            DB::table('users')->where('id', $userId)->value('user_name')
        );
        $this->assertSame(
            'Edna',
            DB::table('brewer')->where('uid', $userId)->value('brewerFirstName')
        );
    }

    public function test_blank_optionals_are_stored_as_null_not_empty_string(): void
    {
        $userId = $this->loginAsEntrant();

        $payload = $this->savePayload();
        $payload['brewerAddress'] = '';
        $payload['brewerCity'] = '';
        $payload['brewerState'] = '';
        $payload['brewerPhone2'] = '';

        $this->post('/list/edit-account', $payload)->assertRedirect('/list?msg=2');

        $brewer = (array) DB::table('brewer')->where('uid', $userId)->first();
        $this->assertNull($brewer['brewerAddress']);
        $this->assertNull($brewer['brewerCity']);
        $this->assertNull($brewer['brewerState']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function provideOversizedInput(): iterable
    {
        yield 'zip over 10' => [['brewerZip' => '12345678901']];
        yield 'first name over 200' => [['brewerFirstName' => str_repeat('x', 201)]];
        yield 'phone over 25' => [['brewerPhone1' => str_repeat('5', 26)]];
    }

    /**
     * @param  array<string, string>  $override
     */
    #[DataProvider('provideOversizedInput')]
    public function test_invalid_input_is_rejected(array $override): void
    {
        $userId = $this->loginAsEntrant();

        $this->post('/list/edit-account', [...$this->savePayload(), ...$override])
            ->assertSessionHasErrors();

        $this->assertSame(
            'Edna',
            DB::table('brewer')->where('uid', $userId)->value('brewerFirstName')
        );
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->loginAsEntrant();

        $payload = $this->savePayload();
        unset($payload['brewerFirstName'], $payload['brewerLastName'], $payload['brewerEmail']);

        $this->post('/list/edit-account', $payload)
            ->assertSessionHasErrors(['brewerFirstName', 'brewerLastName', 'brewerEmail']);
    }
}
