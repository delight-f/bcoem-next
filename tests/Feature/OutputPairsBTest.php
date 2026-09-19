<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Entries\UserDocs;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5.2 outputs, slice B (ticket 02-remaining-outputs.md):
 * shipping_label, entry (paper forms), judge_notes, assignments,
 * scoresheets.
 *
 *  - admin gate on every route ('/?msg=99' for authed non-admins);
 *  - each PDF output returns 200 with an application/pdf %PDF body;
 *  - scoresheets is a raw single-file stream of an uploaded user_docs PDF
 *    (legacy is file streaming, NOT bundling or generation), with
 *    traversal clamped to the user_docs directory.
 */
final class OutputPairsBTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p52b.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9520;

    private const MEMBER_EMAIL = 'p52b.member@brewingcompetitions.com';

    private const MEMBER_ID = 9521;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $brewerIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $styleIds = [];

    /** @var list<int> */
    private array $tableIds = [];

    /** @var list<int> */
    private array $locationIds = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::ADMIN_EMAIL, self::MEMBER_EMAIL] as $email) {
            DB::table('users')->where('user_name', $email)->delete();
        }

        $docs = UserDocs::root();
        if (! is_dir($docs)) {
            mkdir($docs, 0775, true);
        }
        $fixture = UserDocs::path('P52b-951001.pdf');
        file_put_contents($fixture, "%PDF-1.4\n%P52b-fake-scoresheet\n%%EOF\n");
        $this->tempFiles[] = $fixture;

        $this->makeUser(self::ADMIN_EMAIL, self::ADMIN_ID, '1');
        $this->makeUser(self::MEMBER_EMAIL, self::MEMBER_ID, '2');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        @rmdir(UserDocs::root());

        // flightEntryID is a CSV column: match members with LIKE, not =.
        foreach (array_merge($this->entryIds, [0]) as $id) {
            DB::table('judging_flights')->where('flightEntryID', 'like', '%'.$id.'%')->delete();
        }
        DB::table('judging_assignments')->whereIn('bid', array_merge($this->userIds, [0]))->delete();
        DB::table('staff')->whereIn('uid', array_merge($this->userIds, [0]))->delete();
        DB::table('brewing')->whereIn('id', array_merge($this->entryIds, [0]))->delete();
        DB::table('brewer')->whereIn('id', array_merge($this->brewerIds, [0]))->delete();
        DB::table('judging_tables')->whereIn('id', array_merge($this->tableIds, [0]))->delete();
        DB::table('judging_locations')->whereIn('id', array_merge($this->locationIds, [0]))->delete();
        DB::table('styles')->whereIn('id', array_merge($this->styleIds, [0]))->delete();
        DB::table('users')->whereIn('id', array_merge($this->userIds, [self::ADMIN_ID, self::MEMBER_ID]))->delete();

        parent::tearDown();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach ($this->urls() as $url) {
            $this->get($url)->assertRedirect();
        }
    }

    public function test_non_admins_are_gated(): void
    {
        $this->loginAs(self::MEMBER_EMAIL);

        foreach ($this->urls() as $url) {
            $this->get($url)->assertRedirect('/?msg=99');
        }
    }

    public function test_all_outputs_render_pdf_for_admins(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        // Seed a full fixture graph so every query path has data.
        $brewerId = $this->seedBrewer();
        $locationId = $this->seedLocation();
        $tableId = $this->seedTable($locationId);
        $entryA = $this->seedEntry($brewerId, ['brewJudgingNumber' => '951001', 'brewPaid' => 1]);
        $entryB = $this->seedEntry($brewerId, ['brewJudgingNumber' => '951002', 'brewPossAllergens' => 'P52b gluten']);
        $this->seedAssignment($this->memberUid(), $tableId, $locationId, 'J', 'HJ');
        $this->seedFlight($tableId, [$entryA, $entryB], 1, 1);
        DB::table('staff')->insert(['uid' => $this->adminUid(), 'staff_judge' => 1]);

        foreach ($this->urls() as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }

        // The ?go= variants of judge_notes render too.
        foreach (['allergens', 'admin'] as $go) {
            $response = $this->get('/admin/output/judge_notes?go='.$go);

            $response->assertOk();
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }
    }

    public function test_empty_competition_still_renders_pdfs(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        foreach ($this->urls(['scoresheets']) as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }
    }

    public function test_scoresheets_streams_uploaded_file_bytes_exactly(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        $payload = "%PDF-1.4\n%P52b-fake-scoresheet\n%%EOF\n";
        $this->assertSame($payload, file_get_contents(UserDocs::path('P52b-951001.pdf')));
        $response = $this->get('/admin/output/scoresheets?file=P52b-951001.pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($payload, $response->getContent());
    }

    public function test_scoresheets_rejects_missing_and_traversal_paths(): void
    {
        $this->loginAs(self::ADMIN_EMAIL);

        // A real file OUTSIDE user_docs must not be reachable.
        $outside = public_path('P52b-outside.pdf');
        file_put_contents($outside, '%PDF-1.4 secret');
        $this->tempFiles[] = $outside;

        $this->get('/admin/output/scoresheets?file=P52b-nope.pdf')->assertNotFound();
        // basename() clamping folds any traversal outside user_docs onto an
        // in-bounds name; ../P52b-outside.pdf therefore resolves to
        // user_docs/P52b-outside.pdf, which does not exist.
        $this->get('/admin/output/scoresheets?file=../P52b-outside.pdf')->assertNotFound();
        $this->get('/admin/output/scoresheets')->assertNotFound();
    }

    /**
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private function urls(array $exclude = []): array
    {
        return array_values(collect([
            '/admin/output/shipping_label',
            '/admin/output/judge_notes',
            '/admin/output/assignments',
            '/admin/output/scoresheets?file=P52b-951001.pdf',
        ])->reject(static fn (string $url) => in_array(str_contains($url, '?') ? explode('?', $url)[0] : $url, $exclude, true))->all());
    }

    private function loginAs(string $email): void
    {
        $this->loginWithEmail($email);
    }

    private function makeUser(string $email, int $id, string $level): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'user_name' => $email,
            'password' => self::HASH,
            'userLevel' => $level,
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        $this->userIds[] = $id;
    }

    private function seedBrewer(?int $uid = null): int
    {
        $brewerId = (int) DB::table('brewer')->insertGetId([
            'uid' => $uid ?? $this->memberUid(),
            'brewerFirstName' => 'P52b First',
            'brewerLastName' => 'P52b Last',
            'brewerAddress' => '42 P52b Lane',
            'brewerCity' => 'Fixture City',
            'brewerState' => 'FC',
            'brewerZip' => '00001',
            'brewerCountry' => 'United States',
            'brewerEmail' => self::MEMBER_EMAIL,
            'brewerJudgeRank' => 'Recognized, Certified Cider Guide',
            'brewerClubs' => 'P52b Club',
            'brewerDropOff' => 0,
        ]);
        $this->brewerIds[] = $brewerId;

        return $brewerId;
    }

    private function seedLocation(): int
    {
        $locationId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 1,
            'judgingDate' => '1700000000',
            'judgingLocName' => 'P52b Session Hall',
        ]);
        $this->locationIds[] = $locationId;

        return $locationId;
    }

    private function seedTable(int $locationId): int
    {
        $styleId = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'P52b Light Lager',
            'brewStyleGroup' => '1',
            'brewStyleNum' => 'A',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
        ]);
        $this->styleIds[] = $styleId;

        $tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'P52b Table',
            'tableStyles' => (string) $styleId,
            'tableNumber' => 950 + $styleId % 40,
            'tableLocation' => $locationId,
        ]);
        $this->tableIds[] = $tableId;

        return $tableId;
    }

    /** @param  array<string, mixed>  $overrides */
    private function seedEntry(int $brewerId, array $overrides = []): int
    {
        $entryId = (int) DB::table('brewing')->insertGetId(array_merge([
            'brewName' => 'P52b Entry',
            'brewStyle' => 'P52b Light Lager',
            'brewCategory' => '1',
            'brewCategorySort' => '1',
            'brewSubCategory' => 'A',
            'brewBrewerID' => $brewerId,
            'brewPaid' => 1,
            'brewReceived' => 1,
            'brewConfirmed' => '1',
            'brewJudgingNumber' => '951003',
            'brewInfo' => 'P52b special^ingredients',
        ], $overrides));
        $this->entryIds[] = $entryId;

        return $entryId;
    }

    private function seedAssignment(
        int $bid,
        int $tableId,
        int $locationId,
        string $assignment = 'J',
        string $roles = '',
    ): int {
        return (int) DB::table('judging_assignments')->insertGetId([
            'bid' => $bid,
            'assignment' => $assignment,
            'assignTable' => $tableId,
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => $locationId,
            'assignRoles' => $roles,
            'assignPlanning' => 0,
        ]);
    }

    /** @param  list<int>  $entryIds */
    private function seedFlight(int $tableId, array $entryIds, int $flightNumber, int $round): int
    {
        return (int) DB::table('judging_flights')->insertGetId([
            'flightTable' => $tableId,
            'flightNumber' => $flightNumber,
            'flightEntryID' => implode(',', $entryIds),
            'flightEntryOrder' => 0,
            'flightRound' => $round,
            'flightPlanning' => 0,
        ]);
    }

    private function adminUid(): int
    {
        return self::ADMIN_ID;
    }

    private function memberUid(): int
    {
        return self::MEMBER_ID;
    }
}
