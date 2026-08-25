<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Auth\CredentialNormalizer;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The five AJAX endpoints this slice's forms call (P3.7). Ports
 * ajax/username.ajax.php, valid_email.ajax.php, account_checks.ajax.php,
 * save.ajax.php and count_records.ajax.php with identical observable
 * behavior.
 *
 * Contract notes (parity):
 *   - Legacy's consumed output was the raw HTML fragment (the JS injected
 *     it straight into the DOM); its `action=json` envelope mode was dead
 *     code. The port wraps the same fragments in a JSON envelope
 *     {status, message, errors} so forms read `message` as HTML.
 *   - status codes mirror legacy: 1 = ok, 0 = rejected input, 9 = no
 *     session (save only — legacy's other files only checked the always-
 *     bootstrapped session flag, which every visitor has).
 *   - Message strings are byte-for-byte parity surface (lang/en/site.php).
 *
 * Port hardening (legacy had none): all endpoints are CSRF-protected
 * POSTs under the web middleware group; save is additionally gated on an
 * authenticated session + userLevel<=1 for admin writes (legacy checked a
 * same-origin Referer header, trivially spoofed).
 */
final class AjaxController extends Controller
{
    /** save.ajax.php brewing fields reachable from this slice (admin inline edits). */
    private const BREWING_FIELDS = [
        'brewAdminNotes',
        'brewStaffNotes',
        'brewBoxNum',
        'brewJudgingNumber',
        'brewPaid',
        'brewReceived',
    ];

    /** count_records.ajax.php allow-lists, verbatim. */
    private const COUNT_SECTIONS = ['evaluation', 'brewing', 'updated-display'];

    /** @var array<string, list<string>> */
    private const COUNT_COLUMNS = [
        'evaluation' => ['evalTable'],
        'brewing' => ['brewConfirmed', 'brewPaid', 'brewReceived'],
    ];

    /**
     * username.ajax.php: availability check for users.user_name.
     * Legacy pipeline: normalize_email_username() → invalid email degrades
     * to "POST variable empty" (it queried with `false` and fell through).
     */
    public function username(Request $request): JsonResponse
    {
        $raw = (string) $request->input('user_name', '');

        if (! $request->has('user_name') || strlen($raw) < 3) {
            return response()->json([
                'status' => '0',
                'message' => 'No username provided.',
                'errors' => 'No POST variable provided.',
            ]);
        }

        $userName = CredentialNormalizer::username($raw);

        if ($userName === '' || filter_var($userName, FILTER_VALIDATE_EMAIL) === false) {
            return response()->json([
                'status' => '0',
                'message' => 'No username provided.',
                'errors' => 'POST variable empty.',
            ]);
        }

        $taken = DB::table('users')->where('user_name', $userName)->exists();

        return response()->json([
            'status' => '1',
            'message' => $taken
                ? sprintf('<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> %s</span>', self::t('site.alert_email_in_use'))
                : sprintf('<span class="text-success"><i class="fa fa-check-circle"></i> %s</span>', self::t('site.alert_email_not_in_use')),
            'errors' => '',
        ]);
    }

    /**
     * valid_email.ajax.php: email format check. The vendored is_email lib
     * is not ported (spec §9); FILTER_VALIDATE_EMAIL replicates the first
     * half of legacy's pipeline and is the documented deviation.
     */
    public function validEmail(Request $request): JsonResponse
    {
        $email = self::sterilize((string) $request->input('email', ''));

        // filter_var returns the value or false; empty never validates
        // (legacy: `(is_email($email)) && (!empty($email))`).
        $valid = $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        return response()->json([
            'status' => $valid ? '1' : '0',
            'message' => $valid
                ? sprintf('<span class="text-success"><i class="fa fa-check-circle"></i> %s</span>', self::t('site.alert_email_valid'))
                : sprintf('<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> %s</span>', self::t('site.alert_email_not_valid')),
            'errors' => '',
        ]);
    }

