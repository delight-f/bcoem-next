<?php

declare(strict_types=1);

namespace App\Support\Tenant;

use Illuminate\Support\Facades\DB;

/**
 * Derives every date/cap gate the public surface renders, faithful to the
 * characterization ledgers:
 *
 * - W2: drop-off/shipping windows default OPEN when either date is blank.
 * - Once any judging session has started (now > earliest session date),
 *   entry + registration windows report closed regardless of their dates.
 * - Comp-wide entry/paid caps close the entry window while open
 *   (open_limit semantics — a literal 0 cap closes immediately).
 * - Judge/steward registration caps come from judging_preferences.
 * - disable_pay requires ALL of registration/shipping/dropoff/entry/pay
 *   to be state 2.
 */
final class Windows
{
    private function __construct(
        public readonly WindowState $registration,
        public readonly WindowState $entry,
        public readonly WindowState $judge,
        public readonly WindowState $dropoff,
        public readonly WindowState $shipping,
        public readonly ?WindowState $pay,
        public readonly bool $judgeCapReached,
        public readonly bool $stewardCapReached,
        public readonly bool $compEntryLimitReached,
        /** 0 = every judging session is in the past (or none scheduled). */
        public readonly int $futureJudgingSessions,
        public readonly ?int $firstJudgingDate,
        public readonly ?int $lastJudgingDate,
    ) {}

    /**
     * Legacy $judging_start tri-state over the session schedule:
     * 0 = not started; 1 = in progress (a session runs until its last date
     * + a 6-hour grace window); 2 = concluded.
     */
    public function judgingState(int $now, int|string|null $awardsEpoch): int
    {
        if ($this->firstJudgingDate === null || $now <= $this->firstJudgingDate) {
            return 0;
        }

        if ($this->lastJudgingDate !== null) {
            return $now < ($this->lastJudgingDate + 21600) ? 1 : 2;
        }

        if ($awardsEpoch !== null && $awardsEpoch !== 0) {
            return $now < $awardsEpoch ? 1 : 2;
        }

        // No end data at all: assume a single session fits in six hours.
        return $now < ($this->firstJudgingDate + 21600) ? 1 : 2;
    }

    public static function derive(TenantContext $ctx, int $now): self
    {
        $registration = WindowStates::openOrClosed(
            $now, $ctx->contestEpoch('contestRegistrationOpen'), $ctx->contestEpoch('contestRegistrationDeadline'),
        );
        $entry = WindowStates::openOrClosed(
            $now, $ctx->contestEpoch('contestEntryOpen'), $ctx->contestEpoch('contestEntryDeadline'),
        );
        $judge = WindowStates::openOrClosed(
            $now, $ctx->contestEpoch('contestJudgeOpen'), $ctx->contestEpoch('contestJudgeDeadline'),
        );

        // W2: blank windows default OPEN.
        $dropoffOpen = $ctx->contestEpoch('contestDropoffOpen');
        $dropoffClose = $ctx->contestEpoch('contestDropoffDeadline');
        $dropoff = ($dropoffOpen !== null && $dropoffClose !== null)
            ? WindowStates::openOrClosed($now, $dropoffOpen, $dropoffClose)
            : WindowState::Open;

        $shippingOpen = $ctx->contestEpoch('contestShippingOpen');
        $shippingClose = $ctx->contestEpoch('contestShippingDeadline');
        $shipping = ($shippingOpen !== null && $shippingClose !== null)
            ? WindowStates::openOrClosed($now, $shippingOpen, $shippingClose)
            : WindowState::Open;

        // Judging schedule drives the started-override, pay window, and the
        // "judging past" result gate.
        $sessions = DB::table('judging_locations')
            ->where('judgingLocType', '<', 2)
            ->whereNotNull('judgingDate')
            ->where('judgingDate', '!=', '')
            ->orderBy('judgingDate')
            ->get(['judgingDate', 'judgingDateEnd']);

        $dates = [];
        foreach ($sessions as $s) {
            foreach (['judgingDate', 'judgingDateEnd'] as $col) {
                $v = $s->{$col};
                if ($v !== null && $v !== '' && is_numeric($v)) {
                    $dates[] = (int) $v;
                }
            }
        }

        $first = $dates === [] ? null : min($dates);
        $last = $dates === [] ? null : max($dates);
        $judgingStarted = $first !== null && $now > $first;

        if ($judgingStarted) {
            $entry = WindowState::After;
            $registration = WindowState::After;
        }

        $pay = $last !== null
            ? WindowStates::openOrClosed($now, $ctx->contestEpoch('contestEntryOpen'), $last)
            : null;

        // Comp-wide caps (all brewing rows count, confirmed or not —
        // registration-rules ledger #1).
        $totalEntries = (int) DB::table('brewing')->count();
        $paidEntries = (int) DB::table('brewing')->where('brewPaid', 1)->count();

        $entryLimit = $ctx->prefsStr('prefsEntryLimit');
        $paidLimit = $ctx->prefsStr('prefsEntryLimitPaid');

        $entryLimitReached = WindowStates::limitReached($totalEntries, $entryLimit, $entry);
        $paidLimitReached = WindowStates::limitReached($paidEntries, $paidLimit, $entry);

        if ($entryLimitReached || $paidLimitReached) {
            $entry = WindowState::After;
        }

        // Judge/steward caps (judging_preferences), same open_limit shape.
        $judgeCap = WindowStates::limitReached(
            (int) DB::table('brewer')->where('brewerJudge', 'Y')->count(),
            $ctx->judgingStr('jPrefsCapJudges'), $judge,
        );
        $stewardCap = WindowStates::limitReached(
            (int) DB::table('brewer')->where('brewerSteward', 'Y')->count(),
            $ctx->judgingStr('jPrefsCapStewards'), $judge,
        );
        if ($judgeCap) {
            $judge = WindowState::After;
        }

        return new self(
            registration: $registration,
            entry: $entry,
            judge: $judge,
            dropoff: $dropoff,
            shipping: $shipping,
            pay: $pay,
            judgeCapReached: $judgeCap,
            stewardCapReached: $stewardCap,
            compEntryLimitReached: $entryLimitReached || $paidLimitReached,
            futureJudgingSessions: self::futureSessions($now),
            firstJudgingDate: $first,
            lastJudgingDate: $last,
        );
    }

    private static function futureSessions(int $now): int
    {
        return (int) DB::table('judging_locations')->where('judgingDate', '>=', $now)->count();
    }

    /** Legacy $disable_pay: TRUE only when all five windows are fully past. */
    public function disablePay(): bool
    {
        return $this->registration === WindowState::After
            && $this->shipping === WindowState::After
            && $this->dropoff === WindowState::After
            && $this->entry === WindowState::After
            && $this->pay === WindowState::After;
    }
}
