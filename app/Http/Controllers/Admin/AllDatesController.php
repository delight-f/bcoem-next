<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * All competition-related dates (spec §7 P5.4) — port of
 * admin/all_dates.admin.php + process_dates.inc.php (action=dates).
 *
 * One form writes three tables (legacy order):
 *  1. contest_info id=1 — the thirteen date columns as UTC epochs parsed in
 *     the tenant's tz; contestAwardsLocTime mirrors contestAwardsLocDate;
 *  2. judging_preferences id=1 — jPrefsJudgingOpen/Closed, with the legacy
 *     fallbacks: an empty open date takes the earliest judging-session
 *     date; an empty close date takes the latest session date, else the
 *     earliest + 14 days;
 *  3. preferences id=1 — prefsWinnerDelay epoch (non-empty ⇒ 'Y') +
 *     prefsDisplayWinners.
 *
 * The inline per-session date editors (POST id[] + judgingDate{id}) are part
 * of the same form in legacy; they update judging_locations rows here too.
 */
final class AllDatesController extends Controller
{
    private const CONTEST_DATES = [
        'contestRegistrationOpen',
        'contestRegistrationDeadline',
        'contestEntryOpen',
        'contestEntryDeadline',
        'contestEntryEditDeadline',
        'contestJudgeOpen',
        'contestJudgeDeadline',
        'contestDropoffOpen',
        'contestDropoffDeadline',
        'contestShippingOpen',
        'contestShippingDeadline',
        'contestAwardsLocDate',
    ];

