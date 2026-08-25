<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Judging-session and non-judging-session config (spec §6 P4.1, ticket 01).
 * Legacy: admin/judging_locations.admin.php +
 * process_judging_locations.inc.php, admin/non-judging_locations.admin.php
 * (same `judging_locations` table — non-judging rows are judgingLocType=2).
 *
 * Storage parity with process_judging_locations.inc.php:
 *  - dates are stored as UTC epochs parsed in the tenant's configured UTC
 *    offset (to_utc_epoch()); an empty end date stays NULL;
 *  - every text column goes through blank_to_null ('' → NULL);
 *  - a missing/empty judgingLocType means 0 (Traditional).
 *
 * Delete parity with process_delete.inc.php (go=judging): before the row
 * delete, Y-{id}/N-{id} marks are stripped from every brewer's
 * brewerJudgeLocation / brewerStewardLocation CSVs.
 */
final class LocationController extends Controller
{
    /**
     * 'judging' → sessions of type 0/1; 'non-judging' → type 2. Set per
     * route group via ->defaults('kind', ...).
     *
     * @var 'judging'|'non-judging'
     */
    private string $kind;

    public function __construct(Request $request)
    {
        $this->kind = $request->route('kind') === 'non-judging' ? 'non-judging' : 'judging';
    }

    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('judging.config.locations', [
            'ctx' => TenantContext::load(),
            'nonJudging' => $this->kind === 'non-judging',
            'locations' => $this->query()->orderBy('id')->get(),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('judging.config.location-form', [
            'ctx' => TenantContext::load(),
            'nonJudging' => $this->kind === 'non-judging',
            'location' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $data = $this->validatePayload($request);

        DB::table('judging_locations')->insert($data);

        return redirect($this->indexUrl());
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $location = $this->query()->where('id', $id)->first();
        if ($location === null) {
            return redirect($this->indexUrl());
        }

        return view('judging.config.location-form', [
            'ctx' => TenantContext::load(),
            'nonJudging' => $this->kind === 'non-judging',
            'location' => $location,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $data = $this->validatePayload($request);

        DB::table('judging_locations')->where('id', $id)->update($data);

        return redirect($this->indexUrl());
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        // Strip this location's availability marks from every brewer CSV
        // before the delete (process_delete.inc.php go=judging branch).
        foreach (['brewerJudgeLocation', 'brewerStewardLocation'] as $column) {
            $rows = DB::table('brewer')->get(['id', $column]);
            foreach ($rows as $row) {
                $csv = (string) ($row->{$column} ?? '');
                if ($csv === '' || ! str_contains($csv, '-'.$id)) {
                    continue;
                }

                $kept = [];
                foreach (explode(',', $csv) as $mark) {
                    if ($mark === 'Y-'.$id || $mark === 'N-'.$id) {
                        continue;
                    }
                    $kept[] = $mark;
                }

                DB::table('brewer')->where('id', $row->id)->update([$column => implode(',', $kept)]);
            }
        }

        DB::table('judging_locations')->delete($id);

        return redirect($this->indexUrl());
    }

    private function query(): Builder
    {
        $q = DB::table('judging_locations');

        return $this->kind === 'non-judging'
            ? $q->where('judgingLocType', 2)
            : $q->whereIn('judgingLocType', [0, 1]);
    }

    private function indexUrl(): string
    {
        return $this->kind === 'non-judging' ? '/admin/judging/non-judging' : '/admin/judging/locations';
    }

    /**
     * Same field set + transforms as process_judging_locations.inc.php:
     * required name/address/start (and rounds for judging sessions), an
     * end date that is required exactly for distributed (type 1)
     * sessions, blank_to_null on all text columns.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $nonJudging = $this->kind === 'non-judging';

        $tz = TenantContext::load()->prefsStr('prefsTimeZone');
        $datetime = [
            function (string $attribute, mixed $value, \Closure $fail) use ($tz): void {
                if (is_string($value) && $value !== '' && self::toUtcEpoch($value, $tz) === null) {
                    $fail("The {$attribute} field is not a valid date/time.");
                }
            },
        ];

        $rules = [
            'judgingLocName' => ['required', 'string', 'max:255'],
            'judgingDate' => ['required', ...$datetime],
            'judgingLocation' => ['required', 'string', 'max:255'],
            'judgingLocNotes' => ['nullable', 'string', 'max:1000'],
        ];
        if ($nonJudging) {
            $rules['judgingDateEnd'] = ['nullable', ...$datetime];
            $rules['judgingRounds'] = ['nullable'];
        } else {
            $rules['judgingLocType'] = ['required', 'in:0,1'];
            $rules['judgingDateEnd'] = [
                'nullable',
                'required_if:judgingLocType,1',
                ...$datetime,
            ];
            $rules['judgingRounds'] = ['required', 'integer', 'min:1'];
        }

        return self::storageRow($request->validate($rules), $nonJudging, $tz);
    }

    /**
     * The exact column map process_judging_locations.inc.php writes:
     * epochs for the two dates, blank_to_null on every text column, and
     * an empty judgingLocType stored as 0.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function storageRow(array $data, bool $nonJudging, ?string $tz): array
    {
        return [
            'judgingLocType' => $nonJudging ? 2 : (int) $data['judgingLocType'],
            'judgingDate' => self::toUtcEpoch((string) $data['judgingDate'], $tz),
            'judgingDateEnd' => self::toUtcEpoch(isset($data['judgingDateEnd']) ? (string) $data['judgingDateEnd'] : '', $tz),
            'judgingLocation' => self::blankToNull((string) $data['judgingLocation']),
            'judgingLocName' => self::blankToNull((string) $data['judgingLocName']),
            'judgingRounds' => $nonJudging ? null : (int) $data['judgingRounds'],
            'judgingLocNotes' => self::blankToNull(isset($data['judgingLocNotes']) ? (string) $data['judgingLocNotes'] : ''),
        ];
    }

    /**
     * Legacy global blank_to_null(): '' → NULL, everything else through.
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Port of to_utc_epoch(): parse the posted wall time in the tenant's
     * timezone and store the UTC epoch. Returns null for blanks (the
     * caller validated non-blanks already).
     */
    private static function toUtcEpoch(?string $value, ?string $tzOffset): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone(DateFmt::tz($tzOffset)));
        } catch (\Exception) {
            return null;
        }

        return $dt->getTimestamp();
    }
}
