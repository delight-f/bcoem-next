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
 * Custom modules admin (spec §7 P5.4) — port of admin/mods.admin.php +
 * process_mods.inc.php.
 *
 * Parity: add/edit writes the mods columns for the public-side extend
 * targets (0 All Public Pages, 1 Public Home, 6 Public Registration,
 * 8 Public Account); the inline list form bulk-updates only mod_enable per
 * posted id[]. Administration (legacy 9) is dropped — the render gate runs
 * public-only, so an admin-extending module could never render. The legacy
 * $_SESSION['mods_display'] refresh is dropped — the standalone build reads
 * fresh rows per request.
 */
final class ModsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        return view('admin.mods', [
            'ctx' => TenantContext::load(),
            'mods' => DB::table('mods')->orderBy('mod_rank')->get(),
            'editing' => null,
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        return $this->index($request);
    }

    public function store(Request $request): RedirectResponse
    {
        DB::table('mods')->insert(self::row($request));

        return redirect('/admin/mods?msg=9');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        return view('admin.mods', [
            'ctx' => TenantContext::load(),
            'mods' => DB::table('mods')->orderBy('mod_rank')->get(),
            'editing' => DB::table('mods')->where('id', $id)->first(),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        DB::table('mods')->where('id', $id)->update(self::row($request));

        return redirect('/admin/mods?msg=9');
    }

    /** Enable-toggle bulk submit from the list form. */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        foreach ((array) $request->input('id', []) as $id) {
            $id = (int) $id;
            DB::table('mods')->where('id', $id)->update([
                'mod_enable' => $request->boolean('mod_enable'.$id) ? 1 : 0,
            ]);
        }

        return redirect('/admin/mods?msg=9');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! DB::table('mods')->where('id', $id)->exists()) {
            return redirect('/admin/mods');
        }

        DB::table('mods')->delete($id);

        return redirect('/admin/mods?msg=9');
    }

    /**
     * Exact process_mods.inc.php column map, minus the admin extend targets
     * (dropped with the "Administration" option).
     *
     * @return array<string, mixed>
     */
    private static function row(Request $request): array
    {
        $data = $request->validate([
            'mod_name' => ['required', 'string', 'max:100'],
            'mod_filename' => ['required', 'regex:/^[A-Za-z0-9_\-]+\.php$/', 'max:100'],
            'mod_description' => ['nullable', 'string'],
            'mod_type' => ['required', 'in:0,1,2,3'],
            'mod_permission' => ['required', 'in:0,1,2'],
            'mod_extend_function' => ['required', 'in:0,1,6,8'],
            'mod_rank' => ['required', 'integer', 'min:1', 'max:25'],
            'mod_display_rank' => ['required', 'in:0,1,2'],
            'mod_enable' => ['required', 'in:0,1'],
        ]);

        $extendFunction = (string) $data['mod_extend_function'];

        return [
            'mod_name' => self::blankToNull((string) $data['mod_name']),
            'mod_type' => (string) $data['mod_type'],
            'mod_extend_function' => $extendFunction,
            'mod_filename' => self::blankToNull((string) $data['mod_filename']),
            'mod_description' => self::blankToNull(trim((string) ($data['mod_description'] ?? ''))),
            'mod_permission' => (string) $data['mod_permission'],
            'mod_rank' => (int) $data['mod_rank'],
            'mod_display_rank' => (string) $data['mod_display_rank'],
            'mod_enable' => (int) $data['mod_enable'],
        ];
    }

    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
