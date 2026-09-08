<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Judging-number regeneration (legacy regenerate.ajax.php →
 * generate_judging_numbers). Three methods wipe and reassign every
 * brewing.brewJudgingNumber.
 */
final class JudgingNumberRegenTest extends AdminScreensTestCase
{
    protected function tearDown(): void
    {
        DB::table('brewing')->where('brewName', 'like', 'JNREG-%')->delete();
        parent::tearDown();
    }

    public function test_default_assigns_six_digit_random_to_every_entry(): void
    {
        $ids = $this->seedEntries(3);
        $this->post('/admin/judging/regenerate-numbers', ['method' => 'default']);

        $nums = DB::table('brewing')->whereIn('id', $ids)->pluck('brewJudgingNumber');
        self::assertCount(3, $nums);
        foreach ($nums as $n) {
            self::assertMatchesRegularExpression('/^[1-9]{6}$/', (string) $n);
        }
        self::assertSame(3, $nums->unique()->count());
    }

    public function test_identical_sets_zero_padded_entry_id(): void
    {
        $ids = $this->seedEntries(2);
        $this->post('/admin/judging/regenerate-numbers', ['method' => 'identical']);

        foreach ($ids as $id) {
            self::assertSame(
                sprintf('%06s', $id),
                (string) DB::table('brewing')->where('id', $id)->value('brewJudgingNumber'),
            );
        }
    }

    public function test_legacy_assigns_per_category_sequence(): void
    {
        $ids = $this->seedEntries(2); // both category 21
        $this->post('/admin/judging/regenerate-numbers', ['method' => 'legacy']);

        $nums = DB::table('brewing')->whereIn('id', $ids)->pluck('brewJudgingNumber');
        foreach ($nums as $n) {
            self::assertMatchesRegularExpression('/^21-\d{3}$/', (string) $n);
        }

        // Corpus rows may precede the seeds in category 21 and tie-break
        // order is unspecified; the hard invariant is a gap-free sequence
        // across the whole category.
        $category = DB::table('brewing')->where('brewCategory', '21')
            ->whereNotNull('brewJudgingNumber')
            ->pluck('brewJudgingNumber')
            ->map(fn ($n): int => (int) substr((string) $n, 3))
            ->sort()->values();
        self::assertSame(
            range(1, $category->count()),
            $category->all(),
            'category 21 sequence must be contiguous from 21-001',
        );
    }

    public function test_unknown_method_rejected(): void
    {
        $this->post('/admin/judging/regenerate-numbers', ['method' => 'nope'])
            ->assertRedirect('/admin');
    }

    /** @return list<int> */
    private function seedEntries(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = (int) DB::table('brewing')->insertGetId([
                'brewName' => 'JNREG-'.$i,
                'brewCategory' => '21',
                'brewCategorySort' => '21',
                'brewSubCategory' => 'A',
                'brewJudgingNumber' => null,
            ]);
        }

        return $ids;
    }
}
