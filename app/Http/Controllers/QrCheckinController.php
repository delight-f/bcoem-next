<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * QR mobile check-in (legacy qr.php, PARITY-002). Public password-gated
 * surface — contest_info.contestCheckInPassword is the only auth (bcrypt,
 * session flag qrPasswordOK mirrors legacy). Scanned QR URLs carry the
 * entry id as ?id=N; the check-in form assigns an optional judging
 * number (six chars, stored lowercase), box number and paid flag, and
 * always sets brewReceived=1.
 *
 * Port shape: /qr (GET show + POST authenticate) and /qr/checkin (POST),
 * keeping legacy query/msg semantics (msg codes 1-7) so a scan flow's
 * redirects match legacy behavior. msg=8 is a port addition: the
 * unconfigured-password case (contestCheckInPassword NULL) reports "not
 * available" instead of legacy's misleading "password incorrect" for a page
 * nobody can get past (issue #30).
 */
final class QrCheckinController extends Controller
{
    public function show(Request $request): View
    {
        $ctx = TenantContext::load();

        return view('qr.checkin', [
            'ctx' => $ctx,
            'id' => $this->entryId($request),
            'msg' => (string) $request->query('msg', 'default'),
            'passwordSet' => self::passwordSet($ctx->contestStr('contestCheckInPassword')),
            'checkedIn' => $this->checkedInNumbers($request),
        ]);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $id = $this->entryId($request);
        $password = (string) $request->input('inputPassword', '');

        $redirect = '/qr?action=default'.($id !== null ? '&id='.$id : '');

        $stored = DB::table('contest_info')->where('id', 1)->value('contestCheckInPassword');

        // No password configured: report it distinctly rather than echoing
        // legacy's "password incorrect" for a page nobody can get past.
        if (! self::passwordSet($stored)) {
            return redirect($redirect.'&msg=8');
        }

        if (mb_strlen($password) < 1 || mb_strlen($password) > 72) {
            return redirect($redirect.'&msg=1');
        }

        if (! password_verify($password, (string) $stored)) {
            $request->session()->invalidate();

            return redirect($redirect.'&msg=1');
        }

        $request->session()->put('qrPasswordOK', true);

        return redirect($redirect.'&msg=2');
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->session()->get('qrPasswordOK', false)) {
            return redirect('/qr?msg=1');
        }

        $id = $this->entryId($request);
        if ($id === null) {
            return redirect('/qr?action=default&go=success&view=0^000000&msg=4');
        }

        $entry = DB::table('brewing')->where('id', $id)
            ->first(['id', 'brewJudgingNumber', 'brewPaid']);
        if ($entry === null) {
            return redirect('/qr?action=default&go=success&view='.$id.'^000000&msg=4');
        }

        $data = ['brewReceived' => 1];
        $brewPaid = (int) $entry->brewPaid === 1 ? 1 : 0;
        $boxNum = trim((string) $request->input('brewBoxNum', ''));
        if ($boxNum !== '') {
            $data['brewBoxNum'] = $boxNum;
        }
        if ($brewPaid === 0 && $request->boolean('brewPaid')) {
            $brewPaid = 1;
        }
        $data['brewPaid'] = $brewPaid;

        $judgingNumber = strtolower(sprintf('%06s', trim((string) $request->input('brewJudgingNumber', ''))));
        if ($judgingNumber !== '') {
            $clash = DB::table('brewing')
                ->where('brewJudgingNumber', $judgingNumber)
                ->where('id', '<>', $id)
                ->first(['id']);
            if ($clash !== null) {
                return redirect('/qr?action=default&go=default&view='.$clash->id.'^'.$judgingNumber.'&msg=5');
            }

            $data['brewJudgingNumber'] = $judgingNumber;
            $msg = 3;
            $assigned = $judgingNumber;
        } else {
            $msg = 6;
            $assigned = (string) $entry->brewJudgingNumber;
        }

        $updated = DB::table('brewing')->where('id', $id)->update($data);

        return redirect('/qr?action=default&go=success&view='.$id.'^'.$assigned.'&msg='.($updated ? $msg : 7));
    }

    /** Entry id from ?id= (QR-encoded). Null when absent/default. */
    private function entryId(Request $request): ?int
    {
        $raw = (string) $request->query('id', $request->input('id', 'default'));
        if ($raw === '' || $raw === 'default' || ! ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    /** A check-in password is usable when the column holds a non-empty hash. */
    private static function passwordSet(mixed $stored): bool
    {
        return is_string($stored) && $stored !== '';
    }

    /**
     * Legacy carried recently checked-in pairs via ?view=id^num (caret
     * list) so messages can name the entry/judging numbers.
     *
     * @return array<int, string>
     */
    private function checkedInNumbers(Request $request): array
    {
        $view = (string) $request->query('view', '');
        if ($view === '') {
            return [];
        }

        $pairs = [];
        foreach (explode('^', $view) as $pair) {
            $pair = trim($pair);
            if ($pair !== '') {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }
}