    public function edit(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $tz = $ctx->prefsStr('prefsTimeZone');
        $df = $ctx->prefsStr('prefsDateFormat');
        $tf = $ctx->prefsStr('prefsTimeFormat');

        $sessions = DB::table('judging_locations')->whereIn('judgingLocType', [0, 1])
            ->orderBy('judgingDate')->orderBy('judgingLocName')->get();
        $nonJudging = DB::table('judging_locations')->where('judgingLocType', 2)
            ->orderBy('judgingDate')->orderBy('judgingLocName')->get();

        // Legacy placeholder "current_date current_time" (getTimeZoneDateTime
        // 'system' date + 'time-gmt') — e.g. "2026-08-27 09:20, AEST".
        $now = time();
        $currentDateTime = DateFmt::dateTime($now, $tz, $df, $tf, 'system', true) ?? '';
        [$currentDate, $currentTime] = $currentDateTime !== '' ? explode(' ', $currentDateTime, 2) : ['', ''];

        $prefsEval = (int) ($ctx->prefsStr('prefsEval') ?: 0) === 1;

        [$judgingOpenDate, $judgingCloseDate, $suggestedOpen, $suggestedClose] =
            $prefsEval ? $this->judgingWindow($ctx) : ['', '', false, false];

        return view('admin.all-dates', [
            'ctx' => $ctx,
            'contest' => (array) DB::table('contest_info')->where('id', 1)->first(),
            'judging' => (array) DB::table('judging_preferences')->where('id', 1)->first(),
            'sessions' => $sessions,
            'nonJudging' => $nonJudging,
            'prefsEval' => $prefsEval,
            'currentDate' => $currentDate,
            'currentTime' => $currentTime,
            'judgingOpenDate' => $judgingOpenDate,
            'judgingCloseDate' => $judgingCloseDate,
            'suggestedOpen' => $suggestedOpen,
            'suggestedClose' => $suggestedClose,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $tz = TenantContext::load()->prefsStr('prefsTimeZone');
        $dateKeys = [...self::CONTEST_DATES, 'jPrefsJudgingOpen', 'jPrefsJudgingClosed', 'prefsWinnerDelay'];
        $rules = array_combine(
            $dateKeys,
            array_map(fn (): array => ['nullable', 'string'], $dateKeys),
        );
        foreach ($rules as $key => $rule) {
            $rules[$key] = [...$rule, function (string $attribute, mixed $value, \Closure $fail) use ($tz): void {
                if (is_string($value) && $value !== '' && $this->toUtcEpoch($value, $tz) === null) {
                    $fail("The {$attribute} field is not a valid date/time.");
                }
            }];
        }

        $data = $request->validate($rules);

        // 1. contest_info dates (awards time mirrors the awards date).
        $contestUpdate = [];
        foreach (self::CONTEST_DATES as $key) {
            $epoch = $this->toUtcEpoch((string) ($data[$key] ?? ''), $tz);
            $contestUpdate[$key] = $epoch;
            if ($key === 'contestAwardsLocDate') {
                $contestUpdate['contestAwardsLocTime'] = $epoch;
            }
        }
        DB::table('contest_info')->where('id', 1)->update($contestUpdate);

        // 2. Judging window with session-date fallbacks.
        [$earliest, $latest] = $this->sessionRange();
        $open = (string) ($data['jPrefsJudgingOpen'] ?? '') !== ''
            ? $this->toUtcEpoch((string) $data['jPrefsJudgingOpen'], $tz)
            : $earliest;

        if ((string) ($data['jPrefsJudgingClosed'] ?? '') !== '') {
            $closed = $this->toUtcEpoch((string) $data['jPrefsJudgingClosed'], $tz);
        } elseif ($latest !== null) {
            $closed = $latest;
        } else {
            $closed = $earliest !== null ? $earliest + 1209600 : null;
        }

        DB::table('judging_preferences')->where('id', 1)->update([
            'jPrefsJudgingOpen' => $open,
            'jPrefsJudgingClosed' => $closed,
        ]);

        // 3. Winners publish delay.
        $winnerDelayRaw = (string) ($data['prefsWinnerDelay'] ?? '');
        DB::table('preferences')->where('id', 1)->update([
            'prefsWinnerDelay' => $winnerDelayRaw !== '' ? $this->toUtcEpoch($winnerDelayRaw, $tz) : null,
            'prefsDisplayWinners' => $winnerDelayRaw !== '' ? 'Y' : 'N',
        ]);

        // 4. Inline session date edits (id[] + judgingDate{id}/judgingDateEnd{id}).
        foreach ((array) $request->input('id', []) as $sessionId) {
            $sessionOpen = $this->toUtcEpoch((string) $request->input('judgingDate'.$sessionId, ''), $tz);
            $sessionClose = $this->toUtcEpoch((string) $request->input('judgingDateEnd'.$sessionId, ''), $tz);
            DB::table('judging_locations')->where('id', (int) $sessionId)->update([
                'judgingDate' => $sessionOpen,
                'judgingDateEnd' => $sessionClose,
            ]);
        }

        return redirect('/admin/dates?msg=2');
    }

    /** Earliest/latest epoch across all judging sessions' start and end dates. */
    /** @return array{int|null, int|null} [earliest, latest] epochs */
    private function sessionRange(): array
    {
        $dates = [];
        foreach (DB::table('judging_locations')
            ->whereIn('judgingLocType', [0, 1])
            ->get(['judgingDate', 'judgingDateEnd']) as $row) {
            foreach (['judgingDate', 'judgingDateEnd'] as $col) {
                if ($row->{$col} !== null && $row->{$col} !== '') {
                    $dates[] = (int) $row->{$col};
                }
            }
        }

        if ($dates === []) {
            return [null, null];
        }

        return [min($dates), max($dates)];
    }

    /**
     * Port of all_dates.admin.php:17-140 — the Judging Open/Close display
     * strings + suggested flags. Rendered only when prefsEval==1.
     *
     * @return array{?string, ?string, bool, bool} [$openDisplay, $closeDisplay, $suggestedOpen, $suggestedClose]
     */
    private function judgingWindow(TenantContext $ctx): array
    {
        $tz = $ctx->prefsStr('prefsTimeZone');
        $df = $ctx->prefsStr('prefsDateFormat');
        $tf = $ctx->prefsStr('prefsTimeFormat');
        $fmt = fn (int $epoch): ?string => DateFmt::dateTime($epoch, $tz, $df, $tf, 'system', false);
        $now = time();

        $judgingDates = [];
        $judgingEarliest = '';
        $judgingLatest = '';

        foreach (DB::table('judging_locations')->where('judgingLocType', '<=', 1)
            ->get(['judgingDate', 'judgingDateEnd']) as $row) {
            if (! empty($row->judgingDate)) {
                $judgingDates[] = (int) $row->judgingDate;
            }
            if (! empty($row->judgingDateEnd)) {
                $judgingDates[] = (int) $row->judgingDateEnd;
            }
        }
        if ($judgingDates !== []) {
            $judgingEarliest = min($judgingDates);
            if (max($judgingDates) > $judgingEarliest) {
                $judgingLatest = max($judgingDates);
            }
        }

        $openRaw = $ctx->judgingStr('jPrefsJudgingOpen');
        $closeRaw = $ctx->judgingStr('jPrefsJudgingClosed');
        $openSet = $openRaw !== null && $openRaw !== '';
        $closeSet = $closeRaw !== null && $closeRaw !== '';

        $suggestedOpen = false;
        $suggestedClose = false;

        if ($openSet) {
            $jOpen = (int) $openRaw;
            if ($judgingEarliest !== '' && $judgingEarliest < (int) $openRaw) {
                $jOpen = $judgingEarliest;
            }
            $judgingOpenDate = $fmt($jOpen);
        } else {
            $suggestedOpenDate = $judgingEarliest !== '' ? $judgingEarliest : (int) (round($now / (15 * 60)) * (15 * 60));
            $judgingOpenDate = $fmt($suggestedOpenDate);
            $suggestedOpen = true;
        }

        if ($closeSet) {
            if ($openSet) {
                if ((int) $closeRaw > (int) $openRaw) {
                    $jClosed = (int) $closeRaw;
                    if ($judgingLatest !== '' && $judgingLatest > (int) $closeRaw) {
                        $jClosed = $judgingLatest;
                    }
                } else {
                    if ($judgingEarliest === '') {
                        $jClosed = (int) $openRaw + 86400;
                    } else {
                        $jClosed = ((int) $openRaw >= $judgingEarliest) ? (int) $openRaw + 86400 : $judgingEarliest + 86400;
                    }
                }
            } else {
                if ($judgingLatest === '') {
                    $jClosed = $judgingEarliest === '' ? $now + 86400 : $judgingEarliest + 86400;
                } else {
                    $jClosed = $judgingLatest;
                }
            }
            $judgingCloseDate = $fmt($jClosed);
        } else {
            if ($judgingLatest !== '') {
                $suggestedCloseDate = $judgingLatest;
            } else {
                if ($openSet) {
                    $suggestedCloseDate = (int) $openRaw + 86400;
                } elseif ($judgingEarliest !== '') {
                    $suggestedCloseDate = $judgingEarliest + 86400;
                } else {
                    $suggestedCloseDate = (int) (round(($now + 86400) / (15 * 60)) * (15 * 60));
                }
            }
            $judgingCloseDate = $fmt($suggestedCloseDate);
            $suggestedClose = true;
        }

        return [$judgingOpenDate ?? '', $judgingCloseDate ?? '', $suggestedOpen, $suggestedClose];
    }

    private function toUtcEpoch(?string $value, ?string $tzOffset): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone(DateFmt::tz($tzOffset)))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
