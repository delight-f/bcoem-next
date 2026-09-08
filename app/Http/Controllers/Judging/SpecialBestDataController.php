<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Winner rows for custom categories (spec §6 P4.4, ticket 04). Legacy:
 * admin/special_best_data.admin.php + process_special_best_data.inc.php.
 *
 * Storage parity: each form slot resolves a judging number to its brewing
 * row (lowercased, like legacy's strtolower(sterilize()) — the sterilize
 * entity-encoding is a no-op on alphanumeric/dash judging numbers); the row
 * stores sid/bid/eid/sbd_place/sbd_comments, all blank_to_null'd. A slot
 * with an unknown judging number is skipped and reported back (legacy's
 * $a[] = 1 miss counter → msg=24).
 */
final class SpecialBestDataController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $rows = DB::table('special_best_data as sbd')
            ->leftJoin('brewing as b', 'sbd.eid', '=', 'b.id')
            ->leftJoin('brewer as br', 'sbd.bid', '=', 'br.uid')
            ->leftJoin('special_best_info as sbi', 'sbd.sid', '=', 'sbi.id')
            ->orderBy('sbi.sbi_rank')
            ->orderBy('sbd.sid')
            ->orderBy('sbd.sbd_place')
            ->get([
                'sbd.*',
                'b.brewName',
                'br.brewerFirstName', 'br.brewerLastName',
                'sbi.sbi_name',
            ]);

        return view('judging.special-best-data', [
            'ctx' => TenantContext::load(),
            'rows' => $rows,
            // "View..." 'All Custom Style Entries' renders only when winner
            // rows exist (special_best_data.admin.php:816-822).
            'entriesCount' => $rows->count(),
            // "Add/Edit Entries For..." dropdown — legacy
            // lib/admin.lib.php score_custom_winning_choose().
            'entryDropdown' => $this->entryDropdown(),
        ]);
    }

    /**
     * @return Collection<int, array{id:int,name:string,hasData:bool}>
     */
    private function entryDropdown(): Collection
    {
        $counts = DB::table('special_best_data')
            ->select('sid')
            ->selectRaw('count(*) as c')
            ->groupBy('sid')
            ->pluck('c', 'sid');

        return DB::table('special_best_info')->orderBy('sbi_name')->get(['id', 'sbi_name'])
            ->map(static fn (\stdClass $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->sbi_name,
                'hasData' => (int) ($counts[$c->id] ?? 0) > 0,
            ]);
    }

    /** Add/edit slots for one category (existing rows prefilled, rest blank). */
    public function edit(Request $request, int $id): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $category = DB::table('special_best_info')->where('id', $id)->first();
        if ($category === null) {
            return redirect('/admin/judging/special-best');
        }

        $existing = DB::table('special_best_data')->where('sid', $id)->orderBy('sbd_place')->get();
        $slots = [];
        foreach ($existing as $row) {
            $entry = $row->eid === null ? null : DB::table('brewing')->where('id', $row->eid)->first(['brewName']);
            $slots[] = ['rowId' => (int) $row->id, 'exists' => true, 'judgingNumber' => '', 'place' => $row->sbd_place, 'comments' => $row->sbd_comments, 'entryName' => $entry->brewName ?? null];
        }
        for ($i = count($existing); $i < max(1, (int) $category->sbi_places); $i++) {
            $slots[] = ['rowId' => null, 'exists' => false, 'judgingNumber' => '', 'place' => '', 'comments' => '', 'entryName' => null];
        }

        return view('judging.special-best-data-form', [
            'ctx' => TenantContext::load(),
            'category' => $category,
            'slots' => $slots,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $misses = 0;
        /** @var list<string> $keys */
        $keys = array_map(strval(...), (array) $request->input('slot_id', []));

        DB::transaction(function () use ($request, $keys, &$misses): void {
            foreach ($keys as $key) {
                $judgingNo = strtolower(trim((string) $request->input("sbd_judging_no{$key}", '')));
                if ($judgingNo === '') {
                    continue; // legacy: empty slot writes nothing
                }

                // Legacy counts >1 matches as a miss too — the judging-number
                // column is expected unique; treat duplicates as not found.
                $entry = DB::table('brewing')
                    ->where('brewJudgingNumber', $judgingNo)
                    ->orderBy('id')
                    ->get(['id', 'brewBrewerID']);
                if (count($entry) !== 1) {
                    $misses++;

                    continue;
                }

                /** @var \stdClass $row */
                $row = $entry->first();

                $data = [
                    'sid' => self::blankToNull(self::str($request, "sid{$key}")),
                    'bid' => self::blankToNull((string) $row->brewBrewerID),
                    'eid' => self::blankToNull((string) $row->id),
                    // sbd_place is a numeric column; legacy relied on silent
                    // MySQL truncation of junk — write NULL for non-numeric.
                    'sbd_place' => ctype_digit(self::str($request, "sbd_place{$key}"))
                        ? self::str($request, "sbd_place{$key}")
                        : null,
                    // Plain strip_tags/trim, matching the legacy fix shape.
                    'sbd_comments' => self::blankToNull(strip_tags(trim((string) $request->input("sbd_comments{$key}", '')))),
                ];

                if ((string) $request->input("entry_exists{$key}") === 'Y') {
                    DB::table('special_best_data')->where('id', (int) $key)->update($data);
                } else {
                    DB::table('special_best_data')->insert($data);
                }
            }
        });

        return redirect($misses > 0
            ? '/admin/judging/special-best/'.$id.'/entries?msg=24'
            : '/admin/judging/special-best-data');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('special_best_data')->delete($id);

        return redirect('/admin/judging/special-best-data');
    }

    private static function str(Request $request, string $key): string
    {
        return trim((string) $request->input($key, ''));
    }

    /**
     * Legacy global blank_to_null(): '' → NULL, everything else through.
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
