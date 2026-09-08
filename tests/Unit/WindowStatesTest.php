<?php

declare(strict_types=1);

namespace BCOEM\Tests\Unit;

use App\Support\Tenant\WindowState;
use App\Support\Tenant\WindowStates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the open_or_closed primitive against the characterization contract
 * (ledger contest-info.md #1-#3, #5; mirrors legacy ContestInfoTest).
 */
final class WindowStatesTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int|null, 2: int|null, 3: WindowState}> */
    public static function provideWindows(): array
    {
        $open = 1000;
        $close = 2000;

        return [
            'before open' => [500, $open, $close, WindowState::Before],
            'at open instant' => [1000, $open, $close, WindowState::Open],
            'inside window' => [1500, $open, $close, WindowState::Open],
            'just before close' => [1999, $open, $close, WindowState::Open],
            'at close instant reports not-yet-open (W1)' => [2000, $open, $close, WindowState::Before],
            'after close' => [2001, $open, $close, WindowState::After],
            'null open' => [1500, null, $close, WindowState::Before],
            'null close' => [1500, $open, null, WindowState::Before],
            'both null' => [1500, null, null, WindowState::Before],
        ];
    }

    #[DataProvider('provideWindows')]
    public function test_window_states_match_legacy_primitive(int $now, ?int $open, ?int $close, WindowState $expected): void
    {
        self::assertSame($expected, WindowStates::openOrClosed($now, $open, $close));
    }

    public function test_empty_limit_never_closes(): void
    {
        self::assertFalse(WindowStates::limitReached(999, '', WindowState::Open));
        self::assertFalse(WindowStates::limitReached(999, null, WindowState::Open));
    }

    public function test_limit_reached_only_bites_while_open(): void
    {
        self::assertTrue(WindowStates::limitReached(10, 10, WindowState::Open));
        self::assertFalse(WindowStates::limitReached(10, 10, WindowState::Before));
        self::assertFalse(WindowStates::limitReached(9, 10, WindowState::Open));
    }

    public function test_zero_cap_closes_while_open_like_legacy(): void
    {
        // Legacy open_limit treats a literal 0 limit as a real cap: any total
        // meets it, so an open window with cap 0 is closed. Pinned by
        // RegistrationRulesLimitTest upstream.
        self::assertTrue(WindowStates::limitReached(0, 0, WindowState::Open));
    }
}
