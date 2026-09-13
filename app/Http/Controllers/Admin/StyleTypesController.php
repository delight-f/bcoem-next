<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Style types admin (spec §7 P5.4) — port of admin/style_types.admin.php +
 * process_style_types.inc.php.
 *
 * Parity notes:
 *  - custom type ids start at 16 (ids 1-15 reserved for system use) and are
 *    inserted explicitly, not auto-increment;
 *  - styleTypeOwn is 'custom' on add and preserved on edit (the bcoe rows'
 *    names render disabled and post back as a hidden field);
 *  - combine/separate Mead/Cider mirror the go=combine|separate branches:
 *    flip styleTypeBOS on the Mead/Cider vs Cider(2)/Mead(3) rows, NULL the
 *    deactivated rows' entry limits, and delete any BOS scores for the
 *    retired type ids as a failsafe.
 */
final class StyleTypesController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('admin.style-types', [
            'ctx' => TenantContext::load(),
            'styleTypes' => DB::table('style_types')->orderBy('styleTypeName')->get(),
            'editing' => null,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        return $this->index($request);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $data = self::validated($request);

        // ids 1-15 are reserved for system use.
        $lastId = (int) (DB::table('style_types')->max('id') ?? 0);

        DB::table('style_types')->insert([
            'id' => max($lastId + 1, 16),
            'styleTypeName' => self::blankToNull($data['styleTypeName']),
            'styleTypeOwn' => 'custom',
            'styleTypeBOS' => (string) $data['styleTypeBOS'],
            'styleTypeBOSMethod' => self::blankToNull((string) $data['styleTypeBOSMethod']),
            'styleTypeEntryLimit' => self::blankToNull((string) ($data['styleTypeEntryLimit'] ?? '')),
        ]);

        return redirect('/admin/style-types?msg=9');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('admin.style-types', [
            'ctx' => TenantContext::load(),
            'styleTypes' => DB::table('style_types')->orderBy('styleTypeName')->get(),
            'editing' => DB::table('style_types')->where('id', $id)->first(),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $row = DB::table('style_types')->where('id', $id)->first();
        if ($row === null) {
            return redirect('/admin/style-types');
        }

        $data = self::validated($request);

        DB::table('style_types')->where('id', $id)->update([
            // bcoe-owned names cannot change; the form posts them hidden.
            'styleTypeName' => $row->styleTypeOwn === 'bcoe'
                ? $row->styleTypeName
                : self::blankToNull($data['styleTypeName']),
            'styleTypeOwn' => (string) $row->styleTypeOwn,
            'styleTypeBOS' => (string) $data['styleTypeBOS'],
            'styleTypeBOSMethod' => self::blankToNull((string) $data['styleTypeBOSMethod']),
            'styleTypeEntryLimit' => self::blankToNull((string) ($data['styleTypeEntryLimit'] ?? '')),
        ]);

        return redirect('/admin/style-types?msg=9');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        // bcoe system rows are not deletable (legacy hides the control).
        $row = DB::table('style_types')->where('id', $id)->first();
        if ($row === null || $row->styleTypeOwn === 'bcoe') {
            return redirect('/admin/style-types');
        }

        DB::table('style_types')->delete($id);

        return redirect('/admin/style-types?msg=9');
    }

    public function combine(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('style_types')->where('styleTypeName', 'Mead/Cider')->update(['styleTypeBOS' => 'Y']);
        foreach ([2, 3] as $id) { // Cider = 2, Mead = 3
            DB::table('style_types')->where('id', $id)->update([
                'styleTypeBOS' => 'N',
                'styleTypeEntryLimit' => null,
            ]);
        }
        // Failsafe: drop BOS scores for the retired single types.
        DB::table('judging_scores_bos')->whereIn('scoreType', [2, 3])->delete();

        return redirect('/admin/style-types?msg=2');
    }

    public function separate(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('style_types')->where('styleTypeName', 'Mead/Cider')->update([
            'styleTypeBOS' => 'N',
            'styleTypeEntryLimit' => null,
        ]);
        foreach ([2, 3] as $id) {
            DB::table('style_types')->where('id', $id)->update(['styleTypeBOS' => 'Y']);
        }

        // Failsafe: drop BOS scores for the combined type.
        $combinedId = DB::table('style_types')->where('styleTypeName', 'Mead/Cider')->value('id');
        if ($combinedId !== null) {
            DB::table('judging_scores_bos')->where('scoreType', (int) $combinedId)->delete();
        }

        return redirect('/admin/style-types?msg=2');
    }

    /** @return array<string, mixed> */
    private static function validated(Request $request): array
    {
        return $request->validate([
            'styleTypeName' => ['required', 'string', 'max:100'],
            'styleTypeBOS' => ['required', 'in:Y,N'],
            'styleTypeBOSMethod' => ['required', 'in:1,2,3'],
            'styleTypeEntryLimit' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
