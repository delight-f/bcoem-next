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
 * Barcode check-in (spec P4.5, ticket 05). Legacy:
 * admin/barcode_check-in.admin.php +
 * includes/process/process_barcode_check_in.inc.php.
 *
 * Flag semantics pinned by ledger/entry-lifecycle.md and the legacy
 * process include:
 *  - Check-in only ever writes brewReceived='1'; it never touches
 *    brewPaid or brewConfirmed (ledger #12 — independent flags).
 *  - There is NO undo path in the legacy module (un-receiving lives on
 *    the entries-admin ajax checkbox, out of scope here), so a re-scan
 *    of a received entry is an idempotent re-write reported as "already
 *    checked in" — exactly what legacy did on every successful scan.
 *  - A scan whose judging number matches several rows (brewJudgingNumber
 *    has no unique index, ledger #9) is refused without any write — the
 *    port of legacy's flag_jnum refusal when a supplied judging number
 *    was already assigned to another entry.
 *
 * Single fast text input replaces legacy's 15-row batch form: a
 * keyboard-wedge scanner types the code and sends Enter, which submits.
 * Barcodes encode judging numbers, so lookup is exact brewJudgingNumber
 * first, bare numeric entry id second (legacy accepted both fields).
 */
final class BarcodeCheckinController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        if ($request->query('clear') !== null) {
            session()->forget('checkin.list');
        }

        return view('judging.checkin', [
            'ctx' => TenantContext::load(),
            'checkedIn' => array_values((array) session('checkin.list', [])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $scan = trim((string) $request->validate([
            'scan' => ['required', 'string', 'max:32'],
        ])['scan']);

        $matches = DB::table('brewing')
            ->where('brewJudgingNumber', $scan)
            ->get(['id', 'brewReceived']);
        if ($matches->isEmpty() && ctype_digit($scan)) {
            $row = DB::table('brewing')->where('id', (int) $scan)->first(['id', 'brewReceived']);
            $matches = collect($row === null ? [] : [$row]);
        }

        if ($matches->count() > 1) {
            return redirect('/admin/judging/checkin?dup='.urlencode($scan));
        }

        $entry = $matches->first();
        if ($entry === null) {
            return redirect('/admin/judging/checkin?bad='.urlencode($scan));
        }

        DB::table('brewing')->where('id', (int) $entry->id)->update(['brewReceived' => 1]);

        $again = (int) $entry->brewReceived === 1;
        if (! $again) {
            session()->push('checkin.list', $scan);
        }

        return redirect('/admin/judging/checkin?'.($again ? 'again' : 'ok').'='.urlencode($scan));
    }
}
