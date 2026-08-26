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
 * Custom "best of" categories (spec §6 P4.4, ticket 04). Legacy:
 * admin/special_best.admin.php + process_special_best_info.inc.php.
 *
 * Storage parity: name/description are strip_tags + trim (the port's
 * replacement for legacy's HTMLPurifier pass); places/rank blank_to_null'd
 * ints. sbi_display_places is posted by the legacy form but its process
 * script never writes it — mirrored, not fixed, until a ledger pins intent.
 */
final class SpecialBestController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('judging.special-best', [
            'ctx' => TenantContext::load(),
            'categories' => DB::table('special_best_info')->orderBy('sbi_rank')->get(),
            // "View..." 'All Custom Category Entries' renders only when winner
            // rows exist (special_best.admin.php:784-790).
            'entriesCount' => DB::table('special_best_data')->count(),
            // "Add/Edit Entries For..." dropdown — legacy
            // lib/admin.lib.php score_custom_winning_choose() orders by
            // sbi_name and labels each item add/edit by whether the category
            // already has winner rows.
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
                'name' => $c->sbi_name,
                'hasData' => (int) ($counts[$c->id] ?? 0) > 0,
            ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('judging.special-best-form', [
            'ctx' => TenantContext::load(),
            'category' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('special_best_info')->insert($this->storageRow($request));

        return redirect('/admin/judging/special-best');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $category = DB::table('special_best_info')->where('id', $id)->first();
        if ($category === null) {
            return redirect('/admin/judging/special-best');
        }

        return view('judging.special-best-form', [
            'ctx' => TenantContext::load(),
            'category' => $category,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('special_best_info')->where('id', $id)->update($this->storageRow($request));

        return redirect('/admin/judging/special-best');
    }

    /** Cascades the category's winner rows like process_delete.inc.php:66-101. */
    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::transaction(function () use ($id): void {
            DB::table('special_best_data')->where('sid', $id)->delete();
            DB::table('special_best_info')->delete($id);
        });

        return redirect('/admin/judging/special-best');
    }

    /**
     * @return array<string, mixed>
     */
    private function storageRow(Request $request): array
    {
        $data = $request->validate([
            'sbi_name' => ['required', 'string', 'max:255'],
            'sbi_places' => ['required', 'integer', 'min:1'],
            'sbi_rank' => ['nullable', 'integer', 'min:1', 'max:20'],
            'sbi_description' => ['nullable', 'string'],
        ]);

        return [
            // Plain strip_tags/trim — matches the legacy purifier fix shape
            // against double-encoded output downstream.
            'sbi_name' => self::blankToNull(strip_tags(trim((string) $data['sbi_name']))),
            'sbi_description' => self::blankToNull(strip_tags(trim((string) ($data['sbi_description'] ?? '')))),
            'sbi_places' => (int) $data['sbi_places'],
            'sbi_rank' => self::blankToNull(isset($data['sbi_rank']) ? (string) ((int) $data['sbi_rank']) : null),
        ];
    }

    /**
     * Legacy global blank_to_null(): '' → NULL, everything else through.
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
