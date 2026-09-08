<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Drop-off location config (spec §6 P4.1, ticket 01). Legacy:
 * admin/dropoff.admin.php + process_drop_off.inc.php.
 *
 * Storage parity with process_drop_off.inc.php: the name is capitalized
 * like legacy capitalize() (ucwords incl. after - . ( )), the website goes
 * through check_http() (http:// prepended when no scheme) and is
 * lowercased, and every column is blank_to_null'd.
 *
 * ponytail: legacy's dropoff delete link (go=dropoff) matches NO branch in
 * process_delete.inc.php — it was a silent no-op. The port performs the
 * plain row delete instead; a delete button that deletes nothing is not
 * behavior worth preserving.
 */
final class DropOffController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('judging.config.dropoff', [
            'ctx' => TenantContext::load(),
            'locations' => DB::table('drop_off')->orderBy('id')->get(),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        return view('judging.config.dropoff-form', [
            'ctx' => TenantContext::load(),
            'location' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('drop_off')->insert($this->storageRow($request));

        return redirect('/admin/dropoff');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $location = DB::table('drop_off')->where('id', $id)->first();
        if ($location === null) {
            return redirect('/admin/dropoff');
        }

        return view('judging.config.dropoff-form', [
            'ctx' => TenantContext::load(),
            'location' => $location,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('drop_off')->where('id', $id)->update($this->storageRow($request));

        return redirect('/admin/dropoff');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        DB::table('drop_off')->delete($id);

        return redirect('/admin/dropoff');
    }

    /**
     * @return array<string, mixed>
     */
    private function storageRow(Request $request): array
    {
        $data = $request->validate([
            'dropLocationName' => ['required', 'string', 'max:255'],
            'dropLocationPhone' => ['required', 'string', 'max:255'],
            'dropLocation' => ['required', 'string', 'max:255'],
            'dropLocationWebsite' => ['nullable', 'string', 'max:255'],
            'dropLocationNotes' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'dropLocationName' => self::blankToNull(self::capitalize((string) $data['dropLocationName'])),
            'dropLocation' => self::blankToNull((string) $data['dropLocation']),
            'dropLocationPhone' => self::blankToNull((string) $data['dropLocationPhone']),
            // check_http(): prepend http:// when no scheme; stored lowercase.
            'dropLocationWebsite' => self::blankToNull(strtolower(self::checkHttp(trim((string) ($data['dropLocationWebsite'] ?? ''))))),
            'dropLocationNotes' => self::blankToNull((string) ($data['dropLocationNotes'] ?? '')),
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
     * Port of check_http(): a non-empty value without http(s):// gets
     * http:// prepended.
     */
    private static function checkHttp(string $input): string
    {
        if ($input !== '' && ! str_contains($input, 'http://') && ! str_contains($input, 'https://')) {
            return 'http://'.$input;
        }

        return $input;
    }

    /**
     * Port of capitalize(): ucwords plus title-casing after -, ., ( and ).
     */
    private static function capitalize(string $value): string
    {
        $value = ucwords($value);

        foreach (['-', '.', '(', ')'] as $delimiter) {
            $parts = explode($delimiter, $value);
            $parts = array_map(ucwords(...), $parts);
            $value = implode($delimiter, $parts);
        }

        return $value;
    }
}
