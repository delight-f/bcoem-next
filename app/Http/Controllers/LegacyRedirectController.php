<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Legacy URL redirect contract (HANDOVER §4.3). Old bookmarks and emails
 * use the bcoem query-string shape (index.legacy.php's section/go/action
 * dispatch and includes/process.inc.php POST targets); every mapped shape
 * 301s to the clean port URL so old links keep working drop-in.
 *
 * The map is hand-written from tools/parity/urls.txt (canonical inventory)
 * cross-checked against index.legacy.php and process.inc.php — never
 * invented. Requests under /index.php and plain "/" both land on this
 * controller because the front controller strips its own script name from
 * the path; only a recognized legacy ?section= triggers a redirect, so the
 * port's own /?msg=N links render home as before.
 */
final class LegacyRedirectController extends Controller
{
    /**
     * GET index.php?section=X[&go=Y][&action=Z] → [target, preserved
     * params, status]. Keyed most-specific first: "section|go|action",
     * then "section|go|", then "section||". Status defaults to 301
     * (bookmarks are permanent moves); legacy-only pages with no port
     * equivalent get 302 to the closest surface per urls.txt.
     *
     * TODO gaps (legacy-only, no port page yet — bounce to "/"): entry,
     * contact, volunteers, sponsors (public list), competition (custom
     * info), admin make_admin + change_user_password (both need a user id
     * picker), evaluation go=scoresheet (needs an entry id).
     */
    private const GET_MAP = [
        // ── Anonymous public ──
        'login||' => ['/login'],
        'login|password|forgot' => ['/forgot-password'],
        'login|password|verify' => ['/forgot-password/verify'],
        'login|password|reset-password' => ['/reset-password'],
        'login|password|' => ['/forgot-password'],
        'past-winners||' => ['/'],
        'entry||' => ['/', [], 302],
        'contact||' => ['/', [], 302],
        'volunteers||' => ['/', [], 302],
        'sponsors||' => ['/', [], 302],
        'competition||' => ['/', [], 302],

        // ── Entrant (userLevel 2) ──
        'list||' => ['/list', ['msg']],
        'list|account|' => ['/list/edit-judging'],
        'brew||add' => ['/brew'],
        'pay||' => ['/pay'],
        'brewer||account' => ['/list/edit-account'],

        // ?section=user&go=account&action=password → the authenticated
        // change-password page; username/account variants fold into the
        // merged account form (the port merged legacy's email change into
        // /list/edit-account).
        'user||' => ['/list'],
        'user|account|password' => ['/user/password'],
        'user|account|username' => ['/list/edit-account'],
        'user|account|' => ['/list/edit-account'],

        // ── Admin (userLevel <= 1) — config ──
        'admin||' => ['/admin'],
        'admin|dates|' => ['/admin/dates'],
        'admin|contest_info|edit' => ['/admin/competition-info'],
        'admin|contacts|' => ['/admin/contacts'],
        'admin|contacts|add' => ['/admin/contacts/create'],
        'admin|special_best|' => ['/admin/judging/special-best'],
        'admin|dropoff|' => ['/admin/dropoff'],
        'admin|judging|' => ['/admin/judging/locations'],
        'admin|non-judging|' => ['/admin/judging/non-judging'],
        'admin|sponsors|' => ['/admin/sponsors'],
        'admin|styles|' => ['/admin/styles'],
        'admin|style_types|' => ['/admin/style-types'],
        'admin|upload|html' => ['/admin/hero-images'],
        'admin|upload|' => ['/admin/hero-images'],
        'admin|hero_images|' => ['/admin/hero-images'],
        'admin|mods|' => ['/admin/mods'],

        // ── Admin — entries & participants ──
        'admin|entries|' => ['/backoffice/entries'],
        'admin|count_by_style|' => ['/backoffice/count-by-style'],
        'admin|count_by_substyle|' => ['/backoffice/count-by-substyle'],
        'admin|payments|' => ['/admin/payments'],
        'admin|participants|' => ['/backoffice/participants', ['filter']],
        'admin|checkin|' => ['/admin/judging/checkin'],
        'admin|send_test_email|' => ['/admin/send-test-email'],

        // ── Admin — organizing & scoring ──
        'admin|judging_tables|' => ['/admin/judging/tables'],
        'admin|judging_flights|' => ['/admin/judging/flights'],
        'admin|judging_preferences|' => ['/admin/judging/preferences'],
        'admin|judging_scores|' => ['/admin/judging/scores'],
        'admin|judging_scores_bos|' => ['/admin/judging/bos'],
        'admin|special_best_data|' => ['/admin/judging/special-best-data'],
        'admin|upload_scoresheets|' => ['/admin/upload-scoresheets'],
        'admin|evaluation|default' => ['/eval'],
        'admin|evaluation|' => ['/eval'],
        'evaluation|default|' => ['/eval'],

        // ── Admin — preferences tabs ──
        'admin|preferences|entries' => ['/admin/site-preferences/entries'],
        'admin|preferences|email' => ['/admin/site-preferences/email'],
        'admin|preferences|payment' => ['/admin/site-preferences/payment'],
        'admin|preferences|best' => ['/admin/site-preferences/best'],
        'admin|preferences|' => ['/admin/site-preferences'],

        // ── Admin — data management ──
        'admin|archive|' => ['/admin/archive'],
        'admin|user|' => ['/admin/purge'],

        // ── Legacy-only admin surfaces (no port equivalent yet) ──
        'admin|make_admin|' => ['/admin', [], 302],
        'admin|change_user_password|' => ['/admin', [], 302],
    ];