    /**
     * account_checks.ajax.php: per-field checks. This slice consumes
     * action=email and action=username&go=default (duplicate guard);
     * go=forgot / check_answer are superseded by the P3.1c password-reset
     * routes and go=change feeds the change-email UI deferred with the
     * admin account surface.
     */
    public function accountChecks(Request $request): JsonResponse
    {
        $action = (string) $request->input('action', 'default');

        if ($action === 'email') {
            // FILTER_SANITIZE_EMAIL strips to the RFC local-part alphabet,
            // matching legacy's sanitize+purify outcome for email input.
            $email = filter_var($request->input('email'), FILTER_SANITIZE_EMAIL);
            $valid = is_string($email) && $email !== ''
                && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

            return response()->json([
                'status' => $valid ? '1' : '0',
                'message' => $valid
                    ? sprintf('<p class="text-success"><i class="fas fa-check-circle pe-2"></i><strong>%s</strong></p>', self::t('site.alert_email_valid'))
                    : sprintf('<p class="text-danger"><i class="fas fa-exclamation-triangle pe-2"></i><strong>%s</strong></p>', self::t('site.alert_email_not_valid')),
                'errors' => '',
            ]);
        }

        if ($action === 'username' && (string) $request->input('go', 'default') === 'default') {
            $userName = filter_var($request->input('user_name'), FILTER_SANITIZE_EMAIL);
            $found = is_string($userName) && $userName !== ''
                && DB::table('users')->where('user_name', $userName)->exists();

            return response()->json([
                'status' => '1',
                'message' => $found
                    ? sprintf('<p class="text-danger"><i class="fas fa-exclamation-triangle pe-2"></i><strong>%s</strong></p>', self::t('site.alert_email_in_use'))
                    : sprintf('<p class="text-success"><i class="fas fa-check-circle pe-2"></i><strong>%s</strong></p>', self::t('site.alert_email_not_in_use')),
                'errors' => '',
            ]);
        }

        return response()->json(['status' => '0', 'message' => '', 'errors' => '']);
    }

    /**
     * save.ajax.php, action=brewing: single-field inline updates behind the
     * admin entries surface. Empty writes NULL ('' when rid2=text-col),
     * "0" writes NULL, everything else writes the sterilized input — the
     * legacy write shape, verbatim. brewUpdated stamped on every save.
     */
    public function save(Request $request): JsonResponse
    {
        $id = self::sterilize((string) $request->input('id', 'default'));
        $post = '0';
        $input = '';
        $status = 0;
        $errorType = 0;

        $user = Auth::user();

        if (! ($user instanceof User)) {
            $status = 9; // Session expired, not enabled, etc.
        } elseif ((string) $request->input('action', 'default') === 'brewing'
            && (int) $user->userLevel <= 1
        ) {
            $go = (string) $request->input('go', 'default');

            if (! in_array($go, self::BREWING_FIELDS, true)) {
                // Legacy built the column name from user input and let the
                // query fail (error_type 3); the allow-list fails fast.
                $errorType = 3;
            } else {
                if ($go === 'brewJudgingNumber') {
                    $post = str_replace('^', '-', (string) $request->input($go, ''));
                    $input = strtolower(self::sterilize($post));
                } else {
                    $input = self::sterilize((string) $request->input($go, ''));
                }

                $data = [$go => self::writeValue($input, (string) $request->input('rid2', 'default'))];
                $data['brewUpdated'] = now()->format('Y-m-d H:i:s');

                try {
                    DB::table('brewing')->where('id', $id)->update($data);
                    $status = 1;
                } catch (\Throwable) {
                    $errorType = 3; // SQL error
                }
            }
        }
        // Logged-in non-admins match legacy exactly: no branch runs, the
        // envelope reports a silent no-op (status 0).

        return response()->json([
            'status' => (string) $status,
            'query' => '', // legacy echoed $sql but never assigned it
            'post' => (string) $post,
            'input' => (string) $input,
            'id' => $id,
            'error_type' => (string) $errorType,
        ]);
    }

    /**
     * count_records.ajax.php: count queries behind the entry-status cards.
     * Allow-listed sections/columns; total-fees modes compute the fee math
     * instead of counting rows. Response shape verbatim, including
     * `updated` being set alongside `count` on success only.
     */
    public function countRecords(Request $request): JsonResponse
    {
        $section = self::sterilize((string) $request->input('section', 'default'));

        $response = [
            'success' => false,
            'count' => 0,
            'message' => '',
        ];

        if (! in_array($section, self::COUNT_SECTIONS, true)) {
            $response['message'] = 'Not Authorized.';

            return response()->json($response);
        }

        try {
            $doQuery = true;
            $noQueryValue = '';

            switch ($section) {
                case 'evaluation':
                    $query = DB::table('evaluation');

                    if ((string) $request->input('p1', 'default') === 'eid') {
                        if ((string) $request->input('c1', 'default') === 'table'
                            && in_array((string) $request->input('p2', 'default'), self::COUNT_COLUMNS['evaluation'], true)
                            && (string) $request->input('c2', 'default') !== 'default'
                        ) {
                            $query->where((string) $request->input('p2'), (string) $request->input('c2'));
                        }

                        $count = $query->selectRaw('COUNT(DISTINCT eid) as cnt')->value('cnt');
                    } else {
                        $count = $query->count();
                    }

                    break;

                case 'updated-display':
                    $doQuery = false;
                    $noQueryValue = self::displayStamp();
                    $count = $noQueryValue;

                    break;

                default: // brewing
                    $p1 = (string) $request->input('p1', 'default');

                    if ($p1 === 'total-fees' || $p1 === 'total-fees-paid') {
                        $doQuery = false;
                        $noQueryValue = self::totalFees($p1 === 'total-fees-paid');
                        $count = $noQueryValue;
                    } else {
                        $query = DB::table('brewing');

                        if (in_array($p1, self::COUNT_COLUMNS['brewing'], true)
                            && (string) $request->input('c1', 'default') !== 'default'
                        ) {
                            $query->where($p1, (string) $request->input('c1'));

                            foreach ([['p2', 'c2'], ['p3', 'c3']] as [$pp, $cc]) {
                                if (in_array((string) $request->input($pp, 'default'), self::COUNT_COLUMNS['brewing'], true)
                                    && (string) $request->input($cc, 'default') !== 'default'
                                ) {
                                    $query->where((string) $request->input($pp), (string) $request->input($cc));
                                }
                            }
                        }

                        $count = $query->count();
                    }
            }

            $response['success'] = true;
            $response['count'] = $count;
            $response['updated'] = $doQuery ? self::displayStamp() : $noQueryValue;
        } catch (\Throwable $e) {
            $response['message'] = 'Query failed: '.$e->getMessage();
        }

        return response()->json($response);
    }

