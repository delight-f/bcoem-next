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
 * Competition info (spec §7 P5.4) — port of admin/competition_info.admin.php
 * + process_comp_info.inc.php (action=edit, go=default; the setup "add" and
 * go=qr check-in-password branches are setup-only, not ported).
 *
 * Storage parity with process_comp_info.inc.php:
 *  - dates stored as UTC epochs parsed in the tenant's configured UTC offset
 *    (to_utc_epoch());
 *  - contestRules is JSON {competition_rules, competition_packing_shipping};
 *  - contestClubs is a JSON array from the semicolon-separated input
 *    (trimmed, trailing ";" stripped, "; " → ";");
 *  - URLs through check_http() (http:// prefixed when no scheme present);
 *  - text columns blank_to_null ('' → NULL).
 *
 * Divergence: legacy hashed the posted check-in password unconditionally — an
 * empty submit replaced the hash with bcrypt(''). The port only rehashes a
 * non-empty value and stores NULL when blank (check-in password cleared),
 * matching the field's documented intent.
 *
 * Divergence: legacy gated this screen AND its processor to top-level admins
 * only ($_SESSION['userLevel'] == 0); the port uses the uniform admin gate
 * (userLevel <= 1) like every other ported admin surface.
 *
 * PARITY-028 (2026-08-31): `contestInfoExtra` is the port's safe equivalent
 * of the legacy `custom_competition_info.pub.php` deploy-time drop-in —
 * stored in the DB instead of a file, rendered on the landing page's
 * competition-info surface + gating the "Other Info" nav item.
 */
final class CompetitionInfoController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();

        return view('admin.competition-info', [
            'ctx' => $ctx,
            'contest' => (array) DB::table('contest_info')->where('id', 1)->first(),
            'clubs' => DB::table('brewer')
                ->whereNotNull('brewerClubs')->where('brewerClubs', '!=', '')
                ->distinct()->pluck('brewerClubs')->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $tz = TenantContext::load()->prefsStr('prefsTimeZone');
        $datetime = [
            function (string $attribute, mixed $value, \Closure $fail) use ($tz): void {
                if (is_string($value) && $value !== '' && self::toUtcEpoch($value, $tz) === null) {
                    $fail("The {$attribute} field is not a valid date/time.");
                }
            },
        ];

        $data = $request->validate([
            'contestName' => ['required', 'string', 'max:255'],
            'contestHost' => ['nullable', 'string', 'max:255'],
            'contestHostWebsite' => ['nullable', 'string', 'max:255'],
            'contestHostLocation' => ['nullable', 'string', 'max:255'],
            'competition_rules' => ['nullable', 'string'],
            'competition_packing_shipping' => ['nullable', 'string'],
            'contestAwards' => ['nullable', 'string'],
            'contestAwardsLocation' => ['nullable', 'string', 'max:255'],
            'contestAwardsLocName' => ['nullable', 'string', 'max:255'],
            'contestAwardsLocDate' => ['nullable', ...$datetime],
            'contestShippingOpen' => ['nullable', ...$datetime],
            'contestShippingDeadline' => ['nullable', ...$datetime],
            'contestShippingName' => ['nullable', 'string', 'max:255'],
            'contestShippingAddress' => ['nullable', 'string', 'max:1000'],
            'contestDropoffOpen' => ['nullable', ...$datetime],
            'contestDropoffDeadline' => ['nullable', ...$datetime],
            'contestRegistrationOpen' => ['nullable', ...$datetime],
            'contestRegistrationDeadline' => ['nullable', ...$datetime],
            'contestEntryOpen' => ['nullable', ...$datetime],
            'contestEntryDeadline' => ['nullable', ...$datetime],
            'contestEntryEditDeadline' => ['nullable', ...$datetime],
            'contestJudgeOpen' => ['nullable', ...$datetime],
            'contestJudgeDeadline' => ['nullable', ...$datetime],
            'contestBottles' => ['nullable', 'string'],
            'contestBOSAward' => ['nullable', 'string'],
            'contestCircuit' => ['nullable', 'string'],
            'contestVolunteers' => ['nullable', 'string'],
            'contestLogo' => ['nullable', 'string', 'max:255'],
            'contestCheckInPassword' => ['nullable', 'string', 'max:255'],
            'contestID' => ['nullable', 'string', 'max:50'],
            'contestClubs' => ['nullable', 'string'],
            'contestWinnerLink' => ['nullable', 'string', 'max:255'],
            'contestInfoExtra' => ['nullable', 'string'],
        ]);

        // Empty strings arrive as null via ConvertEmptyStringsToNull.
        $data = array_map(static fn ($v): string => (string) ($v ?? ''), $data);

        DB::table('contest_info')->where('id', 1)->update($this->storageRow($data));

        return redirect('/admin/competition-info?msg=2');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function storageRow(array $data): array
    {
        $tz = TenantContext::load()->prefsStr('prefsTimeZone');

        // Legacy contestRules: both free-text rule blocks as one JSON blob.
        $rules = json_encode([
            'competition_rules' => (string) ($data['competition_rules'] ?? ''),
            'competition_packing_shipping' => (string) ($data['competition_packing_shipping'] ?? ''),
        ], JSON_THROW_ON_ERROR);

        // Legacy contestClubs: semicolon list → trimmed JSON array.
        $clubs = null;
        $rawClubs = trim((string) ($data['contestClubs'] ?? ''));
        if ($rawClubs !== '') {
            $rawClubs = rtrim($rawClubs, ';');
            $rawClubs = str_replace('; ', ';', $rawClubs);
            $clubs = json_encode(explode(';', $rawClubs), JSON_THROW_ON_ERROR);
        }

        return [
            'contestName' => self::blankToNull((string) $data['contestName']),
            'contestHost' => self::blankToNull((string) ($data['contestHost'] ?? '')),
            'contestHostWebsite' => self::checkHttp((string) ($data['contestHostWebsite'] ?? '')), // null on empty
            'contestHostLocation' => self::blankToNull((string) ($data['contestHostLocation'] ?? '')),
            'contestRegistrationOpen' => self::toUtcEpoch((string) ($data['contestRegistrationOpen'] ?? ''), $tz),
            'contestRegistrationDeadline' => self::toUtcEpoch((string) ($data['contestRegistrationDeadline'] ?? ''), $tz),
            'contestEntryOpen' => self::toUtcEpoch((string) ($data['contestEntryOpen'] ?? ''), $tz),
            'contestEntryDeadline' => self::toUtcEpoch((string) ($data['contestEntryDeadline'] ?? ''), $tz),
            'contestEntryEditDeadline' => self::toUtcEpoch((string) ($data['contestEntryEditDeadline'] ?? ''), $tz),
            'contestJudgeOpen' => self::toUtcEpoch((string) ($data['contestJudgeOpen'] ?? ''), $tz),
            'contestJudgeDeadline' => self::toUtcEpoch((string) ($data['contestJudgeDeadline'] ?? ''), $tz),
            'contestRules' => $rules,
            'contestAwards' => self::blankToNull((string) ($data['contestAwards'] ?? '')),
            'contestAwardsLocation' => self::blankToNull((string) ($data['contestAwardsLocation'] ?? '')),
            'contestAwardsLocName' => self::blankToNull((string) ($data['contestAwardsLocName'] ?? '')),
            'contestAwardsLocDate' => self::toUtcEpoch((string) ($data['contestAwardsLocDate'] ?? ''), $tz),
            'contestAwardsLocTime' => self::toUtcEpoch((string) ($data['contestAwardsLocDate'] ?? ''), $tz),
            'contestShippingOpen' => self::toUtcEpoch((string) ($data['contestShippingOpen'] ?? ''), $tz),
            'contestShippingDeadline' => self::toUtcEpoch((string) ($data['contestShippingDeadline'] ?? ''), $tz),
            'contestShippingName' => self::blankToNull((string) ($data['contestShippingName'] ?? '')),
            'contestShippingAddress' => self::blankToNull((string) ($data['contestShippingAddress'] ?? '')),
            'contestDropoffOpen' => self::toUtcEpoch((string) ($data['contestDropoffOpen'] ?? ''), $tz),
            'contestDropoffDeadline' => self::toUtcEpoch((string) ($data['contestDropoffDeadline'] ?? ''), $tz),
            'contestBottles' => self::blankToNull((string) ($data['contestBottles'] ?? '')),
            'contestBOSAward' => self::blankToNull((string) ($data['contestBOSAward'] ?? '')),
            'contestCircuit' => self::blankToNull((string) ($data['contestCircuit'] ?? '')),
            'contestVolunteers' => self::blankToNull((string) ($data['contestVolunteers'] ?? '')),
            'contestLogo' => self::blankToNull((string) ($data['contestLogo'] ?? '')),
            'contestCheckInPassword' => isset($data['contestCheckInPassword']) && $data['contestCheckInPassword'] !== ''
                ? password_hash((string) $data['contestCheckInPassword'], PASSWORD_BCRYPT)
                : null,
            'contestID' => self::blankToNull((string) ($data['contestID'] ?? '')),
            'contestClubs' => $clubs,
            'contestWinnerLink' => self::checkHttp((string) ($data['contestWinnerLink'] ?? '')), // null on empty
            'contestInfoExtra' => self::blankToNull((string) ($data['contestInfoExtra'] ?? '')),
        ];
    }

    /** Legacy check_http(): prefix http:// when no scheme present, NULL on empty. */
    private static function checkHttp(string $input): ?string
    {
        if ($input === '') {
            return null;
        }

        if (str_contains($input, 'http://') || str_contains($input, 'https://')) {
            return $input;
        }

        return 'http://'.$input;
    }

    /** Legacy global blank_to_null(): '' → NULL. */
    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /** Port of to_utc_epoch(): wall time in the tenant tz → UTC epoch, null on blank/bad. */
    private static function toUtcEpoch(?string $value, ?string $tzOffset): ?int
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
