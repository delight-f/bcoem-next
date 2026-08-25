<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Admin-initiated password change (spec §7 P5.4) — port of
 * admin/change_user_password.admin.php + process_users.inc.php
 * go=change_user_password branch.
 *
 * Reconciliation with Slice B auth: the existing password paths are the
 * token-reset flow (ForgotPasswordController, anonymous) — there is no
 * logged-in change-for-user path to reuse, so this port adds it with the
 * same storage contract: bcrypt hash into users.password plus a fresh
 * userCreated stamp.
 *
 * Divergence: legacy hashed $_POST['password'] — the CONFIRM field — and
 * relied on client-side data-match validation for parity between the two
 * boxes. The port validates the match server-side and hashes the new
 * password field; a mismatch is a validation error instead of silently
 * storing whichever value arrived in the confirm box.
 */
final class ChangeUserPasswordController extends Controller
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

        return view('admin.change-user-password', ['ctx' => TenantContext::load(), 'user' => $user]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $data = $request->validate([
            'password1' => ['required', 'string', 'min:8'],
            'password' => ['required', 'string', 'same:password1'],
        ]);

        DB::table('users')->where('id', $id)->update([
            'password' => Hash::make((string) $data['password1']),
            'userCreated' => date('Y-m-d H:i:s'),
        ]);

        return redirect('/admin/users/'.$id.'/password?msg=2');
    }
}
