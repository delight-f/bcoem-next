<?php

declare(strict_types=1);

namespace App\Support\Tenant;

/**
 * The single window primitive behind every competition date gate.
 *
 * Semantics are characterization-pinned (ledger contest-info.md #1-#3,
 * ContestInfoTest):
 *
 *   0 = not yet open   (before $open, OR either date missing/unparsable)
 *   1 = open           (from $open inclusive, until but excluding $close)
 *   2 = closed         (strictly after $close)
 *
 * Deliberate weirdness preserved (W1): at $now == $close exactly the result
 * is 0, NOT 2 — legacy compares neither "< close" nor "> close" on that
 * instant. Do not "fix" without an approved deviation record.
 */
final class WindowStates
{
    public static function openOrClosed(int $now, ?int $open, ?int $close): WindowState
    {
        if ($open === null || $close === null) {
            return WindowState::Before;
        }

        if ($now < $open) {
            return WindowState::Before;
        }

        if ($now === $close) {
            return WindowState::Before; // W1: the close-instant gap
        }

        if ($now < $close) {
            return WindowState::Open;
        }

        return WindowState::After;
    }

    /**
     * Capacity caps only bite while the window is otherwise open.
     *
     * Faithful to legacy open_limit(): empty (''/null) means "no limit";
     * anything else — including a literal 0 — compares against the total,
     * so a zero cap closes the window whenever it is open. That edge is
     * pinned by RegistrationRulesLimitTest; do not second-guess it here.
     */
    public static function limitReached(int $total, int|string|null $limit, WindowState $window): bool
    {
        if ($limit === null || $limit === '') {
            return false;
        }

        return $total >= (int) $limit && $window === WindowState::Open;
    }
}
