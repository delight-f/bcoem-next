<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Judging/competition-organization preferences (spec §6 P4.1, ticket 01).
 * Legacy: admin/judging_preferences.admin.php +
 * process_judging_preferences.inc.php.
 *
 * Writes (exact row/column parity):
 *  - `judging_preferences` id=1: jPrefsQueued/FlightEntries/MaxBOS/Rounds/
 *    BottleNum/CapStewards/CapJudges — caps keep a posted 0 ("no limit"
 *    vs "unset" is NULL; legacy special-cased the scrubbed zero);
 *  - `preferences` id=1: prefsEval + prefsDisplaySpecial — the same rows
 *    the public surface reads via PreferencesRepository/TenantContext;
 *  - only when electronic scoresheets are enabled: jPrefsScoresheet,
 *    jPrefsMinWords, jPrefsScoreDispMax and the judging open/close epochs,
 *    clamped to the earliest/latest defined session dates exactly like
 *    process_judging_preferences.inc.php.
 */
final class JudgingPreferenceController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();

        return view('judging.config.preferences', [
            'ctx' => $ctx,
            'judging' => $ctx->judging,
            'prefsEval' => $ctx->prefsStr('prefsEval'),
            'prefsDisplaySpecial' => $ctx->prefsStr('prefsDisplaySpecial'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'jPrefsQueued' => ['required', 'in:Y,N'],
            'jPrefsBottleNum' => ['required', 'integer', 'min:1', 'max:15'],
            'jPrefsFlightEntries' => ['required', 'integer', 'min:1', 'max:50'],
            'jPrefsMaxBOS' => ['required', 'integer', 'min:1', 'max:4'],
            'jPrefsRounds' => ['required', 'integer', 'min:1', 'max:5'],
            // A posted 0 must survive (legacy: "allow for 0"); blank → NULL.
            'jPrefsCapJudges' => ['nullable', 'integer', 'min:0'],
            'jPrefsCapStewards' => ['nullable', 'integer', 'min:0'],
            'prefsEval' => ['required', 'in:0,1'],
            'prefsDisplaySpecial' => ['required', 'in:J,E'],
        ]);

        $evalEnabled = $data['prefsEval'] === '1';
        $extra = [];
        if ($evalEnabled) {
            $extra = $request->validate([
                'jPrefsScoresheet' => ['required', 'integer', 'min:1', 'max:4'],
                'jPrefsMinWords' => ['nullable', 'integer', 'min:0'],
                'jPrefsScoreDispMax' => ['nullable', 'integer', 'min:1', 'max:10'],
                'jPrefsJudgingOpen' => ['nullable', 'string'],
                'jPrefsJudgingClosed' => ['nullable', 'string'],
            ]);
        }

        $oldMaxBos = (int) TenantContext::load()->judgingStr('jPrefsMaxBOS');

        DB::table('judging_preferences')->where('id', 1)->update([
            'jPrefsQueued' => $data['jPrefsQueued'],
            'jPrefsFlightEntries' => (int) $data['jPrefsFlightEntries'],
            'jPrefsMaxBOS' => (int) $data['jPrefsMaxBOS'],
            'jPrefsBottleNum' => (int) $data['jPrefsBottleNum'],
            'jPrefsCapStewards' => self::cap($data['jPrefsCapStewards'] ?? null),
            'jPrefsCapJudges' => self::cap($data['jPrefsCapJudges'] ?? null),
            ...($evalEnabled ? [
                'jPrefsScoresheet' => (int) $extra['jPrefsScoresheet'],
                'jPrefsMinWords' => self::cap($extra['jPrefsMinWords'] ?? null),
                'jPrefsScoreDispMax' => self::cap($extra['jPrefsScoreDispMax'] ?? null),
                'jPrefsJudgingOpen' => self::clampedOpen(
                    (string) ($extra['jPrefsJudgingOpen'] ?? ''),
                    self::sessionDateRange()['earliest'],
                ),
                'jPrefsJudgingClosed' => self::clampedClosed(
                    (string) ($extra['jPrefsJudgingClosed'] ?? ''),
                    (string) ($extra['jPrefsJudgingOpen'] ?? ''),
                    self::sessionDateRange(),
                ),
            ] : []),
        ]);

        DB::table('preferences')->where('id', 1)->update([
            'prefsEval' => (int) $data['prefsEval'],
            'prefsDisplaySpecial' => $data['prefsDisplaySpecial'],
        ]);

        // Lowering the BOS maximum removes recorded places beyond it (HM=5
        // exempt). Legacy compared old < new — backwards for its own intent;
        // the port compares in the direction that actually trims.
        if ($oldMaxBos > (int) $data['jPrefsMaxBOS']) {
            DB::table('judging_scores_bos')
                ->where('scorePlace', '>', (int) $data['jPrefsMaxBOS'])
                ->where('scorePlace', '!=', 5)
                ->delete();
        }

        return redirect('/admin/judging/preferences');
    }

    /**
     * Cap columns: NULL when unset, otherwise the posted int with a kept 0.
     */
    private static function cap(int|string|null $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * Earliest/latest date across all defined sessions (type <= 1),
     * including end dates — the same scan process_judging_preferences does
     * before clamping the open/close windows. Empty strings when no
     * sessions exist.
     *
     * @return array{earliest: string, latest: string}
     */
    private static function sessionDateRange(): array
    {
        $dates = [];
        foreach (DB::table('judging_locations')->whereIn('judgingLocType', [0, 1])->get(['judgingDate', 'judgingDateEnd']) as $row) {
            foreach ([$row->judgingDate, $row->judgingDateEnd] as $value) {
                if ($value !== null && $value !== '' && is_numeric($value)) {
                    $dates[] = (int) $value;
                }
            }
        }
        if ($dates === []) {
            return ['earliest' => '', 'latest' => ''];
        }

        return ['earliest' => (string) min($dates), 'latest' => (string) max($dates)];
    }

    /**
     * Posted open date, but never after the earliest session start; with
     * no post: the earliest session start, else today midnight (tenant tz).
     */
    private static function clampedOpen(string $posted, string $earliest): ?int
    {
        $tz = TenantContext::load()->prefsStr('prefsTimeZone');

        if ($posted !== '') {
            $epoch = self::toUtcEpoch($posted, $tz);
            if ($epoch === null) {
                return null;
            }

            return ($earliest === '' || $earliest > (string) $epoch) ? $epoch : (int) $earliest;
        }

        if ($earliest !== '') {
            return (int) $earliest;
        }

        $today = new \DateTimeImmutable('today 00:00:00', new \DateTimeZone(DateFmt::tz($tz)));

        return $today->getTimestamp();
    }

    /**
     * Posted close date, but never before the latest session date; with no
     * post: latest session date, else posted open + 1 day, else earliest
     * session + 1 day, else tomorrow midnight (tenant tz).
     *
     * @param  array{earliest: string, latest: string}  $range
     */
    private static function clampedClosed(string $posted, string $postedOpen, array $range): ?int
    {
        $tz = TenantContext::load()->prefsStr('prefsTimeZone');

        if ($posted !== '') {
            $epoch = self::toUtcEpoch($posted, $tz);
            if ($epoch === null) {
                return null;
            }

            return ($range['latest'] === '' || $range['latest'] < (string) $epoch) ? $epoch : (int) $range['latest'];
        }

        if ($range['latest'] !== '') {
            return (int) $range['latest'];
        }

        if ($postedOpen !== '') {
            $open = self::toUtcEpoch($postedOpen, $tz);
            if ($open !== null) {
                return $open + 86400;
            }
        }

        if ($range['earliest'] !== '') {
            return (int) $range['earliest'] + 86400;
        }

        $tomorrow = new \DateTimeImmutable('tomorrow 00:00:00', new \DateTimeZone(DateFmt::tz($tz)));

        return $tomorrow->getTimestamp();
    }

    /**
     * Port of to_utc_epoch(): parse wall time in the tenant's timezone,
     * store the UTC epoch. Null on blanks/failures.
     */
    private static function toUtcEpoch(?string $value, ?string $tzOffset): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone(DateFmt::tz($tzOffset))))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }
}
