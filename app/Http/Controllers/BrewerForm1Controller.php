<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Brewer\Clubs;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Brewer profile form 1 (P3.2b) — port of `pub/brewer_form_1.pub.php`
 * (clubs / Pro-Am / AHA / MHP sections) + the matching fields of
 * `includes/process/process_brewer.inc.php` via
 * `process_brewer_info.inc.php`.
 *
 * Second wizard step after account/contact (form 0). Legacy served it as
 * ?section=brewer&go=profile; the port uses clean URLs under /list.
 * Saves only the four demographics columns; blank_to_null parity applies
 * to every text column (empty string → NULL).
 */
final class BrewerForm1Controller extends Controller
{
    public function show(): View
    {
        $ctx = TenantContext::load();
        $row = DB::table('brewer')->where('uid', (int) Auth::id())->first();

        $brewer = $row !== null
            ? $row
            : (object) ['brewerClubs' => null, 'brewerAHA' => null, 'brewerMHP' => null, 'brewerProAm' => '0'];

        return view('brewer.clubs', [
            'brewer' => $brewer,
            'ctx' => $ctx,
            'mhpDisplay' => (int) $ctx->prefsStr('prefsMHPDisplay') === 1,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $ctx = TenantContext::load();

        $data = $request->validate([
            // Legacy pattern [^%&"\']+ on the free-text field; capped at the
            // same 255 used by registration.
            'brewerClubs' => ['nullable', 'string', 'max:255'],
            'brewerClubsOther' => ['nullable', 'string', 'max:255'],
            // Legacy client pattern [A-Za-z0-9]+; varchar(255) column.
            'brewerAHA' => ['nullable', 'string', 'max:255'],
            // Legacy pattern \d*; int(11) column.
            'brewerMHP' => ['nullable', 'integer', 'min:0'],
            // tinyint(2) column: 1=yes, 0=no, 2=opt out (radio values).
            'brewerProAm' => ['nullable', 'in:0,1,2'],
        ]);

        $values = [
            'brewerClubs' => self::blankToNull(Clubs::value($data, $ctx)),
            'brewerAHA' => self::blankToNull($data['brewerAHA'] ?? ''),
            'brewerMHP' => isset($data['brewerMHP']) ? (int) $data['brewerMHP'] : null,
            'brewerProAm' => $data['brewerProAm'] ?? '0',
        ];

        $uid = (int) Auth::id();

        if (DB::table('brewer')->where('uid', $uid)->exists()) {
            DB::table('brewer')->where('uid', $uid)->update($values);
        } else {
            // Failsafe for a user without a brewer row (legacy always has
            // one from registration); minimal row like the baseline fixture.
            DB::table('brewer')->insert($values + [
                'uid' => $uid,
                'brewerFirstName' => '',
                'brewerLastName' => '',
                'brewerEmail' => (string) (Auth::user()->user_name ?? ''),
            ]);
        }

        return redirect('/list/edit-clubs');
    }

    /**
     * Legacy global blank_to_null(): '' → NULL, everything else through.
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
