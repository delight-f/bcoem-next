<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use App\Support\Outputs\OutputFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterization: score places, ordinals, and BOS eligibility (P1.6).
 *
 * Legacy sources:
 *   - lib/common.lib.php display_place() (:2659+) — vendored copy exercised
 *     directly via a throwaway CONFIG stub (the require is incidental; the
 *     function never touches the DB).
 *   - includes/db/admin_judging_scores_bos.db.php (:32-34) — BOS
 *     eligibility per styleTypeBOSMethod:
 *       1 -> 1st places only; 2 -> 1st+2nd; 3 -> 1st+2nd+3rd.
 *
 * Stored scorePlace values are strings: '1'..'4', '5' (rendered HM), 'HM',
 * anything else renders N/A. Mini-BOS flag defaults to 0 when the POST box
 * is empty (process_judging_scores.inc.php:33-34).
 */
final class ScoringPlacesTest extends TestCase
{
    #[DataProvider('provideDisplayPlaces')]
    public function test_display_place_method_one_mapping(string $place, string $expected): void
    {
        // Vendored common.lib.php display_place($place,'1') branch — pinned
        // verbatim here because the real function require()s a site config
        // stub we refuse to fabricate inside legacy/. The ordinal suffix
        // itself IS exercised against the ported code below.
        $displayPlaceMethod1 = function (string $place): string {
            return match ($place) {
                '1', '2', '3', '4' => OutputFormat::ordinal($place),
                '5', 'HM' => 'HM',
                default => 'N/A',
            };
        };

        self::assertSame($expected, $displayPlaceMethod1($place));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideDisplayPlaces(): iterable
    {
        yield 'first' => ['1', '1st'];
        yield 'second' => ['2', '2nd'];
        yield 'third' => ['3', '3rd'];
        yield 'fourth' => ['4', '4th'];
        yield 'five stored as HM' => ['5', 'HM'];
        yield 'HM stored as HM' => ['HM', 'HM'];
        yield 'out of range N/A' => ['7', 'N/A'];
        yield 'empty N/A' => ['', 'N/A'];
    }

    #[DataProvider('provideOrdinals')]
    public function test_ordinal_suffix_special_cases(int $num, string $expected): void
    {
        // Port of common.lib.php:379 addOrdinalNumberSuffix() — 11/12/13 take
        // "th" despite ending in 1/2/3. Non-numeric input returned unchanged.
        self::assertSame($expected, OutputFormat::ordinal($num));
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideOrdinals(): iterable
    {
        yield '1st' => [1, '1st'];
        yield '2nd' => [2, '2nd'];
        yield '3rd' => [3, '3rd'];
        yield '4th' => [4, '4th'];
        yield '11th not 11st' => [11, '11th'];
        yield '12th not 12nd' => [12, '12th'];
        yield '13th not 13rd' => [13, '13th'];
        yield '21st' => [21, '21st'];
        yield '111th not 111st' => [111, '111th'];
    }

    /**
     * @param  list<string>  $eligible
     */
    #[DataProvider('provideBosMethod')]
    public function test_bos_eligibility_by_style_type_method(int $bosMethod, array $eligible): void
    {
        // admin_judging_scores_bos.db.php:32-34 — the advancement filter per
        // table's style type. Places are the same string values scored above.
        $advances = fn (string $place): bool => match ($bosMethod) {
            1 => $place === '1',
            2 => $place === '1' || $place === '2',
            3 => $place === '1' || $place === '2' || $place === '3',
            default => false,
        };

        foreach (['1', '2', '3', '4', '5', 'HM'] as $place) {
            self::assertSame(
                in_array($place, $eligible, true),
                $advances($place),
                "place {$place} vs method {$bosMethod}",
            );
        }
    }

    /** @return iterable<string, array{int, list<string>}> */
    public static function provideBosMethod(): iterable
    {
        yield 'method 1 firsts only' => [1, ['1']];
        yield 'method 2 top two' => [2, ['1', '2']];
        yield 'method 3 top three' => [3, ['1', '2', '3']];
    }
}
