<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Results\ResultsRepository;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
use App\Support\Tenant\Windows;
use App\Support\Tenant\WindowState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin landing dashboard — port of admin/default.admin.php. Renders the
 * two-column accordion with the SAME subheadings, order, link labels,
 * grouping and conditionals as legacy, plus the action-button row and the
 * right sidebar (admin/sidebar.admin.php).
 *
 * Links whose port backend does not exist (the label-template matrix,
 * per-session/per-row report variants, pullsheet variants, awards/export
 * families, regenerate/purge modal flows) are rendered DISABLED with a
 * `<!-- TODO: legacy output -->` comment rather than silently dropped —
 * the legacy contract is preserved and the gap is visible. Ledger:
 * .scratch/bcoem-next/dashboard-inventory.md.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $ctx = TenantContext::load();
        $now = time();
        $windows = Windows::derive($ctx, $now);
        $user = $request->user();

        $prefs = [
            'paypalIpn' => (int) $ctx->prefsStr('prefsPaypalIPN') === 1,
            'entryForm' => (int) $ctx->prefsStr('prefsEntryForm'),
            'useMods' => (string) $ctx->prefsStr('prefsUseMods') === 'Y',
            'winnerMethod' => (int) $ctx->prefsStr('prefsWinnerMethod'),
            'proEdition' => (int) $ctx->prefsStr('prefsProEdition'),
            'eval' => (string) $ctx->prefsStr('prefsEval') === '1',
            'showBestBrewer' => (int) ($ctx->prefsStr('prefsShowBestBrewer') ?? 0) !== 0,
            'showBestClub' => (int) ($ctx->prefsStr('prefsShowBestClub') ?? 0) !== 0,
            'mhpDisplay' => (int) $ctx->prefsStr('prefsMHPDisplay') === 1,
            // Legacy $barcode_qrcode_array (bottle_label.output.php :93-99).
            'barcodes' => in_array((int) $ctx->prefsStr('prefsEntryForm'), [1, 3, 5, 6, 0, 11], true),
        ];

        $counts = [
            'tables' => DB::table('judging_tables')->count(),
            'judging' => DB::table('judging_locations')->count(),
            'styleTypes' => DB::table('style_types')->count(),
            'sbi' => DB::table('special_best_info')->count(),
        ];

        $sections = $this->sections((int) $user->userLevel, (int) $user->userAdminObfuscate, $prefs, $counts);

        $helpHtml = [
            'Organizing' => implode('', [
                '<p>Organization in BCOE&amp;M begins with assigning individual participants as a <a href="'.url('/admin/judging/tables').'?action=assign&filter=staff">staff</a> member and/or <a href="'.url('/admin/judging/tables').'?action=assign&filter=judges">judge</a> or <a href="'.url('/admin/judging/tables').'?action=assign&filter=stewards">steward</a>. This builds a pool of available participants to assign to various duties in the competition.</p>',
                '<p>Once assignments have been given, the next steps are to:</p>',
                '<ol>',
                '<li><a href="'.url('/admin/judging/tables').'">Define tables</a> where specific sub-styles will be judged.</li>',
                '<li>Add flights to tables (if queued judging is disabled).</li>',
                '<li>Assign <a href="'.url('/admin/judging/flights').'?action=assign&filter=rounds">tables to rounds</a>.</li>',
                '<li>Assign judges and stewards to tables (and flights, if applicable).</li>',
                '</ol>',
            ]),
        ];

        return view('admin.dashboard', [
            'helpHtml' => $helpHtml,
            'helpTopics' => config('dashboard-help'),
            'left' => $sections['left'],
            'right' => $sections['right'],
            'status' => $this->status($ctx, $windows, $now),
            'firstName' => DB::table('brewer')->where('uid', (int) $user->id)->value('brewerFirstName') ?? '',
        ]);
    }

    /**
     * Sidebar "Competition Status" panel + action-row gates
     * (admin/sidebar.admin.php + admin/default.admin.php:470-505).
     *
     * @return non-empty-array<string, mixed>
     */
    private function status(TenantContext $ctx, Windows $windows, int $now): array
    {
        $fee = (float) ($ctx->contestStr('contestEntryFee') ?? 0);
        $cap = (float) ($ctx->contestStr('contestEntryCap') ?? 0);

        // ponytail: legacy's per-entrant discount matrix (brewerDiscount +
        // tiered contestEntryFeeDiscount) is not ported — the port's payment
        // flow charges flat contestEntryFee per entry. Mirror that here with
        // legacy's per-entrant contestEntryCap; add the discount tiers if a
        // competition actually needs them.
        $fees = 0.0;
        $feesPaid = 0.0;
        $perEntrant = DB::table('brewing')
            ->selectRaw('brewBrewerID, COUNT(*) AS n, SUM(brewPaid = 1) AS paid_n')
            ->groupBy('brewBrewerID')
            ->get();
        foreach ($perEntrant as $row) {
            $fees += $cap > 0 ? min($row->n * $fee, $cap) : $row->n * $fee;
            $feesPaid += $cap > 0 ? min($row->paid_n * $fee, $cap) : $row->paid_n * $fee;
        }

        $judgingStarted = $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate;
        $judgingPast = $windows->futureJudgingSessions === 0
            && $windows->firstJudgingDate !== null
            && $now > $windows->firstJudgingDate + 21600;

        $evalsOn = (string) $ctx->prefsStr('prefsEval') === '1';

        return [
            'confirmed' => DB::table('brewing')->where('brewConfirmed', 1)->count(),
            'unconfirmed' => DB::table('brewing')->where('brewConfirmed', '<>', 1)->count(),
            'paid' => DB::table('brewing')->where('brewPaid', 1)->count(),
            'paidReceived' => DB::table('brewing')->where('brewPaid', 1)->where('brewReceived', 1)->count(),
            'entryLimit' => $ctx->prefsStr('prefsEntryLimit'),
            'paidLimit' => $ctx->prefsStr('prefsEntryLimitPaid'),
            'fees' => $fees,
            'feesPaid' => $feesPaid,
            'tablesPlanning' => (string) ($ctx->judgingStr('jPrefsTablePlanning') ?? '0') === '1',
            'evalsOn' => $evalsOn,
            'evalTotal' => $evalsOn ? DB::table('evaluation')->count() : 0,
            'evalEntries' => $evalsOn ? DB::table('evaluation')->distinct()->count('eid') : 0,
            'participants' => DB::table('brewer')->count(),
            'participantsWithEntries' => DB::table('brewing')->distinct()->count('brewBrewerId'),
            'judges' => DB::table('brewer')->where('brewerJudge', 'Y')->count(),
            'judgesAssigned' => DB::table('staff')->where('staff_judge', 1)->count(),
            'judgeCap' => $ctx->judgingStr('jPrefsCapJudges'),
            'stewards' => DB::table('brewer')->where('brewerSteward', 'Y')->count(),
            'stewardsAssigned' => DB::table('staff')->where('staff_steward', 1)->count(),
            'stewardCap' => $ctx->judgingStr('jPrefsCapStewards'),
            'staff' => DB::table('brewer')->where('brewerStaff', 'Y')->count(),
            'staffAssigned' => DB::table('staff')->where('staff_staff', 1)->count(),
            'organizer' => DB::table('staff')
                ->join('brewer', 'brewer.uid', '=', 'staff.uid')
                ->where('staff_organizer', 1)
                ->first(['brewerFirstName', 'brewerLastName']),
            'windows' => [
                'entry' => $windows->entry === WindowState::Open,
                'dropoff' => $windows->dropoff === WindowState::Open,
                'shipping' => $windows->shipping === WindowState::Open,
                'registration' => $windows->registration === WindowState::Open,
                'judge' => $windows->judge === WindowState::Open,
            ],
            // sidebar.admin.php tail: server environment line
            'phpVersion' => PHP_VERSION,
            'dbVersion' => (string) (DB::selectOne('SELECT VERSION() AS v')->v ?? ''),
            'updated' => DateFmt::dateTime(
                $now,
                $ctx->prefs['prefsTimeZone'] ?? null,
                $ctx->prefsStr('prefsDateFormat'),
                $ctx->prefsStr('prefsTimeFormat'),
            ),
            'currencySymbol' => $ctx->currencySymbol(),
            // default.admin.php action-row gates
            'judgingStarted' => $judgingStarted,
            'judgingPast' => $judgingPast,
            'postCompTasks' => ! $judgingPast && $now >= (int) ($ctx->prefsStr('prefsWinnerDelay') ?: 0),
            'winnersPublished' => ($ctx->prefsStr('prefsDisplayWinners') ?? 'N') === 'Y',
            'showBest' => ((int) ($ctx->prefsStr('prefsShowBestBrewer') ?? 0) !== 0
                || (int) ($ctx->prefsStr('prefsShowBestClub') ?? 0) !== 0) && $judgingStarted,
            'bestBrewers' => $judgingStarted ? ResultsRepository::current()->bestBrewers(
                (string) $ctx->prefsStr('prefsBestBrewerPointsMethod'),
                'flat',
            ) : [],
        ];
    }

    /**
     * Admin dashboard panels in default.admin.php order and shape. Each
     * section: [title, icon, help, categories]. Each category:
     * [categoryLabel, items] where each item is one of:
     *   ['label', 'href']                          active link
     *   ['label', null, 'todo']                    disabled (backend missing)
     *   ['label', null, null, children]            family (per-count / per-row dropdown)
     *
     * @return array{left: list<array>, right: list<array>}
     */
    private function sections(int $level, int $obfuscate, array $prefs, array $counts): array
    {
        $l = static fn (string $href, string $label): array => ['label' => $label, 'href' => $href];
        $todo = static fn (string $label, string $src): array => ['label' => $label, 'href' => null, 'todo' => 'TODO: legacy output — '.$src];
        $family = static fn (string $label, array $children, string $descriptor = 'labels per entry'): array => ['label' => $label, 'children' => $children, 'descriptor' => $descriptor];

        // A legacy `for($i=1;$i<=12;$i++)` label-count dropdown. The bottle
        // and box label families all route through /admin/output/labels, so
        // each count maps to the port route with the `section=labels-admin`
        // segment dropped (it selects that route).
        $countFamily = fn (string $label, string $hrefTemplate): array => [
            'label' => $label,
            'children' => collect(range(1, 12))->map(
                fn (int $i): array => ['label' => (string) $i,
                    'href' => '/admin/output/labels?'.str_replace('section=labels-admin&', '', $hrefTemplate).'&sort='.$i],
            )->all(),
        ];

        $level0 = $level === 0;
        $barcodes = $prefs['barcodes'];
        $tables = $counts['tables'];

        $left = [];

        if ($level0) {
            $left[] = [
                'Competition Preparation', 'fa-wrench',
                'Your competition&#39;s vital information is managed and maintained here. Manage all dates, contacts, custom categories, drop-off locations, judging and non-judging sessions, sponsors, and accepted styles and style types.',
                [
                    ['All Competition Dates', [$l('/admin/dates', 'Edit')]],
                    ['Competition Info', [$l('/admin/competition-info', 'Edit'), $l('/admin/upload?action=html', 'Upload Logo')]],
                    ['Contacts', [$l('/admin/contacts', 'Manage'), $l('/admin/contacts/create', 'Add')]],
                    ['Custom Categories', [$l('/admin/judging/special-best', 'Manage'), $l('/admin/judging/special-best/create', 'Add')]],
                    ['Drop-Off Locations', [$l('/admin/dropoff', 'Manage'), $l('/admin/dropoff/create', 'Add')]],
                    ['Judging Sessions', [$l('/admin/judging/locations', 'Manage'), $l('/admin/judging/locations?action=add', 'Add')]],
                    ['Non-Judging Sessions', [$l('/admin/judging/non-judging', 'Manage'), $l('/admin/judging/non-judging/create', 'Add')]],
                    ['Sponsors', [$l('/admin/sponsors', 'Manage'), $l('/admin/sponsors/create', 'Add'), $l('/admin/upload?action=html', 'Upload Logos')]],
                    ['Styles Accepted', [$l('/admin/styles', 'Manage'), $l('/admin/styles/create', 'Add')]],
                    ['Style Types', [$l('/admin/style-types', 'Manage'), $l('/admin/style-types/create', 'Add')]],
                ],
            ];
        }

        // Entries, Payments, and Participants — legacy Entries/Payments and Participants.
        $entriesItems = [];
        $entriesItems[] = ['Entries', [$l('/backoffice/entries', 'Manage'), $l('/backoffice/entries?view=paid', 'Paid')]];
        if ($prefs['paypalIpn']) {
            $entriesItems[] = ['Payments', [$l('/admin/payments', 'Manage')]];
        }
        $participantLinks = [$l('/backoffice/participants', 'Manage')];
        if ($level0) {
            array_push(
                $participantLinks,
                $l('/admin/judging/tables?action=assign&filter=judges', 'Assign/Unassign Judges'),
                $l('/admin/judging/tables?action=assign&filter=stewards', 'Assign/Unassign Stewards'),
                $l('/admin/judging/tables?action=assign&filter=staff', 'Assign/Unassign Staff'),
            );
        } else {
            array_push(
                $participantLinks,
                $l('/admin/judging/tables?action=assign&filter=judges', 'Assign/Unassign Judges'),
                $l('/admin/judging/tables?action=assign&filter=stewards', 'Assign/Unassign Stewards'),
            );
        }
        $entriesItems[] = ['Participants', $participantLinks];
        $entriesItems[] = ['Register', [
            $l('/register/entrant', 'A Participant'),
            $l('/register/judge?view=quick', 'A Judge (Quick)'),
            $l('/register/judge', 'A Judge (Standard)'),
            $l('/register/steward?view=quick', 'A Steward (Quick)'),
            $l('/register/steward', 'A Steward (Standard)'),
        ]];

        $left[] = ['Entries, Payments, and Participants', 'fa-beer',
            'Everything to manage your competition entries and associated participants. Add, edit, or delete user accounts, register, designate, and assign judges, stewards, and staff.',
            $entriesItems,
        ];

        // Entry Sorting.
        $sortItems = [];
        if ($obfuscate === 0) {
            $sortItems[] = ['Regenerate', [
                ['label' => 'Judging Numbers (Random)', 'modal' => 'jn-random-modal'],
                ['label' => 'Judging Numbers (With Style Number Prefix)', 'modal' => 'jn-style-modal'],
                ['label' => 'Judging Numbers (Same as Entry Numbers)', 'modal' => 'jn-entry-modal'],
            ]];
            if ($barcodes) {
                $sortItems[] = ['Using Barcodes/QR Codes?', [
                    ['label' => 'Download Barcode and Round Judging Number Labels', 'href' => 'http://brewingcompetitions.com/barcode-labels'],
                ]];
            }
        }
        $checkIn = [$l('/backoffice/entries', 'Manually')];
        if ($obfuscate === 0) {
            if ($barcodes) {
                $checkIn[] = ['label' => 'Via Mobile Devices', 'href' => '/qr', 'target' => '_blank'];
            }
            $checkIn[] = $l('/admin/judging/checkin', 'Via Barcode Scanner (Entry/Judging Numbers Only)');
            $checkIn[] = $l('/admin/judging/checkin?filter=box-paid', 'Via Barcode Scanner (Entry/Judging Numbers, Box, and Paid)');
        }
        $sortItems[] = ['Entry Check-In', $checkIn];

        // Sorting Sheets + Sorting Into Tables (legacy default.admin.php:910-936).
        if ($obfuscate === 0) {
            $sortItems[] = ['Sorting Sheets', [
                $l('/admin/output/table_cards?psort=sorting-placards&view=master-list', 'Sorting Placards'),
                $l('/admin/output/sorting?go=default&filter=default&view=entry', 'Entry Numbers'),
                $l('/admin/output/sorting?go=default&filter=default', 'Judging Numbers'),
                $l('/admin/output/sorting?go=cheat&filter=default', 'Cheat Sheets'),
            ]];
            $sortItems[] = ['Sorting Into Tables', [
                $l('/admin/output/table_cards?psort=sorting-tables&view=master-list', 'Tables and Associated Styles Master List'),
                $l('/admin/output/table_cards?psort=sorting-tables', 'Tables and Associated Styles Placards'),
            ]];
        }

        // Print Bottle Labels (PDF) — label-template matrix (legacy labels-admin).
        if ($obfuscate === 0) {
            $bottle = [];
            $bottle[] = $family('Letter (Avery 5160) — Entry Numbers', $countFamily('Entry Numbers', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&psort=5160')['children']);
            $bottle[] = $family('Letter (Avery 5160) — Judging Numbers', $countFamily('Judging Numbers', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&psort=5160')['children']);
            $bottle[] = $family('Letter (Avery 5160) — With Required Info, All Styles (Entry Numbers)', $countFamily('With Required Info, All Styles (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=all&psort=5160')['children']);
            $bottle[] = $family('Letter (Avery 5160) — With Required Info, Only Styles Where Required (Entry Numbers)', $countFamily('With Required Info, Only Styles Where Required (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=special&psort=5160')['children']);
            $bottle[] = $family('Letter (Avery 5160) — With Required Info, All Styles (Judging Numbers)', $countFamily('With Required Info, All Styles (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=all&psort=5160')['children']);
            $bottle[] = $family('Letter (Avery 5160) — With Required Info, Only Styles Where Required (Judging Numbers)', $countFamily('With Required Info, Only Styles Where Required (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=special&psort=5160')['children']);
            $bottle[] = $family('A4 (Avery 3422) — Entry Numbers', $countFamily('Entry Numbers', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&psort=3422')['children']);
            $bottle[] = $family('A4 (Avery 3422) — Judging Numbers', $countFamily('Judging Numbers', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&psort=3422')['children']);
            $bottle[] = $family('A4 (Avery 3422) — With Required Info, All Styles (Entry Numbers)', $countFamily('With Required Info, All Styles (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=all&psort=3422')['children']);
            $bottle[] = $family('A4 (Avery 3422) — With Required Info, Only Styles Where Required (Entry Numbers)', $countFamily('With Required Info, Only Styles Where Required (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=special&psort=3422')['children']);
            $bottle[] = $family('A4 (Avery 3422) — With Required Info, All Styles (Judging Numbers)', $countFamily('With Required Info, All Styles (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=all&psort=3422')['children']);
            $bottle[] = $family('A4 (Avery 3422) — With Required Info, Only Styles Where Required (Judging Numbers)', $countFamily('With Required Info, Only Styles Where Required (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=special&psort=3422')['children']);
            $bottle[] = $family('Letter (Avery 5160) — Received Only (Entry Numbers)', $countFamily('Received Only (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=special&psort=5160&tb=received')['children']);
            $bottle[] = $family('Letter (Avery 5160) — Received Only (Judging Numbers)', $countFamily('Received Only (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=special&psort=5160&tb=received')['children']);
            $bottle[] = $family('A4 (Avery 3422) — Received Only (Entry Numbers)', $countFamily('Received Only (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=special&psort=3422&tb=received')['children']);
            $bottle[] = $family('A4 (Avery 3422) — Received Only (Judging Numbers)', $countFamily('Received Only (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=special&psort=3422&tb=received')['children']);
            $bottle[] = $family('Round (Avery OL5275WR) — All Entries', $countFamily('All Entries', 'section=labels-admin&go=entries&action=bottle-category-round&filter=default&psort=OL5275WR')['children']);
            $bottle[] = $family('Round (Avery OL5275WR) — Entries Added By Admins', $countFamily('Entries Added By Admins', 'section=labels-admin&go=entries&action=bottle-judging-round&filter=recent&psort=OL5275WR')['children']);
            $bottle[] = $family('Round (Avery OL32) — Entry Numbers', $countFamily('Entry Numbers', 'section=labels-admin&go=entries&action=bottle-entry-round&filter=default&psort=OL32')['children']);
            $bottle[] = $family('Round (Avery OL32) — Judging Numbers', $countFamily('Judging Numbers', 'section=labels-admin&go=entries&action=bottle-judging-round&filter=default&psort=OL32')['children']);
            $bottle[] = $family('Round (Avery OL32) — Category Only', $countFamily('Category Only', 'section=labels-admin&go=entries&action=bottle-category-round&filter=default&psort=OL32')['children']);
            $bottle[] = $family('Round (Avery OL32) — Added After Reg Close (Entry Numbers)', $countFamily('Added After Reg Close (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry-round&filter=recent&psort=OL32')['children']);
            $bottle[] = $family('Round (Avery OL5275WR) — Entry Numbers', $countFamily('Entry Numbers', 'section=labels-admin&go=entries&action=bottle-entry-round&filter=default&psort=OL5275WR')['children']);
            $bottle[] = $family('Round (Avery OL5275WR) — Judging Numbers', $countFamily('Judging Numbers', 'section=labels-admin&go=entries&action=bottle-judging-round&filter=default&psort=OL5275WR')['children']);
            $bottle[] = $family('Round (Avery OL5275WR) — Added After Reg Close (Entry Numbers)', $countFamily('Added After Reg Close (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry-round&filter=recent&psort=OL5275WR')['children']);
            $bottle[] = $l('/admin/output/labels?go=entries&action=bottle-entry&filter=default&psort=5160', 'Entry Numbers');
            $bottle[] = $l('/admin/output/labels?go=entries&action=bottle-judging&filter=default&psort=5160', 'Judging Numbers');
            $bottle[] = $l('/admin/output/labels?go=entries&action=bottle-entry&filter=default&psort=3422', 'Entry Numbers');
            $bottle[] = $l('/admin/output/labels?go=entries&action=bottle-judging&filter=default&psort=3422', 'Judging Numbers');
            $bottle[] = $l('/admin/output/labels?go=entries&action=bottle-judging&filter=default&view=quicksort&psort=5167', 'Quicksort — 6 Labels per Entry');
            $bottle[] = $l('/admin/output/labels?go=entries&action=bottle-judging&filter=default&view=quicksort&psort=5167&tb=short', 'Quicksort — 3 Labels per Entry');
            $sortItems[] = ['Print Bottle Labels (PDF)', $bottle];

            // Print Box Labels (PDF).
            $box = [];
            $box[] = $family('Letter (Avery 5160) — Box Labels (by Table)', $countFamily('Box Labels (by Table)', 'section=labels-admin&go=judging_tables')['children']);
            $box[] = $family('Letter (Avery 5160) — Virtual Judging Box Labels (by Judge Name)', $countFamily('Virtual Judging Box Labels (by Judge Name)', 'section=labels-admin&go=judging_tables&filter=judges')['children']);
            $box[] = $family('A4 (Avery 3422) — Box Labels (by Table)', $countFamily('Box Labels (by Table)', 'section=labels-admin&go=judging_tables&psort=3422')['children']);
            $box[] = $family('A4 (Avery 3422) — Virtual Judging Box Labels (by Judge Name)', $countFamily('Virtual Judging Box Labels (by Judge Name)', 'section=labels-admin&go=judging_tables&filter=judges&psort=3422')['children']);
            $sortItems[] = ['Print Box Labels (PDF)', $box];
        }

        $left[] = ['Entry Sorting', 'fa-exchange',
            'Everything you need to help you with sorting received entries from participants. Check-in entries and print sorting sheets.',
            $sortItems,
        ];

        // Organizing.
        $orgItems = [
            ['Assign/Unassign', [
                $l('/admin/judging/tables?action=assign&filter=judges', 'Judges'),
                $l('/admin/judging/tables?action=assign&filter=stewards', 'Stewards'),
                $l('/admin/judging/tables?action=assign&filter=staff', 'Staff'),
            ]],
            ['Tables', array_merge(
                [$l('/admin/judging/tables', 'Manage'), $l('/admin/judging/tables/create', 'Add')],
                $tables > 1 ? [$l('/admin/judging/tables?action=assign', 'Assign Judges/Stewards')] : [],
            )],
            ['Flights', [$l('/admin/judging/flights', 'Manage'), $l('/admin/judging/flights', 'Add')]],
            ['BOS Judges', [$l('/admin/judging/tables?action=assign&filter=bos', 'Add')]],
        ];
        $left[] = ['Organizing', 'fa-tasks',
            'Post-sort vital functions like assigning personnel as judges, stewards, and/or staff, defining table/medal group configurations, assigning judges and stewards to tables/medal groups, and designating best of show judges.',
            $orgItems,
        ];

        // Scoring.
        $scoreItems = [
            ['Scoresheets and Docs', [
                $l('/admin/upload-scoresheets', 'Upload Multiple'),
                $l('/admin/upload-scoresheets?action=html', 'Upload Individually'),
            ]],
        ];
        if ($obfuscate === 0) {
            $scoreItems[] = ['Entry Evaluations', [$l('/eval', 'Manage')]];
        }
        $scoreLinks = [$l('/admin/judging/scores', 'Manage')];
        if ($prefs['eval']) {
            $scoreLinks[] = $todo('Import Scores', 'import_scores.eval.php modal');
        }
        $scoreItems[] = ['Scores', $scoreLinks];
        // "Add Scores to..." dropdown (legacy score_table_choose,
        // lib/admin.lib.php:445): per-table add/edit link.
        $scoreAddItems = DB::table('judging_tables')->orderBy('tableNumber')
            ->get(['id', 'tableNumber', 'tableName'])
            ->map(fn ($t) => $l('/admin/judging/scores?action=add&id='.$t->id, 'Table '.$t->tableNumber.': '.$t->tableName))
            ->all();
        if ($scoreAddItems !== []) {
            $scoreItems[] = ['Add Scores to...', $scoreAddItems];
        }
        // "Add Entries to..." dropdown (legacy score_custom_winning_choose,
        // lib/admin.lib.php:474): per special-best category, add when no
        // data rows exist yet, edit otherwise.
        $customEntries = DB::table('special_best_info')->orderBy('sbi_name')
            ->get(['id', 'sbi_name'])
            ->map(function ($sbi) use ($l): array {
                $has = DB::table('special_best_data')->where('sid', $sbi->id)->exists();

                return ['label' => (string) $sbi->sbi_name,
                    'href' => '/admin/judging/special-best-data?action='.($has ? 'edit' : 'add').'&id='.$sbi->id];
            })->all();
        if ($customEntries !== []) {
            $scoreItems[] = $family('Add Entries to...', $customEntries, '');
        }
        if ($level0 || $obfuscate === 0) {
            $scoreItems[] = ['BOS Entries and Places', [$l('/admin/judging/bos', 'Manage')]];
        }
        if ($level0) {
            $scoreItems[] = ['Custom Categories', [$l('/admin/judging/special-best-data', 'Manage')]];
        }
        $left[] = ['Scoring', 'fa-trophy',
            'Manage all functions related to evaluating and scoring participant entries for all stages of judging.',
            $scoreItems,
        ];

        // ---- Right column ----
        $reportsItems = [];

        // Before Judging.
        $reportsItems[] = ['Staff Availability', [
            $l('/admin/output/assignments?filter=staff&view=name', 'By Last Name'),
            $l('/admin/output/assignments?filter=staff', 'By Non-Judging Session'),
        ]];
        $reportsItems[] = ['Notes', [
            $l('/admin/output/judge_notes?go=org_notes', 'Notes to Organizer'),
            $l('/admin/output/judge_notes?go=admin', 'Admin and Staff Notes'),
        ]];
        if ($obfuscate === 0) {
            $reportsItems[] = ['Allergens', [$l('/admin/output/judge_notes?go=allergens', 'Possible Allergens in Entries')]];
        }
        $reportsItems[] = ['Drop-Off and Shipping', [
            $l('/admin/output/dropoff', 'Entry Totals'),
            $l('/admin/output/dropoff?go=check', 'List of Entries'),
        ]];
        if ($tables > 0 && $obfuscate === 0) {
            $reportsItems[] = ['Additional Info', [
                $l('/admin/output/pullsheets?go=all_entry_info&view=entry&id=default', 'All By Table - Entry Numbers'),
                $l('/admin/output/pullsheets?go=all_entry_info&id=default', 'All By Table - Judging Numbers'),
            ]];
            $reportsItems[] = ['Judge Inventories', [
                $l('/admin/output/pullsheets?go=all_entry_info&view=judge_inventory&filter=J', 'Entries for Session...'),
                $l('/admin/output/pullsheets?go=all_entry_info&view=judge_inventory&filter=J&sort=entry', 'Judging Numbers for Session...'),
            ]];
        }
        $tableCardPerTable = DB::table('judging_tables')->orderBy('tableNumber')->get()
            ->map(fn ($t) => $l('/admin/output/table_cards?id='.$t->id, 'Table '.$t->tableNumber.': '.$t->tableName))->all();
        $reportsItems[] = ['Table Cards', [
            $l('/admin/output/table_cards', 'All Tables'),
            $l('/admin/output/table_cards?psort=sorting-placards', 'Sorting Placards'),
            $l('/admin/output/table_cards?psort=sorting-placards&view=master-list', 'Sorting Placards (Master List)'),
            $l('/admin/output/table_cards?psort=sorting-tables', 'Sorting Tables'),
            $l('/admin/output/table_cards?psort=sorting-tables&view=master-list', 'Sorting Tables (Master List)'),
            $l('/admin/output/table_cards?id=1', 'For Table...'),
            $family('For Table (choose)...', $tableCardPerTable, ''),
            $family('For Session...', DB::table('judging_locations')->orderBy('id')->get()
                ->flatMap(fn ($loc) => collect(range(1, max(1, (int) $loc->judgingRounds)))
                    ->map(fn (int $round) => $l('/admin/output/table_cards?go=judging_locations&location='.$loc->id.'&round='.$round, (string) $loc->judgingLocName.' - Round '.$round)))->all(), ''),
        ]];
        $reportsItems[] = ['Sign In Sheets', [
            $l('/admin/output/assignments?filter=judges&view=sign-in', 'Judges'),
            $l('/admin/output/assignments?filter=stewards&view=sign-in', 'Stewards'),
        ]];
        if ($tables > 0) {
            $judgeSessionLinks = DB::table('judging_locations')->orderBy('id')->get()
                ->flatMap(fn ($loc) => [
                    $l('/admin/output/assignments?filter=judges&location='.$loc->id.'&view=name', $loc->judgingLocName.' By Name'),
                    $l('/admin/output/assignments?filter=judges&location='.$loc->id.'&view=table', $loc->judgingLocName.' By Table'),
                ])->all();
            $stewardSessionLinks = DB::table('judging_locations')->orderBy('id')->get()
                ->flatMap(fn ($loc) => [
                    $l('/admin/output/assignments?filter=stewards&location='.$loc->id.'&view=name', $loc->judgingLocName.' By Name'),
                    $l('/admin/output/assignments?filter=stewards&location='.$loc->id.'&view=table', $loc->judgingLocName.' By Table'),
                ])->all();
            $reportsItems[] = ['Assignments', [
                $l('/admin/output/assignments?filter=judges&view=name', 'All Judges By Last Name'),
                $l('/admin/output/assignments?filter=judges&view=table', 'All Judges By Table'),
                $l('/admin/output/assignments?filter=judges&view=location', 'All Judges By Session'),
                $family('Judges for Session...', $judgeSessionLinks, ''),
                $l('/admin/output/assignments?filter=stewards&view=name', 'All Stewards Last Name'),
                $l('/admin/output/assignments?filter=stewards&view=table', 'All Stewards By Table'),
                $l('/admin/output/assignments?filter=stewards&view=location', 'All Stewards By Session'),
                $family('Stewards for Session...', $stewardSessionLinks, ''),
            ]];
        }
        $reportsItems[] = ['Judge Scoresheet Labels', [
            $l('/admin/output/labels?go=participants&action=judging_labels&psort=5160', 'Letter'),
            $l('/admin/output/labels?go=participants&action=judging_labels&psort=3422', 'A4'),
        ]];
        if ($obfuscate === 0) {
            $reportsItems[] = ['Name Tags', [
                $l('/admin/output/labels?go=participants&action=judging_nametags&psort=5395', 'Letter'),
            ]];
        }

        // During Judging (tables>0 && obfuscate 0).
        if ($tables > 0 && $obfuscate === 0) {
            $reportsItems[] = ['Mini-BOS Pullsheets', [
                $l('/admin/output/pullsheets?go=mini_bos&view=entry', 'All - Entry Numbers'),
                $l('/admin/output/pullsheets?go=judging_tables&view=entry&filter=mini_bos&id=default', 'All By Table - Entry Numbers'),
                $l('/admin/output/pullsheets?go=mini_bos', 'All - Judging Numbers'),
                $l('/admin/output/pullsheets?go=judging_tables&filter=mini_bos&id=default', 'All By Table - Judging Numbers'),
            ]];
            $reportsItems[] = ['Mini-BOS Cup Mats', [
                $l('/admin/output/bos_mat?action=blank&view=mini-bos', 'Blank (Mini-BOS)'),
                $l('/admin/output/bos_mat?action=blank&view=pro-am', 'Blank (Pro-Am)'),
                $l('/admin/output/bos_mat?action=blank', 'Blank'),
                $l('/admin/output/bos_mat?action=mini-bos&filter=entry', 'All Tables - Entry Numbers'),
                $family('For Table...', DB::table('judging_tables')->orderBy('tableNumber')->get()->flatMap(fn ($t) => [$l('/admin/output/bos_mat?action=mini-bos&view='.$t->id.'&filter=entry', (string) $t->tableNumber.' (Entry)'), $l('/admin/output/bos_mat?action=mini-bos&view='.$t->id, (string) $t->tableNumber.' (Judging)')])->all(), ''),
                $l('/admin/output/bos_mat?action=mini-bos', 'All Tables - Judging Numbers'),
            ]];
        $bosStyleTypes = DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get();
            $reportsItems[] = ['BOS Pullsheets', [
                $l('/admin/output/pullsheets?go=judging_scores_bos&view=entry', 'All Style Types - Entry Numbers'),
                $family('For Style Type...', $bosStyleTypes->map(fn ($t) => $l('/admin/output/pullsheets?go=judging_scores_bos&view=entry&id='.$t->id, $t->styleTypeName))->all(), ''),
                $l('/admin/output/pullsheets?go=judging_scores_bos', 'All Style Types - Judging Numbers'),
                $family('For Style Type... (Judging)', $bosStyleTypes->map(fn ($t) => $l('/admin/output/pullsheets?go=judging_scores_bos&id='.$t->id, $t->styleTypeName))->all(), ''),
            ]];
            $reportsItems[] = ['BOS Cup Mats', [
                $l('/admin/output/bos_mat?filter=entry', 'All Style Types - Entry Numbers'),
                $family('For Style Type...', $bosStyleTypes->map(fn ($t) => $l('/admin/output/bos_mat?view='.$t->id.'&filter=entry', $t->styleTypeName))->all(), ''),
                $l('/admin/output/bos_mat', 'All Style Types - Judging Numbers'),
                $family('For Style Type... (Judging)', $bosStyleTypes->map(fn ($t) => $l('/admin/output/bos_mat?view='.$t->id, $t->styleTypeName))->all(), ''),
                $family('Pro-Am...', collect(range(1, 3))->flatMap(fn ($sort) => $bosStyleTypes->flatMap(fn ($t) => [$l('/admin/output/bos_mat?action=pro-am&sort='.$sort.'&view='.$t->id.'&filter=entry', $t->styleTypeName.' ('.$sort.')'), $l('/admin/output/bos_mat?action=pro-am&sort='.$sort.'&view='.$t->id, $t->styleTypeName.' ('.$sort.')')])->all())->all(), ''),
            ]];
        // Legacy ps_loc_* session dropdowns: per-location × per-round
        // children, entry/judging number variants.
        $sessionEntry = [];
        $sessionJudging = [];
        foreach (DB::table('judging_locations')->orderBy('id')->get() as $loc) {
            foreach (range(1, max(1, (int) $loc->judgingRounds)) as $round) {
                $name = (string) $loc->judgingLocName.' - Round '.$round;
                $sessionEntry[] = $l('/admin/output/pullsheets?go=judging_locations&view=entry&location='.$loc->id.'&round='.$round, $name);
                $sessionJudging[] = $l('/admin/output/pullsheets?go=judging_locations&view=default&location='.$loc->id.'&round='.$round, $name);
            }
        }
            $reportsItems[] = ['Pullsheets', [
                $l('/admin/output/pullsheets', 'All By Table'),
                $l('/admin/output/pullsheets?go=judging_tables&id=default&view=entry', 'All By Table - Entry Numbers'),
                $family('Entry Numbers for Session...', $sessionEntry, ''),
                $family('Judging Numbers for Session...', $sessionJudging, ''),
                $family('Judging Numbers for Table...', DB::table('judging_tables')->orderBy('tableNumber')->get()
                    ->map(fn ($t) => $l('/admin/output/pullsheets?go=judging_tables&id='.$t->id, (string) $t->tableNumber))->all(), ''),
                $family('Mini-BOS per Table...', DB::table('judging_tables')->orderBy('tableNumber')->get()->flatMap(fn ($t) => [$l('/admin/output/pullsheets?go=judging_tables&action=default&filter=mini_bos&id='.$t->id.'&view=entry', (string) $t->tableNumber.' (Entry)'), $l('/admin/output/pullsheets?go=judging_tables&action=default&filter=mini_bos&id='.$t->id.'&view=default', (string) $t->tableNumber.' (Judging)')])->all(), ''),
                $family('Mini-BOS per Location...', DB::table('judging_locations')->orderBy('id')->get()->flatMap(fn ($t) => [$l('/admin/output/pullsheets?go=judging_locations&filter=mini_bos&location='.$t->id.'&round=1&view=entry', (string) $t->judgingLocName.' (Entry)'), $l('/admin/output/pullsheets?go=judging_locations&filter=mini_bos&location='.$t->id.'&round=1&view=default', (string) $t->judgingLocName.' (Judging)')])->all(), ''),
                $family('Judge Inventory per Location...', DB::table('judging_locations')->orderBy('id')->get()->flatMap(fn ($t) => [$l('/admin/output/pullsheets?go=all_entry_info&filter=J&location='.$t->id.'&view=judge_inventory', (string) $t->judgingLocName), $l('/admin/output/pullsheets?go=all_entry_info&filter=J&location='.$t->id.'&view=judge_inventory&sort=entry', (string) $t->judgingLocName.' (Entry)')])->all(), ''),
                $family('Pro-Am per Style Type...', collect(range(1, 3))->flatMap(fn ($f) => $bosStyleTypes->map(fn ($t) => [$l('/admin/output/pullsheets?go=judging_scores_bos&action=pro-am&filter='.$f.'&id='.$t->id.'&view=entry', $t->styleTypeName.' ('.$f.')'), $l('/admin/output/pullsheets?go=judging_scores_bos&action=pro-am&filter='.$f.'&id='.$t->id, $t->styleTypeName.' ('.$f.')')])->all())->flatten(1)->all(), ''),
            ]];
        }

        // After Judging.
        $reportsItems[] = ['Award Labels', [
            $l('/admin/output/labels?go=judging_scores&action=awards&filter=default&psort=5160', 'Letter'),
            $l('/admin/output/labels?go=judging_scores&action=awards&filter=default&psort=3422', 'A4'),
        ]];
        $reportsItems[] = ['Winner Address Labels', [
            $l('/admin/output/labels?go=judging_scores&action=awards&filter=address&psort=5160', 'Letter'),
            $l('/admin/output/labels?go=judging_scores&action=awards&filter=address&psort=3422', 'A4'),
        ]];
        $reportsItems[] = ['Address Labels', [
            $l('/admin/output/labels?go=participants&action=address_labels&filter=default&psort=3422', 'Address Labels — A4 (All)'),
            $l('/admin/output/labels?go=participants&action=address_labels&filter=with_entries&psort=5160', 'Address Labels — Letter (With Entries)'),
            $l('/admin/output/labels?go=participants&action=address_labels&filter=with_entries&psort=3422', 'Address Labels — A4 (With Entries)'),
            $l('/admin/output/labels?go=participants&action=address_labels', 'Address Labels'),
        ]];
        $reportsItems[] = ['Summaries', [
            $l('/admin/output/participant_summary', 'Participant Summaries'),
        ]];
        $reportsItems[] = ['Participant Entries List', [
            $l('/admin/output/participant_entries_list', 'Participant Entries List (Address)'),
        ]];
        $reportsItems[] = ['BJCP Points', [
            $l('/admin/output/staff_points', 'Print'),
            $l('/admin/output/staff_points?action=download&view=pdf', 'PDF'),
        ]];
        $reportsItems[] = ['Inventory', [
            $l('/admin/output/post_judge_inventory?go=scores', 'With Scores'),
            $l('/admin/output/post_judge_inventory', 'Without Scores'),
        ]];
        $reportsItems[] = ['BOS Results', [
            $l('/admin/output/results?go=judging_scores_bos&action=print&tb=bos&view=default', 'Print'),
            $l('/admin/output/results?go=judging_scores_bos&action=download&view=pdf', 'PDF'),
            $l('/admin/output/results?go=judging_scores_bos&action=download&view=html', 'HTML'),
        ]];
        if ($prefs['showBestBrewer'] || $prefs['showBestClub']) {
            $reportsItems[] = ['Best Brewer'.($prefs['proEdition'] === 0 ? ' and/or Club' : ''), [
                $l('/admin/output/results?go=best&action=print&filter=default&view=default', 'Print'),
                $l('/admin/output/results?go=best&action=print&view=default', 'Print (No Filter)'),
            ]];
        }
        $resultsLink = function (string $go, string $view, string $tb = '', string $psort = '', string $filter = '') use ($l): array {
            $q = ['go' => $go, 'action' => 'print'];
            if ($tb !== '') { $q['tb'] = $tb; }
            if ($filter !== '') { $q['filter'] = $filter; }
            if ($psort !== '') { $q['psort'] = $psort; }
            $q['view'] = $view;
            $label = trim($go.($tb ? ' | '.$tb : '').($filter ? ' | '.$filter : '').($psort ? ' | '.$psort : '').' | '.$view);

            return $l('/admin/output/results?'.http_build_query($q), $label);
        };
        $reportLinks = [];
        foreach (['judging_scores', 'all'] as $rg) {
            foreach (['default', 'winners'] as $rv) {
                foreach (['', 'scores'] as $rtb) {
                    foreach (['', 'table-entry-count-asc', 'table-entry-count-desc'] as $rp) {
                        $reportLinks[] = $resultsLink($rg, $rv, $rtb, $rp);
                    }
                }
            }
        }
        $reportLinks[] = $l('/admin/output/results?go=judging_scores&action=print&filter=scores&view=winners', 'judging_scores | scores | winners (filter)');
        $reportLinks[] = $l('/admin/output/results?go=judging_scores&action=print&filter=none&view=winners', 'judging_scores | none | winners (filter)');
        $reportsItems[] = ['Results ('.$this->resultsMethodLabel($prefs['winnerMethod']).')', [
            ...$reportLinks,
            $l('/admin/output/results?action=default&go=judging_scores&tb=none&view=pdf', 'PDF report'),
            $l('/admin/output/results?action=default&go=judging_scores&tb=none&view=html', 'HTML report'),
        ]];

        $right = [['Reports', 'fa-file',
            'A wide range of reports is available for all stages of your competition - before, during, and after your designated judging sessions.',
            $reportsItems,
        ]];

        // Data Exports.
        $dataExportItems = [];
        $emailCsv = [
            $l('/admin/output/export?go=csv&filter=avail_judges&action=email', 'Available Judges'),
            $l('/admin/output/export?go=csv&filter=avail_stewards&action=email', 'Available Stewards'),
            $l('/admin/output/export?go=csv&filter=judges&action=email', 'Assigned Judges'),
            $l('/admin/output/export?go=csv&filter=stewards&action=email', 'Assigned Stewards'),
            $l('/admin/output/export?go=csv&filter=staff&action=email', 'Available and Assigned Staff'),
        ];
        $participantCsv = [
            $l('/admin/output/export?go=csv&action=participants', 'All Participants'),
            $l('/admin/output/export?go=csv&tb=winners', 'Winners: Limited Data'),
            $l('/admin/output/export?go=csv&tb=circuit', 'Winners: Circuit Data'),
            $l('/admin/output/export?filter=mhp&go=csv&tb=circuit', 'Winners: Master Homebrewer Program Member Data'),
        ];
        $entriesCsv = [
            $l('/admin/output/export?go=csv&action=all&tb=all', 'All Entries: All Data'),
            $l('/admin/output/export?go=csv', 'All Entries: Limited Data'),
            $l('/admin/output/export?go=csv&tb=brewer_contact_info', 'All Entries: Limited Data with Participant Contact Info'),
            $l('/admin/output/export?go=csv&tb=paid&view=all', 'Paid Entries'),
            $l('/admin/output/export?go=csv&tb=paid', 'Paid & Received Entries'),
            $l('/admin/output/export?go=csv&tb=paid&view=not_received', 'Paid Entries Not Received'),
            $l('/admin/output/export?go=csv&tb=nopay&view=all', 'Non-Paid Entries'),
            $l('/admin/output/export?go=csv&tb=nopay', 'Non-Paid & Received Entries'),
            $l('/admin/output/export?action=required&go=csv&tb=required', 'Entries with Required & Optional Info'),
        ];
        $dataExportItems[] = ['Email Addresses and Associated Contact Data (CSV)', $emailCsv];
        $dataExportItems[] = ['Participant Data (CSV)', $participantCsv];
        if ($obfuscate === 0) {
            $dataExportItems[] = ['Entries and Associated Data (CSV)', $entriesCsv];
        }
        $right[] = ['Data Exports', 'fa-download',
            'Export participant and entry data collected by your installation to CSV files, including contact info of participants in addition to entry data in various configurations.',
            $dataExportItems,
        ];

        if ($level0) {
            // Data Management.
            $dataMgmtItems = [];
            $dataMgmtItems[] = ['Integrity', [
                $l('/admin/purge?flow=cleanup', 'Clean-Up Data'),
            ]];
            $dataMgmtItems[] = ['Entries', [
                $l('/admin/purge?flow=confirmed', 'Confirm All Unconfirmed'),
                $l('/admin/purge?flow=unconfirmed', 'Purge All Unconfirmed'),
                $l('/admin/purge?flow=unpaid', 'Purge All Unpaid'),
            ]];
            $dataMgmtItems[] = ['Purge', [
                $l('/admin/purge?flow=entries', 'Entries'),
                $l('/admin/purge?flow=payments', 'Payments'),
                $l('/admin/purge?flow=participants', 'Participants'),
                $l('/admin/purge?flow=tables', 'Judging Tables'),
            ]];
            $dataMgmtItems[] = ['Archives', [
                $l('/admin/archive', 'Manage'),
                $l('/admin/archive?action=add', 'Archive Current Data'),
            ]];
            $right[] = ['Data Management', 'fa-archive',
                'Actions to help maintain the data collected by your installation including various archive and purge functions.',
                $dataMgmtItems,
            ];

            // Preferences.
            $prefItems = [['Preferences', [
                $l('/admin/site-preferences', 'General'),
                $l('/admin/site-preferences/entries', 'Entry'),
                $l('/admin/hero-images', 'Banner Images'),
                $l('/admin/site-preferences/email', 'Email Sending / Contact Display'),
                $l('/admin/site-preferences/payment', 'Currency and Payment'),
                $l('/admin/site-preferences/best', 'Best Brewer'.($prefs['proEdition'] === 0 ? ' and/or Club' : '')),
                $l('/admin/judging/preferences', 'Judging/Competition Organization'),
            ]]];
            if ($prefs['useMods']) {
                $prefItems[] = ['Custom Modules', [
                    $l('/admin/mods', 'Manage'),
                    $l('/admin/mods/create', 'Add'),
                ]];
            }
            $right[] = ['Preferences', 'fa-cog',
                'Define site-wide preferences for entries, email sending, currency and payment, best brewer, and judging/competition organization.',
                $prefItems,
            ];
        }

        // More Help (legacy dashboard-help panel).
        $helpItems = [
            ['How Do I...', [
                ['label' => 'Competition Preparation', 'modal' => 'dashboard-help-modal-comp-prep'],
                ['label' => 'Entries and Participants', 'modal' => 'dashboard-help-modal-entries-participants'],
                ['label' => 'Entry Sorting', 'modal' => 'dashboard-help-modal-sorting'],
                ['label' => 'Organizing', 'modal' => 'dashboard-help-modal-organizing'],
                ['label' => 'Scoring', 'modal' => 'dashboard-help-modal-scoring'],
                ['label' => 'Preferences', 'modal' => 'dashboard-help-modal-preferences'],
                ['label' => 'Reports', 'modal' => 'dashboard-help-modal-reports'],
                ['label' => 'Data Exports', 'modal' => 'dashboard-help-modal-data-exports'],
                ['label' => 'Data Management', 'modal' => 'dashboard-help-modal-data-mgmt'],
                ['label' => 'Report an Issue', 'href' => 'https://github.com/geoffhumphrey/brewcompetitiononlineentry/issues/new/choose'],
            ]],
        ];
        $right[] = ['More Help', 'fa-question-circle',
            'Answers to common organization, judging, and reporting questions.',
            $helpItems,
        ];

        return ['left' => $left, 'right' => $right];
    }

    /** Legacy $results_method[$_SESSION['prefsWinnerMethod']] label. */
    private function resultsMethodLabel(int $method): string
    {
        return match ($method) {
            1 => 'Winners Only',
            2 => 'Winners Only No Scores',
            3 => 'Entry Order',
            4 => 'Average Score',
            5 => 'Highest Score',
            default => 'All with Scores',
        };
    }
}
