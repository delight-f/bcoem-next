<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * P5.4 styles stack through the UI path: set-version lookup predicates
 * (ledger pins #5-#7), the accepted-styles bulk update writing
 * prefsSelectedStyles, custom-style CRUD with the pin-4 normalization
 * decision, and the brewStyle rename cascade into brewing.
 */
final class AdminScreensStylesTest extends AdminScreensTestCase
{
    /** @var list<int> */
    private array $styleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Sweep fixtures orphaned by a crashed earlier run: a stale
        // 'P54 Custom *' row would win the first() lookup below.
        DB::table('styles')->where('brewStyle', 'like', 'P54 Custom %')->delete();
        DB::table('styles')->where('brewStyle', 'like', 'P54 style %')->delete();
    }

    final protected function tearDown(): void
    {
        DB::table('styles')->whereIn('id', $this->styleIds ?: [0])->delete();

        parent::tearDown();
    }

    /** @param array<string, int|string|null> $overrides */
    private function insertStyle(array $overrides = []): int
    {
        $id = (int) DB::table('styles')->insertGetId([
            'brewStyleGroup' => '99',
            'brewStyleNum' => 'X',
            'brewStyle' => 'P54 style '.uniqid(),
            ...$overrides,
        ]);
        $this->styleIds[] = $id;

        return $id;
    }

    public function test_bjcp2025_set_lookup_uses_dual_version_predicate(): void
    {
        $this->remember('preferences');
        DB::table('preferences')->where('id', 1)->update(['prefsStyleSet' => 'BJCP2025']);

        $newOther = $this->insertStyle(['brewStyleVersion' => 'BJCP2025', 'brewStyleType' => 2]);
        $oldCore = $this->insertStyle(['brewStyleVersion' => 'BJCP2021', 'brewStyleType' => 1]);
        $oldOther = $this->insertStyle(['brewStyleVersion' => 'BJCP2021', 'brewStyleType' => 2]);
        $custom = $this->insertStyle(['brewStyleVersion' => 'BJCP2015', 'brewStyleOwn' => 'custom']);

        $listed = $this->get('/admin/styles')->assertOk()->viewData('styles')->pluck('id')->all();

        // Ledger pins #5/#6: dual-version coexistence + customs bypass filters.
        self::assertContains($newOther, $listed);
        self::assertContains($oldCore, $listed);
        self::assertNotContains($oldOther, $listed); // stale "other" row under old version
        self::assertContains($custom, $listed);
    }

    public function test_generic_set_lookup_is_version_or_custom(): void
    {
        $this->remember('preferences');
        DB::table('preferences')->where('id', 1)->update(['prefsStyleSet' => 'NWCiderCup']);

        $inSet = $this->insertStyle(['brewStyleVersion' => 'NWCiderCup']);
        $otherSet = $this->insertStyle(['brewStyleVersion' => 'BJCP2021']);
        $custom = $this->insertStyle(['brewStyleVersion' => 'AABC2022', 'brewStyleOwn' => 'custom']);

        $listed = $this->get('/admin/styles')->assertOk()->viewData('styles')->pluck('id')->all();

        self::assertContains($inSet, $listed);
        self::assertNotContains($otherSet, $listed);
        self::assertContains($custom, $listed); // (version = ? OR brewStyleOwn='custom')
    }

    public function test_bulk_update_writes_at_limit_and_selected_styles_json(): void
    {
        $this->remember('preferences');

        $a = $this->insertStyle(['brewStyleVersion' => 'BJCP2021', 'brewStyleType' => 1, 'brewStyleAtLimit' => null]);
        $b = $this->insertStyle(['brewStyleVersion' => 'BJCP2021', 'brewStyleType' => 1]);

        $rowA = (array) DB::table('styles')->find($a);

        $this->put('/admin/styles', [
            'id' => [$a, $b],
            'brewStyleActive'.$a => 'Y',
            'brewStyleAtLimit'.$a => '1',
            // $b unchecked → dropped from the selection map entirely.
        ])->assertRedirect('/admin/styles?msg=2');
        $styleA = (array) DB::table('styles')->find($a);
        $styleB = (array) DB::table('styles')->find($b);
        self::assertNotEmpty($styleA);
        self::assertNotEmpty($styleB);
        self::assertSame(1, (int) $styleA['brewStyleAtLimit']);
        self::assertNull($styleB['brewStyleAtLimit']);

        $selected = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsSelectedStyles'), true);
        self::assertSame(
            ['id' => $a, 'brewStyle' => $rowA['brewStyle'], 'brewStyleGroup' => '99', 'brewStyleNum' => 'X', 'brewStyleVersion' => 'BJCP2021', 'brewStyleType' => '1'],
            $selected[$a],
        );
        self::assertArrayNotHasKey($b, $selected);
    }

    /**
     * Pin-4 decision asserted through the UI path: posted category codes are
     * normalized on input — numeric single digits pad to '0X' (#2), leading
     * zeros collapse so '002' cannot produce a three-zero-width sort, alpha
     * identifiers stay whole (#3).
     */
    public function test_custom_style_store_normalizes_category_codes(): void
    {
        foreach ([['2', '02'], ['002', '02'], ['12', '12'], ['M1', 'M1']] as [$posted, $expected]) {
            $response = $this->post('/admin/styles', [
                'brewStyle' => 'P54 Custom '.$posted,
                'brewStyleGroup' => $posted,
                'brewStyleNum' => 'Z',
                'brewStyleType' => '1',
                'brewStyleActive' => 'Y',
            ]);
            $response->assertRedirect('/admin/styles?msg=9');

            $row = (array) DB::table('styles')
                ->where('brewStyle', 'P54 Custom '.$posted)
                ->where('brewStyleOwn', 'custom')
                ->first();
            self::assertNotEmpty($row);

            self::assertSame($expected, $row['brewStyleGroup']);
            // Version stamps from the active set; own is always custom.
            self::assertSame(TenantContext::load()->prefsStr('prefsStyleSet') ?? '', (string) $row['brewStyleVersion']);

            // Active custom styles join prefsSelectedStyles.
            $selected = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsSelectedStyles'), true);
            self::assertSame($expected, $selected[$row['id']]['brewStyleGroup']);
        }
    }

    public function test_custom_style_type_two_forces_strength_off(): void
    {
        $this->post('/admin/styles', [
            'brewStyle' => 'P54 Other Cider',
            'brewStyleGroup' => '77',
            'brewStyleNum' => 'Y',
            'brewStyleType' => '2',
            'brewStyleStrength' => '1',
            'brewStyleActive' => 'N',
        ]);

        $row = (array) DB::table('styles')->where('brewStyle', 'P54 Other Cider')->first();
        $this->styleIds[] = (int) $row['id'];
        self::assertSame('0', (string) $row['brewStyleStrength']); // type=2 quirk: strength forced off

        // Inactive styles do NOT join prefsSelectedStyles. The dump ships the
        // column NULL until styles are picked, and null means nothing selected.
        $raw = DB::table('preferences')->where('id', 1)->value('prefsSelectedStyles');
        $selected = $raw === null ? [] : (array) json_decode((string) $raw, true);
        self::assertArrayNotHasKey($row['id'], $selected);
    }

    public function test_edit_renames_style_and_cascades_into_brewing(): void
    {
        // Renaming is a custom-style operation; shipped bcoe styles are
        // read-only (the list hides Edit/Delete and the controller refuses).
        $styleId = $this->insertStyle(['brewStyleVersion' => 'BJCP2021', 'brewStyleType' => 1, 'brewStyleOwn' => 'custom']);

        $entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'P54 entry',
            'brewStyle' => 'P54 Old Name',
            'brewCategory' => '99',
            'brewCategorySort' => '99',
            'brewSubCategory' => 'X',
        ]);

        try {
            $this->put('/admin/styles/'.$styleId, [
                'brewStyleOld' => 'P54 Old Name',
                'brewStyle' => 'P54 New Name',
                'brewStyleGroup' => '99',
                'brewStyleNum' => 'X',
                'brewStyleType' => '1',
                'brewStyleActive' => 'Y',
            ])->assertRedirect('/admin/styles?msg=9');

            $renamed = (array) DB::table('styles')->find($styleId);
            $brewing = (array) DB::table('brewing')->find($entryId);
            self::assertNotEmpty($renamed);
            self::assertNotEmpty($brewing);
            self::assertSame('P54 New Name', $renamed['brewStyle']);
            self::assertSame('P54 New Name', $brewing['brewStyle']);
        } finally {
            DB::table('brewing')->delete($entryId);
        }
    }
}
