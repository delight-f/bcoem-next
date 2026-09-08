<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Slice D output pair: maps, dropoff, print, results, bos_mat
 * (.scratch/bcoem-next/issues/phase-5/02-remaining-outputs.md).
 *
 * Seeds a mini competition (prefix "P52d") with one placed entry so the
 * results rollup has content, then asserts every output endpoint gates
 * guests/non-admins and streams a PDF. Maps mirrors its legacy surface
 * exactly — a redirect to Google Maps, not a document — so it alone
 * asserts a Location header instead of %PDF.
 */
final class OutputPairsDTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'p52d.admin@brewingcompetitions.com';

    private const ADMIN_ID = 95201;

    private const USER_EMAIL = 'p52d.user@brewingcompetitions.com';

    private const USER_ID = 95202;

    private const BREWER_ID = 95203;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $contactIds = [];

    /** @var list<int> */
    private array $dropoffIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $styleIds = [];

    /** @var array<int, array<string, mixed>> */
    private array $origStyleTypes = [];

    /** @var list<int> */
    private array $tableIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::USER_ID])->delete();
        foreach ([self::ADMIN_EMAIL, self::USER_EMAIL] as $email) {
            DB::table('users')->where('user_name', $email)->delete();
        }

        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->post('/login', [
            'loginUsername' => self::ADMIN_EMAIL,
            'loginPassword' => 'bcoem',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('contacts')->whereIn('id', $this->contactIds ?: [0])->delete();
        DB::table('drop_off')->whereIn('id', $this->dropoffIds ?: [0])->delete();
        DB::table('judging_scores')->whereIn('eid', $this->entryIds ?: [0])->delete();
        DB::table('judging_scores_bos')->whereIn('eid', $this->entryIds ?: [0])->delete();
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        DB::table('judging_tables')->whereIn('id', $this->tableIds ?: [0])->delete();
        DB::table('styles')->whereIn('id', $this->styleIds ?: [0])->delete();
        DB::table('brewer')->where('uid', self::BREWER_ID)->delete();

        foreach ($this->origStyleTypes as $id => $row) {
            DB::table('style_types')->where('id', $id)->update($row);
        }
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::USER_ID])->delete();

        parent::tearDown();
    }

    public function test_guest_and_non_admin_are_rejected(): void
    {
        DB::table('users')->insert([
            'id' => self::USER_ID,
            'user_name' => self::USER_EMAIL,
            'password' => self::HASH,
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        // Guests bounce to login (route middleware)…
        $this->post('/logout');
        foreach (['maps', 'dropoff', 'print', 'results', 'bos_mat'] as $output) {
            $this->get('/admin/output/'.$output)->assertRedirect('/login');
        }

        // …and authenticated non-admins to the standard alert.
        $this->post('/login', [
            'loginUsername' => self::USER_EMAIL,
            'loginPassword' => 'bcoem',
        ]);
        foreach (['maps', 'dropoff', 'print', 'results', 'bos_mat'] as $output) {
            $this->get('/admin/output/'.$output)->assertRedirect('/?msg=99');
        }
    }

    public function test_maps_redirects_to_google_maps_with_the_address(): void
    {
        // Spaces become '+' like legacy.
        $this->get('/admin/output/maps?id=456+Brewing+Way')
            ->assertRedirect('http://maps.google.com/maps?f=q&source=s_q&hl=en&q=456+Brewing+Way');

        // Characterization: legacy strips the fancybox "&KeepThis=true"
        // leftover with rtrim() on a CHARACTER LIST, so trailing letters
        // from that list get eaten too ("Street" → "S"). Mirrored verbatim.
        $this->get('/admin/output/maps?id=123+Main+Street')
            ->assertRedirect('http://maps.google.com/maps?f=q&source=s_q&hl=en&q=123+Main+S');
    }

    public function test_results_streams_pdf_in_every_mode(): void
    {
        $this->seedCompetition();

        foreach (['', '?go=judging_scores', '?go=judging_scores_bos', '?go=best'] as $query) {
            $response = $this->get('/admin/output/results'.$query);

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }
    }

    public function test_bos_mat_streams_pdf_in_every_mode(): void
    {
        $this->seedCompetition();

        foreach ([
            '',                       // default: BOS mats by style type
            '?filter=entry',          // footer shows entry numbers
            '?action=mini-bos',       // mats grouped by judging table
            '?action=blank',          // blank mat sheet
            '?action=pro-am&sort=2&view=1',
        ] as $query) {
            $response = $this->get('/admin/output/bos_mat'.$query);

            $response->assertOk();
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }
    }

    public function test_dropoff_streams_summary_and_check_sheets(): void
    {
        $this->seedCompetition();

        $locA = (int) DB::table('drop_off')->insertGetId([
            'dropLocation' => 'P52d Homebrew Shop, 12 Yeast Lane',
            'dropLocationName' => 'P52d% Homebrew Shop',
        ]);
        $this->dropoffIds[] = $locA;
        DB::table('brewer')->where('uid', self::BREWER_ID)->update(['brewerDropOff' => $locA]);

        foreach (['', '?go=check'] as $query) {
            $response = $this->get('/admin/output/dropoff'.$query);

            $response->assertOk();
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }

        // Both seeded entries are attributed to the location via their brewer.
        $this->assertSame(
            2,
            DB::table('brewing as b')->join('brewer as br', 'b.brewBrewerID', '=', 'br.uid')
                ->where('br.brewerDropOff', $locA)->count(),
        );
    }

    public function test_print_streams_contact_sheet(): void
    {
        $this->contactIds[] = (int) DB::table('contacts')->insertGetId([
            'contactFirstName' => 'P52d',
            'contactLastName' => 'Organizer',
            'contactPosition' => 'Competition Coordinator',
            'contactEmail' => 'p52d.organizer@brewingcompetitions.com',
        ]);

        $response = $this->get('/admin/output/print');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $single = $this->get('/admin/output/print?id='.((int) $this->contactIds[0]));
        $single->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $single->getContent());
    }

    /**
     * Mini competition: brewer, BJCP styles, one judging table, two
     * received entries — a 1st-place beer (also mini-BOS'd and BOS-placed)
     * plus an HM cider — so results/bos_mat have content to print.
     */
    private function seedCompetition(): void
    {
        DB::table('brewer')->insert([
            'uid' => self::BREWER_ID,
            'id' => self::BREWER_ID,
            'brewerFirstName' => 'P52d',
            'brewerLastName' => 'Brewer',
            'brewerEmail' => 'p52d.brewer@brewingcompetitions.com',
            'brewerProAm' => 0,
        ]);

        $this->style('1', 'D', 'Standard Bitter');
        $this->style('28', 'A', 'Common Cider');

        foreach ([[1, 'Beer'], [2, 'Cider']] as [$typeId, $typeName]) {
            $row = (array) DB::table('style_types')->where('id', $typeId)->first();
            $this->origStyleTypes[$typeId] = $row;
            DB::table('style_types')->where('id', $typeId)->update([
                'styleTypeName' => $typeName,
                'styleTypeBOS' => 'Y',
                'styleTypeBOSMethod' => '2',
            ]);
        }

        $tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'P52d% Judging Table',
            'tableNumber' => random_int(50, 99),
            'tableLocation' => 1,
            'tableStyles' => '1D,28A',
        ]);
        $this->tableIds[] = $tableId;

        $bitter = $this->entry([
            'brewName' => 'P52d% Bitter',
            'brewCategorySort' => '1',
            'brewCategory' => '1',
            'brewSubCategory' => 'D',
            'brewStyle' => 'Standard Bitter',
            'brewJudgingNumber' => '520001',
        ]);
        $cider = $this->entry([
            'brewName' => 'P52d% Cider',
            'brewCategorySort' => '28',
            'brewCategory' => '28',
            'brewSubCategory' => 'A',
            'brewStyle' => 'Common Cider',
            'brewJudgingNumber' => '520002',
        ]);

        DB::table('judging_scores')->insert([
            'eid' => $bitter,
            'bid' => self::BREWER_ID,
            'scoreTable' => $tableId,
            'scoreEntry' => 36,
            'scorePlace' => '1',
            'scoreType' => '1',
            'scoreMiniBOS' => 1,
        ]);
        DB::table('judging_scores')->insert([
            'eid' => $cider,
            'bid' => self::BREWER_ID,
            'scoreTable' => $tableId,
            'scoreEntry' => 30,
            'scorePlace' => '5',
            'scoreType' => '2',
            'scoreMiniBOS' => 0,
        ]);
        DB::table('judging_scores_bos')->insert([
            'eid' => $bitter,
            'bid' => self::BREWER_ID,
            'scoreEntry' => 36,
            'scorePlace' => '1',
            'scoreType' => '1',
        ]);
    }

    private function style(string $group, string $num, string $name): int
    {
        DB::table('styles')->insert([
            'brewStyleGroup' => $group,
            'brewStyleNum' => $num,
            'brewStyle' => $name,
            'brewStyleType' => '1',
            'brewStyleVersion' => 'BJCP2021',
            'brewStyleOwn' => 'bcoe',
        ]);
        $id = (int) DB::table('styles')->max('id');
        $this->styleIds[] = $id;

        return $id;
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function entry(array $overrides): int
    {
        DB::table('brewing')->insert(array_merge([
            'brewBrewerID' => (string) self::BREWER_ID,
            'brewConfirmed' => '1',
            'brewPaid' => 1,
            'brewReceived' => 1,
        ], $overrides));
        $id = (int) DB::table('brewing')->max('id');
        $this->entryIds[] = $id;

        return $id;
    }
}
