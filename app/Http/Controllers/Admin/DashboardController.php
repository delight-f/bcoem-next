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

        return view('admin.dashboard', [
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
                    ['Competition Info', [$l('/admin/competition-info', 'Edit'), $l('/admin/upload', 'Upload Logo')]],
                    ['Contacts', [$l('/admin/contacts', 'Manage'), $l('/admin/contacts/create', 'Add')]],
                    ['Custom Categories', [$l('/admin/judging/special-best', 'Manage'), $l('/admin/judging/special-best/create', 'Add')]],
                    ['Drop-Off Locations', [$l('/admin/dropoff', 'Manage'), $l('/admin/dropoff/create', 'Add')]],
                    ['Judging Sessions', [$l('/admin/judging/locations', 'Manage'), $l('/admin/judging/locations/create', 'Add')]],
                    ['Non-Judging Sessions', [$l('/admin/judging/non-judging', 'Manage'), $l('/admin/judging/non-judging/create', 'Add')]],
                    ['Sponsors', [$l('/admin/sponsors', 'Manage'), $l('/admin/sponsors/create', 'Add'), $l('/admin/upload', 'Upload Logos')]],
                    ['Styles Accepted', [$l('/admin/styles', 'Manage'), $l('/admin/styles/create', 'Add')]],
                    ['Style Types', [$l('/admin/style-types', 'Manage'), $l('/admin/style-types/create', 'Add')]],
                ],
            ];
        }

        // Entries, Payments, and Participants — legacy Entries/Payments and Participants.
        $entriesItems = [];
        $entriesItems[] = ['Entries', [$l('/backoffice/entries', 'Manage')]];
        if ($prefs['paypalIpn']) {
            $entriesItems[] = ['Payments', [$l('/admin/payments', 'Manage')]];
        }
        $participantLinks = [$l('/backoffice/participants', 'Manage')];
        if ($level0) {
            array_push(
                $participantLinks,
                $l('/admin/judging/flights', 'Assign/Unassign Judges'),
                $l('/admin/judging/flights', 'Assign/Unassign Stewards'),
                $l('/admin/judging/flights', 'Assign/Unassign Staff'),
            );
        } else {
            array_push($participantLinks, $l('/admin/judging/flights', 'Assign/Unassign Judges'), $l('/admin/judging/flights', 'Assign/Unassign Stewards'));
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
                $todo('Judging Numbers (Random)', 'go=... js regen modal'),
                $todo('Judging Numbers (With Style Number Prefix)', 'go=... js regen modal'),
                $todo('Judging Numbers (Same as Entry Numbers)', 'go=... js regen modal'),
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
                $checkIn[] = $todo('Via Mobile Devices', 'qr.php not ported');
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
                $l('/admin/judging/flights', 'Judges'),
                $l('/admin/judging/flights', 'Stewards'),
                $l('/admin/judging/flights', 'Staff'),
            ]],
            ['Tables', array_merge(
                [$l('/admin/judging/tables', 'Manage'), $l('/admin/judging/tables/create', 'Add')],
                $tables > 1 ? [$l('/admin/judging/flights', 'Assign Judges/Stewards')] : [],
            )],
            ['Flights', [$l('/admin/judging/flights', 'Manage'), $l('/admin/judging/flights', 'Add')]],
            ['BOS Judges', [$l('/admin/judging/bos', 'Add')]],
        ];
        $left[] = ['Organizing', 'fa-tasks',
            'Post-sort vital functions like assigning personnel as judges, stewards, and/or staff, defining table/medal group configurations, assigning judges and stewards to tables/medal groups, and designating best of show judges.',
            $orgItems,
        ];

        // Scoring.
        $scoreItems = [
            ['Scoresheets and Docs', [
                $l('/admin/upload-scoresheets', 'Upload Multiple'),
                $l('/admin/upload-scoresheets', 'Upload Individually'),
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
            $todo('By Last Name', 'section=assignments&go=judging_assignments&filter=staff&view=name'),
            $todo('By Non-Judging Session', 'section=assignments&go=judging_assignments&filter=staff'),
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
        $reportsItems[] = ['Table Cards', [
            $l('/admin/output/table_cards', 'All Tables'),
            $todo('For Table...', 'table_choose("table-cards","judging_tables")'),
            $todo('For Session...', 'table-cards judging_locations round'),
        ]];
        $reportsItems[] = ['Sign In Sheets', [
            $todo('Judges', 'section=assignments&go=judging_assignments&filter=judges&view=sign-in'),
            $todo('Stewards', 'section=assignments&go=judging_assignments&filter=stewards&view=sign-in'),
        ]];
        if ($tables > 0) {
            $reportsItems[] = ['Assignments', [
                $l('/admin/output/assignments?filter=judges', 'All Judges By Last Name'),
                $l('/admin/output/assignments?filter=judges', 'All Judges By Table'),
                $l('/admin/output/assignments?filter=judges', 'All Judges By Session'),
                $l('/admin/output/assignments?filter=stewards', 'All Stewards Last Name'),
                $l('/admin/output/assignments?filter=stewards', 'All Stewards By Table'),
                $l('/admin/output/assignments?filter=stewards', 'All Stewards By Session'),
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
            $reportsItems[] = ['Pullsheets', [
                $l('/admin/output/pullsheets', 'All By Table'),
                $l('/admin/output/pullsheets?go=judging_tables&id=default&view=entry', 'All By Table - Entry Numbers'),
                $l('/admin/output/pullsheets?go=judging_tables&id=default', 'All By Table - Judging Numbers'),
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
        $reportsItems[] = ['Medal Labels (Round)', [
            $l('/admin/output/labels?go=judging_scores&action=awards&filter=round&psort=5293', '5293'),
        ]];
        $reportsItems[] = ['Address Labels', [
            $l('/admin/output/labels?go=participants&action=address_labels&filter=default&psort=5160', 'Address Labels — Letter (All)'),
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
        ]];
        $reportsItems[] = ['Inventory', [
            $l('/admin/output/post_judge_inventory', 'With Scores'),
            $l('/admin/output/post_judge_inventory', 'Without Scores'),
        ]];
        $reportsItems[] = ['BOS Results', [
            $l('/admin/output/results?go=judging_scores_bos&action=print&tb=bos&view=default', 'Print'),
            $todo('PDF', 'section=export-results&go=judging_scores_bos&action=download&view=pdf'),
            $todo('HTML', 'section=export-results&go=judging_scores_bos&action=download&view=html'),
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
            $todo('PDF report', 'section=export-results&go=judging_scores&action=default&tb=none&view=pdf'),
            $todo('HTML report', 'section=export-results&go=judging_scores&action=default&tb=none&view=html'),
        ]];

        $right = [['Reports', 'fa-file',
            'A wide range of reports is available for all stages of your competition - before, during, and after your designated judging sessions.',
            $reportsItems,
        ]];

        // Data Exports.
        $dataExportItems = [];
        $emailCsv = [
            $todo('Available Judges', 'section=export-emails&go=csv&filter=avail_judges&action=email'),
            $todo('Available Stewards', 'section=export-emails&go=csv&filter=avail_stewards&action=email'),
            $todo('Assigned Judges', 'section=export-emails&go=csv&filter=judges&action=email'),
            $todo('Assigned Stewards', 'section=export-emails&go=csv&filter=stewards&action=email'),
            $todo('Available and Assigned Staff', 'section=export-emails&go=csv&filter=staff&action=email'),
        ];
        $participantCsv = [
            $todo('All Participants', 'section=export-participants&go=csv'),
            $todo('Winners: Limited Data', 'section=export-entries&go=csv&tb=winners'),
            $todo('Winners: Circuit Data', 'section=export-entries&go=csv&tb=circuit'),
            $todo('Winners: Master Homebrewer Program Member Data', 'section=export-entries&go=csv&tb=circuit&filter=mhp'),
        ];
        $entriesCsv = [
            $l('/admin/output/export?go=csv&action=all&tb=all', 'All Entries: All Data'),
            $l('/admin/output/export?go=csv', 'All Entries: Limited Data'),
            $todo('All Entries: Limited Data with Participant Contact Info', 'section=export-entries&go=csv&tb=brewer_contact_info'),
            $todo('Paid Entries', 'section=export-entries&go=csv&tb=paid&view=all'),
            $todo('Paid & Received Entries', 'section=export-entries&go=csv&tb=paid'),
            $todo('Paid Entries Not Received', 'section=export-entries&go=csv&tb=paid&view=not_received'),
            $todo('Non-Paid Entries', 'section=export-entries&go=csv&tb=nopay&view=all'),
            $todo('Non-Paid & Received Entries', 'section=export-entries&go=csv&tb=nopay'),
            $todo('Entries with Required & Optional Info', 'section=export-entries&go=csv&action=required&tb=required'),
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
                $todo('Clean-Up Data', 'cleanUp modal'),
            ]];
            $dataMgmtItems[] = ['Entries', [
                $todo('Confirm All Unconfirmed', 'confirmAll modal'),
                $todo('Purge All Unconfirmed', 'purgeUnconfirmed modal'),
                $todo('Purge All Unpaid', 'purgeUnpaid modal'),
            ]];
            $dataMgmtItems[] = ['Purge', [
                $l('/admin/purge', 'Entries'),
                $todo('Payments', 'purgePayments modal'),
                $todo('Participants', 'purgeParticipants modal'),
                $todo('Judging Tables', 'purgeTables modal'),
            ]];
            $dataMgmtItems[] = ['Archives', [
                $l('/admin/archive', 'Manage'),
                $l('/admin/archive', 'Archive Current Data'),
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
                $todo('Competition Preparation', 'help modal #dashboard-help-modal-comp-prep'),
                $todo('Entries and Participants', 'help modal #dashboard-help-modal-entries-participants'),
                $todo('Entry Sorting', 'help modal #dashboard-help-modal-sorting'),
                $todo('Organizing', 'help modal #dashboard-help-modal-organizing'),
                $todo('Scoring', 'help modal #dashboard-help-modal-scoring'),
                $todo('Preferences', 'help modal #dashboard-help-modal-preferences'),
                $todo('Reports', 'help modal #dashboard-help-modal-reports'),
                $todo('Data Exports', 'help modal #dashboard-help-modal-data-exports'),
                $todo('Data Management', 'help modal #dashboard-help-modal-data-mgmt'),
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
