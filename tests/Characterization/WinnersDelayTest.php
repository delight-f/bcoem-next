<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterization: winners reveal gating (P1.7).
 *
 * Vendored lib/common.lib.php judging_winner_display() (:3491): strict
 * `time() > $display_date` — at exactly the reveal second winners are NOT
 * yet visible (same boundary family as the W1 window quirk in the
 * contest-info ledger).
 */
final class WinnersDelayTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (! defined('LIB')) {
            define('LIB', dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'legacy'.DIRECTORY_SEPARATOR);
        }
        require_once LIB.'common.lib.php';
    }

    #[DataProvider('provideDelays')]
    public function test_reveal_gating_is_strictly_after_timestamp(int $offsetSeconds, bool $expectedVisible): void
    {
        $displayDate = time() + $offsetSeconds;

        self::assertSame($expectedVisible, judging_winner_display($displayDate));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function provideDelays(): iterable
    {
        yield 'one second ago visible' => [-1, true];
        yield 'past timestamp visible' => [-3600, true];
        yield 'future timestamp hidden' => [3600, false];
        yield 'one second in future still hidden' => [1, false];
        // Offset 0 (displayDate === time()) => NOT visible under strict >.
        // Exact boundary cannot be frozen here; pinned as contract comment.
    }

    public function test_zero_delay_shows_winners_immediately(): void
    {
        // prefsWinnerDelay = '0' (or empty) means no delay: unix epoch is
        // always in the past.
        self::assertTrue(judging_winner_display('0'));
    }
}
