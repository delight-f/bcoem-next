<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Auth\CredentialNormalizer;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Brewer profile form 0 — account & contact edit (P3.2a, ticket 05).
 *
 * Legacy surface: pub/brewer_form_0.pub.php rendered with action=edit +
 * process_brewer.inc.php's edit branch. The port merges the legacy
 * email-change flow (user.pub.php + process_users.inc.php go=username)
 * into this single form per the ticket: a changed brewerEmail re-syncs
 * users.user_name (rejected if another account already owns it), keeping
 * login and the brewer row from drifting apart.
 *
 * blank_to_null parity: empty strings are stored as NULL for every
 * optional column, matching process_brewer.inc.php's edit-branch data map.
 */
final class BrewerController extends Controller
{
    public function showEdit(): View
    {
        $brewer = DB::table('brewer')->where('uid', Auth::id())->first();

        if ($brewer === null) {
            abort(404);
        }

        // Legacy brewer.sec.php:86 — the profile form renders only when the
        // session user owns the row (login email == row email) or is an
        // admin (userLevel <= 1); otherwise just the "own profile" lead
        // (:370). The port page always loads by session uid, so the email
        // match is the live condition.
        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }
        $ownsProfile = strtolower((string) $brewer->brewerEmail) === strtolower((string) $user->user_name)
            || (int) $user->userLevel <= 1;

        return view('brewer.edit', [
            'brewer' => $brewer,
            'ctx' => TenantContext::load(),
            'judgingStarted' => false,
            'futureJudgingSessions' => 0,
            'sponsorsVisible' => false,
            'ownsProfile' => $ownsProfile,
        ]);
    }

    public function saveEdit(Request $request): RedirectResponse
    {
        $userId = (int) Auth::id();

        // Same rules as registration (RegisterController::store) — the two
        // forms write identical columns, so they validate identically.
        $data = $request->validate([
            'brewerFirstName' => ['required', 'string', 'max:200'],
            'brewerLastName' => ['required', 'string', 'max:200'],
            'brewerAddress' => ['nullable', 'string', 'max:255'],
            'brewerCity' => ['nullable', 'string', 'max:255'],
            'brewerState' => ['nullable', 'string', 'max:255'],
            'brewerZip' => ['nullable', 'string', 'max:10'],
            'brewerCountry' => ['nullable', 'string', 'max:255'],
            'brewerPhone1' => ['nullable', 'string', 'max:25'],
            'brewerPhone2' => ['nullable', 'string', 'max:25'],
            'brewerEmail' => ['required', 'email', 'max:255'],
        ]);

        $email = CredentialNormalizer::username($data['brewerEmail']);

        // Email sync rule (process_users.inc.php go=username branch):
        // lowercase/normalized, unique across users.user_name, written to
        // both tables so login keeps working.
        if (DB::table('users')->where('user_name', $email)->where('id', '!=', $userId)->exists()) {
            return back()->withErrors(['brewerEmail' => __('site.email_taken')])->withInput();
        }

        DB::table('brewer')->where('uid', $userId)->update([
            'brewerFirstName' => self::blankToNull($data['brewerFirstName']),
            'brewerLastName' => self::blankToNull($data['brewerLastName']),
            'brewerAddress' => self::blankToNull($data['brewerAddress'] ?? ''),
            'brewerCity' => self::blankToNull($data['brewerCity'] ?? ''),
            'brewerState' => self::blankToNull($data['brewerState'] ?? ''),
            'brewerZip' => self::blankToNull($data['brewerZip'] ?? ''),
            'brewerCountry' => self::blankToNull($data['brewerCountry'] ?? ''),
            'brewerPhone1' => self::blankToNull($data['brewerPhone1'] ?? ''),
            'brewerPhone2' => self::blankToNull($data['brewerPhone2'] ?? ''),
            'brewerEmail' => $email,
        ]);

        DB::table('users')->where('id', $userId)->update([
            'user_name' => $email,
            // Legacy refreshes userCreated on every profile save
            // (process_brewer.inc.php edit branch).
            'userCreated' => now()->format('Y-m-d H:i:s'),
        ]);

        // Legacy landing: ?section=list&msg=2 (info successfully updated).
        return redirect('/list?msg=2');
    }

    /**
     * Legacy global blank_to_null(): '' → NULL, everything else through.
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
