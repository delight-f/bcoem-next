<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterization: registration eligibility limits (P1.2).
 *
 * Legacy source: lib/common.lib.php limit_subcategory() (:3609-3681) and
 * includes/process/process_brewing.inc.php (:42-97). The function is
 * session/DB-coupled, so this pins (a) the category-normalization rules of
 * the limit check and (b) the post-lookup limit DECISION TABLE as exact
 * expected outcomes with source line references. The DB-count shapes are
 * pinned end-to-end in RegistrationRulesDbTest (CI).
 *
 * Overall gating model (process_brewing.inc.php):
 *   - prefsUserEntryLimit counts ALL of a user's brewing rows (COUNT(*),
 *     unconfirmed included) — at-limit add redirects ?section=list&msg=8
 *   - limit_subcategory breach redirects &msg=9
 *   - comp_entry_limit/comp_paid_entry_limit disabled ⇒ always allowed
 *   - admins (userLevel<=1) bypass everything
 */
final class RegistrationRulesLimitTest extends TestCase
{
    #[DataProvider('provideLimitCategoryNormalization')]
    public function test_limit_check_category_normalization(string $category, string $expectedStyleNum): void
    {
        // common.lib.php:3630-3634
        //   preg_match("/[C,M,P,L]/", $style_break[0]) keeps category as-is
        //   elseif ($style_break[0] <= 9) sprintf('%02d', ...)
        //   else unchanged
        $styleNum = null;
        if (preg_match('/[C,M,P,L]/', $category)) {
            $styleNum = $category;
        } elseif ($category <= 9) {
            $styleNum = sprintf('%02d', $category);
        } else {
            $styleNum = $category;
        }

        self::assertSame($expectedStyleNum, $styleNum);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideLimitCategoryNormalization(): iterable
    {
        yield 'single digit padded' => ['2', '02'];
        yield 'double digit unchanged' => ['28', '28'];
        yield 'mead M1 kept whole' => ['M1', 'M1'];
        yield 'cider C kept' => ['C', 'C'];
        yield 'provisional P kept' => ['P', 'P'];
        yield 'light L kept' => ['L', 'L'];
    }

    public function test_character_class_matches_literal_comma_and_is_unanchored(): void
    {
        // The legacy pattern /[C,M,P,L]/ treats the comma as a LITERAL
        // alternative and is UNANCHORED, so any category CONTAINING one of
        // C,M,P,L,(,) anywhere matches — including "X,C" or hypothetical
        // future multi-char categories. Pinned verbatim; do not "fix" while
        // porting without a ledger deviation entry.
        self::assertSame(1, preg_match('/[C,M,P,L]/', 'X,C'));
        self::assertSame(1, preg_match('/[C,M,P,L]/', 'CM'));
        self::assertSame(0, preg_match('/[C,M,P,L]/', '28'));
    }

    #[DataProvider('provideLimitDecision')]
    public function test_subcategory_limit_decision_table(
        int $count,
        int $prefNum,
        bool $styleInExceptions,
        string $exceptionNum,
        bool $expectedReached,
    ): void {
        // Decision tail of limit_subcategory (:3671-3680):
        //   reached = count >= prefNum
        //   if reached && style excepted:
        //       reached = (!empty(exceptionNum) && count >= exceptionNum)
        //     i.e. an excepted style with EMPTY exception limit is unlimited.
        $reached = $count >= $prefNum;
        if ($reached && $styleInExceptions) {
            $reached = ($exceptionNum !== '') && ($count >= (int) $exceptionNum);
        }

        self::assertSame($expectedReached, $reached);
    }

    /** @return iterable<string, array{int, int, bool, string, bool}> */
    public static function provideLimitDecision(): iterable
    {
        $in = true;
        $out = false;

        yield 'below limit ok' => [1, 2, $out, '', false];
        yield 'at limit blocked' => [2, 2, $out, '', true];
        yield 'over limit blocked' => [5, 2, $out, '', true];
        yield 'excepted with higher cap still allows below it' => [2, 2, $in, '4', false];
        yield 'excepted at exception cap blocked' => [4, 2, $in, '4', true];
        yield 'excepted empty cap is unlimited' => [99, 2, $in, '', false];
        yield 'not excepted ignores exception cap' => [2, 2, $out, '99', true];
    }
}