    /**
     * Legacy save write shape: empty → NULL ('' for text columns via
     * rid2=text-col), "0" → NULL, else the sterilized value.
     */
    private static function writeValue(string $input, string $rid2): ?string
    {
        if ($input === '') {
            return $rid2 === 'text-col' ? '' : null;
        }

        return $input === '0' ? null : $input;
    }

    /**
     * total_fees()/total_fees_paid() (common.lib.php), bid=default /
     * filter=default branch — per-entrant totals with the early-entry
     * discount, brewer special rate and fee cap, summed over all users.
     * $paidOnly adds legacy's brewPaid='1' filter (total_fees_paid).
     */
    private static function totalFees(bool $paidOnly): string
    {
        $ctx = TenantContext::load();
        $fee = (float) ($ctx->contestStr('contestEntryFee') ?? 0);
        $feeDiscount = (float) ($ctx->contestStr('contestEntryFee2') ?? 0);
        $discountOn = $ctx->contestStr('contestEntryFeeDiscount') === 'Y';
        $discountNum = (int) ($ctx->contestStr('contestEntryFeeDiscountNum') ?? 0);
        $cap = (float) ($ctx->contestStr('contestEntryCap') ?? 0);
        $specialRate = (string) $ctx->contestStr('contestEntryFeePasswordNum');
        $special = $specialRate === '' ? null : (float) $specialRate;

        $total = 0.0;

        $users = DB::table('users')->orderBy('id')->pluck('id');

        foreach ($users as $uid) {
            $entries = DB::table('brewing')->where('brewBrewerID', $uid);
            if ($paidOnly) {
                $entries->where('brewPaid', '1');
            }
            $numEntries = (int) $entries->count();

            $calc = 0.0;

            if ($numEntries > 0) {
                $brewer = DB::table('brewer')->where('uid', $uid)->value('brewerDiscount');
                $hasSpecial = $brewer === 'Y' && $special !== null;

                if ($hasSpecial) {
                    if ($discountOn) {
                        $a = $discountNum * $special;
                        $b = ($numEntries - $discountNum) * ($feeDiscount > $special ? $special : $feeDiscount);
                        $c = $a + $b;
                        $d = $numEntries * $special;
                        $sum = $numEntries <= $discountNum ? $d : $c;
                    } else {
                        $sum = $numEntries * $special;
                    }
                } else {
                    if ($discountOn) {
                        $a = $discountNum * $fee;
                        $b = ($numEntries - $discountNum) * $feeDiscount;
                        $c = $a + $b;
                        $d = $numEntries * $fee;
                        $sum = $numEntries <= $discountNum ? $d : $c;
                    } else {
                        $sum = $numEntries * $fee;
                    }
                }

                $calc = ($cap > 0 && $sum >= $cap) ? $cap : $sum;
            }

            $total += $calc;
        }

        return number_format($total, 2);
    }

    /** Legacy `$current_date_display_short . ' ' . $current_time`. */
    private static function displayStamp(): string
    {
        $ctx = TenantContext::load();

        return (string) DateFmt::dateTime(
            time(),
            $ctx->prefsStr('prefsTimeZone'),
            $ctx->prefsStr('prefsDateFormat'),
            $ctx->prefsStr('prefsTimeFormat'),
        );
    }

    /**
     * Legacy sterilize() (lib/sanitize.lib.php): trim, HTML-encode, strip
     * tags, collapse slashes, re-add. Kept verbatim so stored ajax input
     * matches what the legacy pipeline would have written.
     */
    public static function sterilize(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $value = trim($value);
        $value = strip_tags(filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $value = stripcslashes($value);
        $value = stripslashes($value);

        return addslashes($value);
    }

    /** Translator access that always yields a string (lang/en/site.php). */
    private static function t(string $key): string
    {
        $value = trans($key);

        return is_string($value) ? $value : (string) $key;
    }
}
