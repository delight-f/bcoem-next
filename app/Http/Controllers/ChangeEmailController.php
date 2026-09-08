<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Auth\CredentialNormalizer;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Legacy ?section=user&action=username (pub/user.pub.php:35-80 + the
 * go=username branch of process_users.inc.php): the distinct
 * change-email page. GET renders the form (self-service, or
 * filter=admin&id=N for admins changing another participant); POST
 * updates users.user_name + brewer.brewerEmail (matched on the OLD
 * brewerEmail, legacy line ~identical) and redirects with msg=3
 * (info updated). Taken email → back with error, legacy msg=1.
 */
final class ChangeEmailController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return redirect('/?msg=99');
        }

        $filter = (string) $request->query('filter', '');
        $id = (int) $request->query('id', 0);

        // Legacy gate: filter=admin requires userLevel<=1; otherwise the
        // target id must be the session user.
        if ($filter === 'admin') {
            if (! $user->isAdmin()) {
                return redirect('/?msg=99');
            }
            $targetId = $id;
        } else {
            $targetId = (int) $user->id;
        }

        $brewer = DB::table('brewer')->where('uid', $targetId)->first();
        if ($brewer === null) {
            return redirect('/list');
        }

        $currentEmail = (string) ($filter === 'admin'
            ? $brewer->brewerEmail
            : $user->user_name);

        $lead = $filter === 'admin'
            ? 'You are changing '.$brewer->brewerFirstName.' '.$brewer->brewerLastName.'’s Email Address (User Name).'
            : 'Change Your Email Address (User Name): '.$currentEmail;

        return view('public.account-username', [
            'ctx' => TenantContext::load(),
            'lead' => $lead,
            'currentEmail' => $currentEmail,
            'filter' => $filter,
            'targetId' => $targetId,
            'oldEmail' => (string) $brewer->brewerEmail,
            'salutation' => '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return redirect('/?msg=99');
        }

        $data = $request->validate([
            'user_name' => ['required', 'email', 'max:255'],
            'sure' => ['required', 'in:Y'],
            'filter' => ['nullable', 'in:admin'],
            'id' => ['nullable', 'integer'],
            'old_email' => ['required', 'email'],
        ]);

        // Same gate as GET.
        if (($data['filter'] ?? '') === 'admin') {
            if (! $user->isAdmin()) {
                return redirect('/?msg=99');
            }
            $targetId = (int) ($data['id'] ?? 0);
        } else {
            $targetId = (int) $user->id;
        }
        $email = CredentialNormalizer::username($data['user_name']);
        $oldEmail = CredentialNormalizer::username($data['old_email']);

        // Legacy process_users.inc.php go=username: taken email → msg=1.
        if (DB::table('users')->where('user_name', $email)->where('id', '!=', $targetId)->exists()) {
            $back = '/user/username';
            if (($data['filter'] ?? '') === 'admin') {
                $back .= '?filter=admin&id='.$targetId;
            }

            return redirect($back.($back === '/user/username' ? '?msg=1' : '&msg=1'));
        }

        DB::table('users')->where('id', $targetId)->update([
            'user_name' => $email,
            'userCreated' => now()->format('Y-m-d H:i:s'),
        ]);

        // Legacy matches the brewer row on the OLD email and rewrites
        // brewerEmail + uid together.
        DB::table('brewer')->where('brewerEmail', $oldEmail)->update([
            'brewerEmail' => $email,
            'uid' => $targetId,
        ]);

        // Self-service change: legacy re-authenticates the session under
        // the new username (?section=list&msg=3).
        return redirect('/list?msg=3');
    }
}
