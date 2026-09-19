<?php

declare(strict_types=1);

namespace App\Http\Controllers\Judging;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
 *
 * `?filter=box-paid` (legacy go=checkin&filter=box-paid) swaps in a table
 * of confirmed entries with their box/paid state and a per-row check-in
 * that can also set the box number and paid flag, mirroring the QR
 * check-in's per-entry form (QrCheckinController::store()).
 */
final class BarcodeCheckinController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->query('clear') !== null) {
            session()->forget('checkin.list');
        }

        // filter=box-paid switches to the box/paid layout (legacy
        // go=checkin&filter=box-paid); the scan form stays available.
        $boxPaid = $request->query('filter') === 'box-paid';

        return view('judging.checkin', [
            'ctx' => TenantContext::load(),
            'checkedIn' => array_values((array) session('checkin.list', [])),
            'boxPaid' => $boxPaid,
            'entries' => $boxPaid ? $this->boxPaidEntries() : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scan' => ['required', 'string', 'max:32'],
            'brewBoxNum' => ['nullable', 'string', 'max:10'],
            'brewPaid' => ['nullable', 'boolean'],
        ]);

        $scan = trim((string) $data['scan']);

        $matches = DB::table('brewing')
            ->where('brewJudgingNumber', $scan)
            ->get(['id', 'brewReceived', 'brewPaid']);
        if ($matches->isEmpty() && ctype_digit($scan)) {
            $row = DB::table('brewing')->where('id', (int) $scan)->first(['id', 'brewReceived', 'brewPaid']);
            $matches = collect($row === null ? [] : [$row]);
        }

        if ($matches->count() > 1) {
            return redirect($this->checkinUrl($request, 'dup', $scan));
        }

        $entry = $matches->first();
        if ($entry === null) {
            return redirect($this->checkinUrl($request, 'bad', $scan));
        }

        $update = ['brewReceived' => 1];

        // The box-paid layout's per-row extras; the plain scan form posts
        // neither, so those flags stay untouched (ledger #12).
        if (array_key_exists('brewBoxNum', $data)) {
            $box = trim((string) $data['brewBoxNum']);
            if ($box !== '') {
                $update['brewBoxNum'] = $box;
            }
        }
        if (array_key_exists('brewPaid', $data)) {
            $update['brewPaid'] = ((int) $entry->brewPaid === 1 || (bool) $data['brewPaid']) ? 1 : 0;
        }

        DB::table('brewing')->where('id', (int) $entry->id)->update($update);

        $again = (int) $entry->brewReceived === 1;
        if (! $again) {
            session()->push('checkin.list', $scan);
        }

        return redirect($this->checkinUrl($request, $again ? 'again' : 'ok', $scan));
    }

    /**
     * Confirmed entries for the box/paid check-in table, in judging-number
     * order (the label sequence staff work from).
     *
     * @return Collection<int, \stdClass>
     */
    private function boxPaidEntries(): Collection
    {
        return DB::table('brewing')
            ->where('brewConfirmed', '1')
            ->orderBy('brewJudgingNumber')
            ->orderBy('id')
            ->get(['id', 'brewJudgingNumber', 'brewBoxNum', 'brewPaid', 'brewReceived']);
    }

    /** Post-check-in redirect, keeping the box/paid view when it posted. */
    private function checkinUrl(Request $request, string $status, string $scan): string
    {
        $filter = $request->input('filter') === 'box-paid' ? 'filter=box-paid&' : '';

        return '/admin/judging/checkin?'.$filter.$status.'='.urlencode($scan);
    }
}
