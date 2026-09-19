<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Authenticated password change — legacy sections/user.sec.php
 * (action=password) + process_users.inc.php go=password branch.
 *
 * Parity: wrong old password bounces back with ?msg=3 ("Your current
 * password was incorrect."); success re-hashes, stamps user_created to
 * now (legacy overwrites userCreated on every change) and lands on /list
 * with the edited-ok message. The strength meter was client-side only in
 * legacy; the server accepted any non-empty pair and so does this.
 */
final class ChangePasswordController extends Controller
{
    public function show(): View
    {
        return view('auth.password', [
            'ctx' => TenantContext::load(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Legacy form marks both required; bcrypt caps usable input at
            // 72 bytes (LoginController's hard cap).
            'passwordOld' => ['required', 'string', 'max:72'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        if (! Hash::check($data['passwordOld'], $user->getAuthPassword())) {
            // Legacy: redirect back with ?msg=3.
            return redirect('/user/password?msg=3');
        }

        DB::table('users')->where('id', (int) $user->id)->update([
            'password' => Hash::make($data['password']),
            'userCreated' => date('Y-m-d H:i:s'),
        ]);

        return redirect('/list?msg=2');
    }
}
