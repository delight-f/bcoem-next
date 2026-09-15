<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Installation\InstallationService;
use App\Support\Wizard\RemoteVersionChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual "check for updates" from the admin dashboard.
 *
 * The automatic notice (RemoteVersionChecker::noticeFor) only runs when its
 * 24-hour cache is stale, and it swallows every failure so a host with no
 * outbound access never sees an error. That makes an update easy to miss and a
 * failed check indistinguishable from "up to date". This endpoint is the
 * explicit, on-demand check: it refreshes the cache synchronously and reports
 * the outcome, including the failure the automatic path hides.
 *
 * Top-Level Administrators only (userLevel 0), matching noticeFor() and the
 * upgrade wizard — a mid-level admin cannot act on the result.
 */
final class UpdateCheckController extends Controller
{
    public function __invoke(
        Request $request,
        RemoteVersionChecker $checker,
        InstallationService $installation,
    ): RedirectResponse {
        $user = $request->user();
        if ($user === null || (int) $user->userLevel !== 0) {
            return redirect('/?msg=99');
        }

        $latest = $checker->refresh();
        $current = $installation->incomingVersion();

        // The notice dismissal is per-session; an update the admin just asked
        // about must not stay hidden behind one.
        $request->session()->forget('wizard.update-notice.dismissed');

        if ($latest === null) {
            return redirect('/admin')->with('error', 'We couldn\'t reach GitHub to check for updates. Check the site\'s outbound connection and try again.');
        }

        if ($current !== '' && version_compare($latest, $current, '>')) {
            // Worded to match the notice the redirect lands on, which offers the
            // action itself (install in the browser, or download by hand).
            return redirect('/admin')->with('status', 'Version '.$latest.' is available.');
        }

        return redirect('/admin')->with('status', 'You are running the latest version'.($current !== '' ? ' ('.$current.')' : '').'.');
    }
}
