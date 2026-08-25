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
 * Change user level (spec §7 P5.4) — port of admin/make_admin.admin.php +
 * process_users.inc.php go=make_admin branch.
 *
 * Parity: writes userLevel, userAdminObfuscate and userCreated=now (legacy
 * bumps userCreated on every level change). Obfuscate defaults: unchecked
 * post with a non-participant target stores 0; checked stores 1 (legacy
 * $userAdminObfuscate logic).
 */
final class MakeAdminController extends Controller
{
    public function edit(Request $request, int $id): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $user = DB::table('users')->where('id', $id)->first();
        if ($user === null) {
            return redirect('/?msg=99');
        }

        return view('admin.make-admin', ['ctx' => TenantContext::load(), 'user' => $user]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $data = $request->validate([
            'userLevel' => ['required', 'in:0,1,2'],
            'userAdminObfuscate' => ['nullable', 'in:0,1'],
        ]);

        // Legacy: unchecked box ⇒ obfuscate only when the target stays a participant.
        $obfuscate = $request->boolean('userAdminObfuscate')
            ? 1
            : ((int) $data['userLevel'] < 2 ? 0 : 1);

        DB::table('users')->where('id', $id)->update([
            'userLevel' => (string) $data['userLevel'],
            'userCreated' => date('Y-m-d H:i:s'),
            'userAdminObfuscate' => $obfuscate,
        ]);

        return redirect('/admin/users/'.$id.'/level?msg=2');
    }
}
