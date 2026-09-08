<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterization: entry category normalization from the posted style code
 * (P1.8, legacy includes/process/process_brewing.inc.php:286-292).
 *
 * The entry form POSTs brewStyle as "<category>-<subcategory>" (dash
 * separated; the UI composes categories without padding). Two columns are
 * derived:
 *
 *   brewCategory     = ltrim(category, "0")            leading zeros stripped
 *   brewCategorySort = ($cat < 10 && ctype_digit($cat))
 *                      ? "0".$cat : $cat               single digits padded
 *
 * The "< 10" comparison is PHP loose numeric-string comparison, which is why
 * alpha categories ("M1", "C1", "PR") fall through unpadded.
 */
final class StylesCategoryNormalizationTest extends TestCase
{
    #[DataProvider('providePostedStyles')]
    public function test_posted_style_normalization(string $posted, string $expectedCat, string $expectedSort): void
    {
        // Exact legacy expressions (:286-292).
        $style = explode('-', $posted);
        $styleTrim = ltrim($style[0], '0');
        if (($style[0] < 10) && (preg_match('/^[[:digit:]]+$/', $style[0]))) {
            $styleFix = '0'.$style[0];
        } else {
            $styleFix = $style[0];
        }

        self::assertSame($expectedCat, $styleTrim);
        self::assertSame($expectedSort, $styleFix);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function providePostedStyles(): iterable
    {
        yield 'single digit BJCP' => ['2-B', '2', '02'];
        yield 'double digit BJCP' => ['15-A', '15', '15'];
        yield 'mead category M1' => ['M1-A', 'M1', 'M1'];
        yield 'cider category C1' => ['C1-A', 'C1', 'C1'];
        yield 'pro-am PR' => ['PR-A', 'PR', 'PR'];
        yield 'subcategory with digit suffix' => ['28-C1', '28', '28'];
    }

    /**
     * Latent-input hazard: the UI never sends zero-padded categories, but a
     * hand-crafted or migrated POST of "02-B" yields category "2" AND sort
     * "002" (inconsistent width) because the pad branch prepends another
     * zero. Pinned so the port decides explicitly whether to keep it.
     */
    public function test_zero_padded_input_produces_triple_zero_sort(): void
    {
        $style = explode('-', '02-B');
        $styleTrim = ltrim($style[0], '0');
        $styleFix = ($style[0] < 10 && preg_match('/^[[:digit:]]+$/', $style[0]))
            ? '0'.$style[0]
            : $style[0];

        self::assertSame('2', $styleTrim);
        self::assertSame('002', $styleFix);
    }

    #[DataProvider('provideLooseCompareCategories')]
    public function test_alpha_categories_never_pad(string $category): void
    {
        // PHP 8 semantics: non-numeric strings compare against ints as
        // strings, so alpha categories never take the pad branch.
        $pads = ($category < 10) && (preg_match('/^[[:digit:]]+$/', $category));
        self::assertFalse($pads);
    }

    /** @return iterable<string, array{string}> */
    public static function provideLooseCompareCategories(): iterable
    {
        yield 'M1' => ['M1'];
        yield 'C1' => ['C1'];
        yield 'PR' => ['PR'];
        yield 'A' => ['A'];
    }
}
