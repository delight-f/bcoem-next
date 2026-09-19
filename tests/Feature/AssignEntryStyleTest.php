<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan bcoem:assign-entry-style`: the repair for entries written while
 * two styles shared one code (see the 2026_09_19_140000 migration). The row
 * cannot say which of the two an entry was — same code, same stored name — so
 * the operator names the entry and the style, and this moves the columns the
 * entry form would have written.
 */
final class AssignEntryStyleTest extends PublicSurfaceTestCase
{
    private const TARGET_NAME = 'NZ India Pale Ale (fixture)';

    private const WRONG_NAME = 'NZ Pale Ale (fixture)';

    private ?int $styleId = null;

    /** @var list<int> */
    private array $entries = [];

    /** @var mixed */
    private $originalStyleSet = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStyleSet = DB::table('preferences')->where('id', 1)->value('prefsStyleSet');
        DB::table('preferences')->where('id', 1)->update(['prefsStyleSet' => 'BA']);

        $this->styleId = (int) DB::table('styles')->insertGetId([
            'brewStyleGroup' => '06',
            'brewStyleNum' => '901',
            'brewStyle' => self::TARGET_NAME,
            'brewStyleCategory' => 'Other Origin Ales',
            'brewStyleVersion' => 'BA',
            'brewStyleOwn' => 'bcoe',
            'brewStyleType' => '1',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('styles')->whereIn('id', $this->styleId === null ? [0] : [$this->styleId])->delete();
        DB::table('brewing')->whereIn('id', $this->entries === [] ? [0] : $this->entries)->delete();
        DB::table('preferences')->where('id', 1)->update(['prefsStyleSet' => $this->originalStyleSet]);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEntry(array $overrides = []): int
    {
        $id = (int) DB::table('brewing')->insertGetId(array_merge([
            'brewName' => 'NZ fixture entry',
            'brewStyle' => self::WRONG_NAME,
            'brewCategory' => '6',
            'brewCategorySort' => '06',
            'brewSubCategory' => '182',
            'brewBrewerID' => '9001',
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => 0,
        ], $overrides));

        $this->entries[] = $id;

        return $id;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return (array) DB::table('brewing')->where('id', $id)->first();
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $extra
     */
    private function assign(string $style, array $ids, array $extra = []): int
    {
        return Artisan::call('bcoem:assign-entry-style', array_merge([
            'style' => $style,
            'entries' => array_map(strval(...), $ids),
        ], $extra));
    }

    public function test_a_dry_run_reports_the_move_without_writing(): void
    {
        $id = $this->makeEntry(['brewReceived' => 1]);

        $exit = $this->assign('06-901', [$id]);

        self::assertSame(0, $exit);
        $output = Artisan::output();
        self::assertStringContainsString('would change', $output);
        self::assertStringContainsString(self::WRONG_NAME.' (06-182) => '.self::TARGET_NAME.' (06-901)', $output);
        // A received entry is called out: its category grouping moves with it.
        self::assertStringContainsString('Already received: '.$id, $output);

        $row = $this->row($id);
        self::assertSame(self::WRONG_NAME, $row['brewStyle']);
        self::assertSame('182', $row['brewSubCategory']);
    }

    public function test_apply_moves_every_style_column_the_entry_form_writes(): void
    {
        $id = $this->makeEntry();

        $exit = $this->assign('06-901', [$id], ['--apply' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('1 entry corrected', Artisan::output());

        $row = $this->row($id);
        self::assertSame(self::TARGET_NAME, $row['brewStyle']);
        self::assertSame('6', $row['brewCategory']);
        self::assertSame('06', $row['brewCategorySort']);
        self::assertSame('901', $row['brewSubCategory']);
        self::assertSame('1', (string) $row['brewStyleType']);
    }

    public function test_the_set_option_reads_a_code_outside_the_sites_active_set(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsStyleSet' => 'BJCP2021']);
        $id = $this->makeEntry();

        self::assertSame(1, $this->assign('06-901', [$id]), 'the fixture style is not a BJCP2021 row');

        self::assertSame(0, $this->assign('06-901', [$id], ['--set' => 'BA', '--apply' => true]));
        self::assertSame(self::TARGET_NAME, $this->row($id)['brewStyle']);
    }

    public function test_a_second_apply_finds_nothing_left_to_do(): void
    {
        $id = $this->makeEntry();

        $this->assign('06-901', [$id], ['--apply' => true]);
        $exit = $this->assign('06-901', [$id], ['--apply' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Nothing to do', Artisan::output());
    }

    public function test_an_unknown_entry_writes_nothing(): void
    {
        $id = $this->makeEntry();

        $exit = $this->assign('06-901', [$id, 999999], ['--apply' => true]);

        // Artisan::output() drains its buffer, so read it once.
        $output = Artisan::output();
        self::assertSame(1, $exit);
        self::assertStringContainsString('No such entry: 999999', $output);
        self::assertStringContainsString('Nothing written', $output);
        self::assertSame(self::WRONG_NAME, $this->row($id)['brewStyle']);
    }

    public function test_a_code_outside_the_catalog_is_refused(): void
    {
        $id = $this->makeEntry();

        $exit = $this->assign('06-999', [$id], ['--apply' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString("No style '06-999' in the BA set", Artisan::output());
        self::assertSame(self::WRONG_NAME, $this->row($id)['brewStyle']);
    }
}