    /**
     * POST/GET includes/process.inc.php — legacy dispatches on ?action=
     * alone (the section param only tags login/logout flows) → closest
     * port page. Only login keeps method+body (307): the port's /login
     * accepts the legacy loginUsername/loginPassword fields verbatim.
     * Every other target is a form replaced by a port form — a 302 to
     * where that form lives is the documented stopgap; no legacy body can
     * be replayed. Logout arrives both as POST and as GET (session-modal
     * window.location.replace links) → home either way.
     */
    private const PROCESS_MAP = [
        'login' => ['/login', 307],
        'logout' => ['/', 302],
        'forgot' => ['/forgot-password', 302],
        'reset' => ['/forgot-password', 302],
        'delete' => ['/list', 302],
        'barcode_check_in' => ['/admin/judging/checkin', 302],
        'update_judging_flights' => ['/admin/judging/flights', 302],
        'reorder_flight_entries' => ['/admin/judging/flights', 302],
        'delete_scoresheets' => ['/admin/upload-scoresheets', 302],
        'clear_session' => ['/', 302],
        'update' => ['/', 302], // TODO gap: update.php updater not ported
        'purge' => ['/admin/purge', 302],
        'cleanup' => ['/admin/purge', 302],
        'generate_judging_numbers' => ['/admin', 302],
        'check_discount' => ['/brew', 302],
        'convert_bjcp' => ['/admin/styles', 302],
        'archive' => ['/admin/archive', 302],
        'publish' => ['/admin/archive', 302],
        'email' => ['/backoffice/entries', 302],
        'paypal' => ['/admin/payments', 302],
        'dates' => ['/admin/dates', 302],
    ];

    public function __invoke(Request $request): RedirectResponse|View
    {
        $section = (string) $request->query('section', '');
        if ($section === '') {
            // Plain "/" (or bare /index.php): the real home page.
            return app(PublicController::class)->home($request);
        }

        $go = (string) $request->query('go', '');
        $action = (string) $request->query('action', '');

        $dynamic = $this->dynamic($section, $go, $action, $request);
        if ($dynamic !== null) {
            return $this->redirect($request, ...$dynamic);
        }

        $entry = self::GET_MAP["{$section}|{$go}|{$action}"]
            ?? self::GET_MAP["{$section}|{$go}|"]
            ?? self::GET_MAP["{$section}||"]
            ?? null;

        if ($entry === null) {
            // Unrecognized section: legacy rendered default.sec.php for
            // anything unknown — render the port home instead of bouncing.
            return app(PublicController::class)->home($request);
        }

        return $this->redirect($request, $entry[0], $entry[1] ?? [], $entry[2] ?? 301);
    }

    /**
     * includes/process.inc.php — see PROCESS_MAP. GET hits are the logout
     * modal's location.replace links; they follow the same table.
     */
    public function process(Request $request): RedirectResponse
    {
        $action = (string) $request->query('action', '');
        $entry = self::PROCESS_MAP[$action] ?? ['/', 302];

        return $this->redirect($request, $entry[0], [], $entry[1]);
    }

    /**
     * Shapes whose target depends on a param value rather than the
     * dispatch key alone.
     *
     * @return array{0: string, 1: list<string>, 2: int}|null
     */
    private function dynamic(string $section, string $go, string $action, Request $request): ?array
    {
        // ?section=past-winners&go={suffix} → /past-winners/{suffix};
        // sanitized exactly like the port route's own repository lookup.
        if ($section === 'past-winners' && $go !== '') {
            $suffix = preg_replace('/[^a-zA-Z0-9]+/', '', $go) ?? '';

            return [$suffix === '' ? '/' : '/past-winners/'.$suffix, [], 301];
        }

        // ?section=register[&go={entrant|judge|steward}] preserving view
        // (e.g. view=quick on the judge form). No go= meant the entrant
        // form in legacy too.
        if ($section === 'register') {
            $go = in_array($go, ['entrant', 'judge', 'steward'], true) ? $go : 'entrant';

            return ["/register/{$go}", ['view'], 301];
        }
        // Entry edit forms: same brew form in edit mode with id=N.
        $id = (string) $request->query('id', '');
        if ($id !== '' && ctype_digit($id)) {
            if ($section === 'brew' && $action === 'edit') {
                return ["/brew/{$id}/edit", [], 301];
            }
            if ($section === 'admin' && $go === 'entries' && $action === 'edit') {
                return ["/backoffice/entries/{$id}/edit", [], 301];
            }
        }

        return null;
    }

    /**
     * RedirectResponse built directly (not redirect()->to()) so the
     * Location header carries the clean path verbatim — the URL generator
     * prepends the front-controller script name when the request came in
     * on /index.php, which produced the old /index.php/list dead ends.
     *
     * @param  list<string>  $keep
     */
    private function redirect(Request $request, string $target, array $keep, int $status): RedirectResponse
    {
        if ($keep !== []) {
            $query = collect($request->query())->only($keep)->filter(fn ($v): bool => (string) $v !== '');
            if ($query->isNotEmpty()) {
                $target .= '?'.http_build_query($query->all());
            }
        }

        return new RedirectResponse($target, $status);
    }
}
