<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Results\BestBrewerStandings;
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
            'mhpDisplay' => (int) ($ctx->prefsStr('prefsMHPDisplay') ?? 0) === 1,
            'eval' => (string) $ctx->prefsStr('prefsEval') === '1',
            'showBestBrewer' => (int) ($ctx->prefsStr('prefsShowBestBrewer') ?? 0) !== 0,
            'showBestClub' => (int) ($ctx->prefsStr('prefsShowBestClub') ?? 0) !== 0,
            // Legacy $barcode_qrcode_array (bottle_label.output.php :93-99).
            'barcodes' => in_array((int) $ctx->prefsStr('prefsEntryForm'), [1, 3, 5, 6, 0, 11], true),
            // Tables Planning/Competition mode (default.admin.php Tables row)
            // and the queued-judging flag that gates the Flights row.
            'tablesPlanning' => (string) ($ctx->judgingStr('jPrefsTablePlanning') ?? '0') === '1',
            'queued' => (string) ($ctx->judgingStr('jPrefsQueued') ?? 'Y') === 'N',
            // default.admin.php gates the After Judging Reports sub-section on
            // $judging_started (first judging session in the past).
            'judgingStarted' => $windows->firstJudgingDate !== null && $now > $windows->firstJudgingDate,
        ];

        $counts = [
            'tables' => DB::table('judging_tables')->count(),
            'judging' => DB::table('judging_locations')->count(),
            'styleTypes' => DB::table('style_types')->count(),
            'sbi' => DB::table('special_best_info')->count(),
        ];

        $sections = $this->sections((int) $user->userLevel, (int) $user->userAdminObfuscate, $prefs, $counts);

        return view('admin.dashboard', [
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
            'winnerMethodTable' => (int) ($ctx->prefsStr('prefsWinnerMethod') ?? 0) === 0,
            'showBest' => ((int) ($ctx->prefsStr('prefsShowBestBrewer') ?? 0) !== 0
                || (int) ($ctx->prefsStr('prefsShowBestClub') ?? 0) !== 0) && $judgingStarted,
            'bestBrewers' => $judgingStarted ? BestBrewerStandings::forAwards($ctx)->brewerRows : [],
        ];
    }

    /**
     * Admin dashboard panels in default.admin.php order and shape. Each
     * section: [title, icon, help, categories]; each category is the accordion
     * tuple [categoryLabel, body]. Body shapes, chosen by the blade:
     *   list of links/families        generic inline row (most panels)
     *   ['links'=>, 'dropdown'=>]     row with a single real dropdown (Scoring)
     *   ['matrix'=>papers]            paper-by-paper label matrix (Sorting)
     *   ['blocks'=>list]              ordered block row — {inline|block|dd}
     *                                 segments reproducing legacy Reports rows
     *   '_section' category           full-width sub-section heading (Reports
     *                                 Before/During/After Judging)
     *
     * @return array{left: list<array>, right: list<array>}
     */
    private function sections(int $level, int $obfuscate, array $prefs, array $counts): array
    {
        $l = static fn (string $href, string $label): array => ['label' => $label, 'href' => $href];

        $level0 = $level === 0;
        $barcodes = $prefs['barcodes'];
        $tables = $counts['tables'];
        $planning = $prefs['tablesPlanning'];
        $queued = $prefs['queued'];
        $judgingStarted = $prefs['judgingStarted'];

        $left = [];

        if ($level0) {
            $left[] = [
                'Competition Preparation', 'fa-wrench', 'comp-prep',
                [
                    ['All Competition Dates', [$l('/admin/dates', 'Edit')]],
                    ['Competition Info', [$l('/admin/competition-info', 'Edit'), $l('/admin/upload?action=html', 'Upload Logo')]],
                    ['Contacts', [$l('/admin/contacts', 'Manage'), $l('/admin/contacts/create', 'Add')]],
                    ['Custom Categories', [$l('/admin/judging/special-best', 'Manage'), $l('/admin/judging/special-best/create', 'Add')]],
                    ['Drop-Off Locations', [$l('/admin/dropoff', 'Manage'), $l('/admin/dropoff/create', 'Add')]],
                    ['Judging Sessions', [$l('/admin/judging/locations', 'Manage'), $l('/admin/judging/locations/create', 'Add')]],
                    ['Non-Judging Sessions', [$l('/admin/judging/non-judging', 'Manage'), $l('/admin/judging/non-judging/create', 'Add')]],
                    ['Sponsors', [$l('/admin/sponsors', 'Manage'), $l('/admin/sponsors/create', 'Add'), $l('/admin/upload?action=html', 'Upload Logos')]],
                    ['Styles Accepted', [$l('/admin/styles', 'Manage'), $l('/admin/styles/create', 'Add')]],
                    ['Style Types', [$l('/admin/style-types', 'Manage'), $l('/admin/style-types/create', 'Add')]],
                ],
            ];
        }

        $entriesItems = [];
        $entriesItems[] = ['Entries', [$l('/backoffice/entries', 'Manage')]];
        if ($prefs['paypalIpn']) {
            $entriesItems[] = ['Payments', [$l('/admin/payments', 'Manage')]];
        }
        $participantManage = [$l('/backoffice/participants', 'Manage')];
        $participantAssign = [
            $l('/backoffice/participants?filter=judges', 'Assign/Unassign Judges'),
            $l('/backoffice/participants?filter=stewards', 'Assign/Unassign Stewards'),
        ];
        if ($level0) {
            $participantAssign[] = $l('/backoffice/participants?filter=staff', 'Assign/Unassign Staff');
        }
        // Legacy default.admin.php renders the Participant and Register rows as
        // separate <ul> line groups (Manage alone; the assign links together),
        // so each line is its own nested list for the accordion renderer.
        $entriesItems[] = ['Participants', [$participantManage, $participantAssign]];
        $entriesItems[] = ['Register', [
            [$l('/register/entrant', 'A Participant')],
            [$l('/register/judge?view=quick', 'A Judge (Quick)'), $l('/register/judge', 'A Judge (Standard)')],
            [$l('/register/steward?view=quick', 'A Steward (Quick)'), $l('/register/steward', 'A Steward (Standard)')],
        ]];
        $left[] = ['Entries, Payments, and Participants', 'fa-beer', 'entries-participants',
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

        // Sorting Sheets + Sorting Into Tables (legacy default.admin.php:909-933).
        $sortingSheets = [
            $l('/admin/output/table_cards?psort=sorting-placards&view=master-list', 'Sorting Placards'),
            $l('/admin/output/sorting?go=default&filter=default&view=entry', 'Entry Numbers'),
        ];
        if ($obfuscate === 0) {
            $sortingSheets[] = $l('/admin/output/sorting?go=default&filter=default', 'Judging Numbers');
            $sortingSheets[] = $l('/admin/output/sorting?go=cheat&filter=default', 'Cheat Sheets');
        }
        $sortItems[] = ['Sorting Sheets', $sortingSheets];

        // Sorting Into Tables — always shown (default.admin.php:922-933).
        $sortItems[] = ['Sorting Into Tables', [
            $l('/admin/output/table_cards?psort=sorting-tables&view=master-list', 'Tables and Associated Styles Master List'),
            $l('/admin/output/table_cards?psort=sorting-tables', 'Tables and Associated Styles Placards'),
        ]];

        // Print Bottle Labels (PDF) + Print Box Labels (PDF) — the paper-by-paper
        // label matrix (legacy default.admin.php:934-1241, labels-admin branch).
        // Each row is one PAPER (product link on the left, e.g. "Letter / Avery
        // 5160"); its options live on the right, each with its own real
        // "Number of Labels per Entry/Table/Judge" 1-12 dropdown.
        if ($obfuscate === 0) {
            $labelsHref = static fn (string $qs): string => '/admin/output/labels?'
                .str_replace('section=labels-admin&', '', $qs);
            // One count menu: the descriptive option text + a dropdown of 1-12.
            $countOpt = static fn (string $label, string $qs, string $button = 'Number of Labels per Entry'): array => [
                'label' => $label,
                'button' => $button,
                'children' => collect(range(1, 12))->map(
                    fn (int $i): array => ['label' => (string) $i, 'href' => $labelsHref($qs).'&sort='.$i],
                )->all(),
            ];
            // One option row, drawn as a two-column paper block.
            $paper = static fn (string $label, string $href, array $options, string $title = ''): array => [
                'paper' => $label, 'href' => $href, 'options' => $options, 'title' => $title,
            ];

            $bottle = [];
            $bottle[] = $paper(
                (string) 'Letter',
                'https://www.avery.com/products/labels/5167',
                [['label' => 'Quicksort - 6 Labels per Entry', 'href' => $labelsHref('section=labels-admin&go=entries&action=bottle-judging&filter=default&view=quicksort&psort=5167')],
                    ['label' => 'Quicksort - 3 Labels per Entry', 'href' => $labelsHref('section=labels-admin&go=entries&action=bottle-judging&filter=default&view=quicksort&psort=5167&tb=short')]],
            );
            $bottle[] = $paper(
                'Letter',
                'https://www.avery.com/products/labels/5160',
                [['label' => 'Entry Numbers', 'href' => $labelsHref('section=labels-admin&go=entries&action=bottle-entry&filter=default&psort=5160')],
                    ['label' => 'Judging Numbers', 'href' => $labelsHref('section=labels-admin&go=entries&action=bottle-judging&filter=default&psort=5160')],
                    $countOpt('With Required Info - All Styles (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=all&psort=5160'),
                    $countOpt('With Required Info - Only Styles Where Required (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=special&psort=5160'),
                    $countOpt('With Required Info - All Styles (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=all&psort=5160'),
                    $countOpt('With Required Info - Only Styles Where Required (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=special&psort=5160')],
            );
            $bottle[] = $paper(
                'A4',
                'https://www.avery.fi/product/multipurpose-labels-ultragrip-3422',
                [['label' => 'Entry Numbers', 'href' => $labelsHref('section=labels-admin&go=entries&action=bottle-entry&filter=default&psort=3422')],
                    ['label' => 'Judging Numbers', 'href' => $labelsHref('section=labels-admin&go=entries&action=bottle-judging&filter=default&psort=3422')],
                    $countOpt('With Required Info - All Styles (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=all&psort=3422'),
                    $countOpt('With Required Info - Only Styles Where Required (Entry Numbers)', 'section=labels-admin&go=entries&action=bottle-entry&filter=default&view=special&psort=3422'),
                    $countOpt('With Required Info - All Styles (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=all&psort=3422'),
                    $countOpt('With Required Info - Only Styles Where Required (Judging Numbers)', 'section=labels-admin&go=entries&action=bottle-judging&filter=default&view=special&psort=3422')],
            );
            $bottle[] = $paper(
                '0.50 in/13 mm Round',
                'http://www.onlinelabels.com/Products/OL32.htm',
                [$countOpt('Entry Numbers', 'section=labels-admin&go=entries&action=bottle-entry-round&filter=default&psort=OL32'),
                    $countOpt('Judging Numbers', 'section=labels-admin&go=entries&action=bottle-judging-round&filter=default&psort=OL32'),
                    $countOpt('Style/Sub-Style Only', 'section=labels-admin&go=entries&action=bottle-category-round&filter=default&psort=OL32'),
                    $countOpt('Entries Added By Admins', 'section=labels-admin&go=entries&action=bottle-entry-round&filter=recent&psort=OL32')],
            );
            $bottle[] = $paper(
                '0.75 in/19 mm Round',
                'http://www.onlinelabels.com/Products/OL5275WR.htm',
                [$countOpt('Entry Numbers', 'section=labels-admin&go=entries&action=bottle-entry-round&filter=default&psort=OL5275WR'),
                    $countOpt('Judging Numbers', 'section=labels-admin&go=entries&action=bottle-judging-round&filter=default&psort=OL5275WR'),
                    $countOpt('Style/Sub-Style Only', 'section=labels-admin&go=entries&action=bottle-category-round&filter=default&psort=OL5275WR'),
                    $countOpt('Entries Added By Admins', 'section=labels-admin&go=entries&action=bottle-judging-round&filter=recent&psort=OL5275WR')],
            );
            $sortItems[] = ['Print Bottle Labels (PDF)', ['matrix' => $bottle]];

            // Print Box Labels (PDF).
            $box = [];
            $box[] = $paper(
                'Letter',
                'https://www.avery.com/products/labels/5160',
                [$countOpt('Box Labels (by Table)', 'section=labels-admin&go=judging_tables', 'Number of Labels per Table'),
                    $countOpt('Virtual Judging Box Labels (by Judge Name)', 'section=labels-admin&go=judging_tables&filter=judges', 'Number of Labels per Judge')],
            );
            $box[] = $paper(
                'A4',
                'https://www.avery.fi/product/multipurpose-labels-ultragrip-3422',
                [$countOpt('Box Labels (by Table)', 'section=labels-admin&go=judging_tables&psort=3422', 'Number of Labels per Table'),
                    $countOpt('Virtual Judging Box Labels (by Judge Name)', 'section=labels-admin&go=judging_tables&filter=judges&psort=3422', 'Number of Labels per Judge')],
            );
            $sortItems[] = ['Print Box Labels (PDF)', ['matrix' => $box]];
        }

        $left[] = ['Entry Sorting', 'fa-exchange', 'sorting',
            $sortItems,
        ];

        // Organizing.
        $orgItems = [
            ['Assign/Unassign', [
                $l('/admin/judging/tables?action=assign&filter=judges', 'Judges'),
                $l('/admin/judging/tables?action=assign&filter=stewards', 'Stewards'),
                $l('/admin/judging/tables?action=assign&filter=staff', 'Staff'),
            ]],
            // Tables row + the planning/competition-mode switch
            // (default.admin.php:1273-1287). Row links are Manage / Add /
            // [Assign Judges/Stewards if tables>1]; the mode switch adds its
            // own indicator + two buttons under them, so the row is emitted
            // as a dedicated "tables-mode" block for the blade renderer.
            // Rendered by the blade's dedicated tables-mode branch: row links
            // plus the planning/competition switch.
            ['tables-mode', ['links' => array_merge(
                [$l('/admin/judging/tables', 'Manage'), $l('/admin/judging/tables/create', 'Add')],
                $tables > 1 ? [$l('/admin/judging/tables?action=assign', 'Assign Judges/Stewards')] : [],
            ), 'planning' => $planning]],
        ];
        if ($queued) {
            // Flights row — only when queued judging is disabled
            // (default.admin.php:1288-1300). Manage and Add both land on the
            // flights screen, like legacy.
            $orgItems[] = ['Flights', [
                $l('/admin/judging/flights', 'Manage'),
                $l('/admin/judging/flights', 'Add'),
            ]];
        }
        if ($tables > 1) {
            // BOS Judges (default.admin.php:1302-1315) — only when more than
            // one table is defined.
            $orgItems[] = ['BOS Judges', [
                $l('/admin/judging/tables?action=assign&filter=bos', 'Add'),
            ]];
        }
        $left[] = ['Organizing', 'fa-tasks', 'organizing',
            $orgItems,
        ];

        // Scoring.
        $scoreItems = [
            ['Scoresheets and Docs', [
                $l('/admin/upload-scoresheets', 'Upload Multiple'),
                $l('/admin/upload-scoresheets?action=html', 'Upload Individually'),
            ]],
        ];
        if ($prefs['eval'] && $obfuscate === 0) {
            // Entry Evaluations — prefsEval==1 AND not obfuscated
            // (default.admin.php:1346).
            $scoreItems[] = ['Entry Evaluations', [$l('/eval', 'Manage')]];
        }
        if ($level0 || $obfuscate === 0) {
            // Scores row + its "Add Scores to..." dropdown (default.admin.php:
            // 1363-1380). The dropdown lists every judging table with an
            // add/edit link like legacy score_table_choose (lib/admin.lib.php:
            // 445) — action=edit once the table has scores, else action=add.
            $scoreLinks = [$l('/admin/judging/scores', 'Manage')];
            if ($prefs['eval']) {
                $scoreLinks[] = ['label' => 'Import Scores', 'href' => '/eval/import-scores'];
            }
            $addItems = DB::table('judging_tables')->orderBy('tableNumber')
                ->get(['id', 'tableNumber', 'tableName'])
                ->map(fn ($t): array => $l(
                    // Legacy split add (no scores yet) vs edit (scores exist)
                    // into two actions; the port's ScoreController serves one
                    // grid for both (judging-scores.php:15-17), so all rows
                    // link to the same edit route.
                    '/admin/judging/scores/'.(int) $t->id.'/edit',
                    'Table '.$t->tableNumber.': '.$t->tableName,
                ))
                ->all();
            $scoreItems[] = ['Scores', ['links' => $scoreLinks, 'dropdown' => [
                'button' => 'Add Scores to...', 'id' => 'scoresMenu1',
                'empty' => 'No tables have been defined',
                'items' => $addItems,
            ]]];
        }
        if ($level0 || $obfuscate === 0) {
            // BOS Entries and Places (default.admin.php:1382-1388).
            $scoreItems[] = ['BOS Entries and Places', [$l('/admin/judging/bos', 'Manage')]];
        }
        if ($level0) {
            // Custom Categories row + its "Add Entries to..." dropdown
            // (default.admin.php:1390-1410). Dropdown mirrors legacy
            // score_custom_winning_choose (lib/admin.lib.php:474): each
            // category is add/edit depending on whether data rows exist; when
            // none, a disabled empty line + divider + "Add a Custom Category".
            $customEntries = DB::table('special_best_info')->orderBy('sbi_name')
                ->get(['id', 'sbi_name'])
                ->map(function ($sbi) use ($l): array {
                    $has = DB::table('special_best_data')->where('sid', $sbi->id)->exists();

                    return $l('/admin/judging/special-best-data?action='.($has ? 'edit' : 'add').'&id='.$sbi->id, (string) $sbi->sbi_name);
                })->all();
            $scoreItems[] = ['Custom Categories', ['links' => [$l('/admin/judging/special-best-data', 'Manage')], 'dropdown' => [
                'button' => 'Add Entries to...', 'id' => 'scoresMenu2',
                'empty' => 'No custom categories have been defined',
                'items' => $customEntries,
            ]]];
        }
        $left[] = ['Scoring', 'fa-trophy', 'scoring',
            $scoreItems,
        ];

        // ---- Right column ----
        $reportsItems = [];

        // Reports panel (default.admin.php:1411-2198). Rows use the shared
        // [category, rowLinks] accordion tuple; a '_section' category is a
        // full-width Before/During/After Judging heading, and report rows
        // carry an ordered list of {inline|block|dd} blocks reproducing
        // legacy's interleaved flat <ul>s and dropdown buttons:
        //   {inline:[items]}  -> <ul class="list-inline">
        //   {block:[items]}   -> <ul class="list-unstyled"> (one link/line)
        //   {dd:{button,items,prefix?}} -> a Bootstrap dropdown (prefix is a
        //                          literal rendered before the button, e.g.
        //                          Entry Required Info "Letter - Entry Numbers by Style")
        // A block item is a link item, or {text:...} for a literal label
        // (Address Labels "Winners" etc.), or {todo-label:...} disabled.
        $inline = static fn (array $items): array => ['inline' => $items];
        $block = static fn (array $items): array => ['block' => $items];
        $dd = static fn (string $button, array $items, string $prefix = ''): array => ['dd' => ['button' => $button, 'items' => $items, 'prefix' => $prefix]];
        $text = static fn (string $t): array => ['text' => $t];
        $rows = &$reportsItems;

        $locRows = DB::table('judging_locations')->orderBy('id')->get();
        $tbls = DB::table('judging_tables')->orderBy('tableNumber')->get();
        $bosStyleTypes = DB::table('style_types')->where('styleTypeBOS', 'Y')->orderBy('id')->get();

        // Per-session (location x round) link list — legacy $ps_loc_* builders.
        $perSession = static function (string $qsBase) use ($l, $locRows): array {
            $out = [];
            foreach ($locRows as $loc) {
                foreach (range(1, max(1, (int) $loc->judgingRounds)) as $round) {
                    $out[] = $l($qsBase.'&location='.$loc->id.'&round='.$round,
                        (string) $loc->judgingLocName.' - Round '.$round);
                }
            }

            return $out;
        };

        // ============================ Before Judging ============================
        $rows[] = ['_section', 'Before Judging'];
        $rows[] = ['Staff Availability', ['blocks' => [
            $inline([$l('/admin/output/assignments?filter=staff&view=name', 'By Last Name'),
                $l('/admin/output/assignments?filter=staff', 'By Non-Judging Session')]),
        ]]];
        $rows[] = ['Notes', ['blocks' => [
            $inline([$l('/admin/output/judge_notes?go=org_notes', 'Notes to Organizer'),
                $l('/admin/output/judge_notes?go=admin', 'Admin and Staff Notes')]),
        ]]];
        if ($obfuscate === 0) {
            $rows[] = ['Allergens', ['blocks' => [
                $inline([$l('/admin/output/judge_notes?go=allergens', 'Possible Allergens in Entries')]),
            ]]];
        }
        $rows[] = ['Drop-Off and Shipping', ['blocks' => [
            $inline([$l('/admin/output/dropoff', 'Entry Totals'),
                $l('/admin/output/dropoff?go=check', 'List of Entries')]),
        ]]];
        if (($tables > 0) && ($obfuscate === 0)) {
            $rows[] = ['Additional Info', ['blocks' => [
                $block([$l('/admin/output/pullsheets?go=all_entry_info&view=entry&id=default', 'All By Table - Entry Numbers')]),
                $dd('Entry Numbers for Table...', $tbls->map(fn ($t) => $l('/admin/output/pullsheets?go=all_entry_info&view=entry&id='.$t->id, 'Table '.$t->tableNumber.': '.$t->tableName))->all()),
                $block([$l('/admin/output/pullsheets?go=all_entry_info&id=default', 'All By Table - Judging Numbers')]),
                $dd('Judging Numbers for Table...', $tbls->map(fn ($t) => $l('/admin/output/pullsheets?go=all_entry_info&id='.$t->id, 'Table '.$t->tableNumber.': '.$t->tableName))->all()),
            ]]];
            $rows[] = ['Pullsheets', ['blocks' => [
                $block([$l('/admin/output/pullsheets?go=judging_tables&view=entry&id=default', 'All By Table - Entry Numbers')]),
                $dd('Entry Numbers for Table...', $tbls->map(fn ($t) => $l('/admin/output/pullsheets?go=judging_tables&view=entry&id='.$t->id, 'Table '.$t->tableNumber.': '.$t->tableName))->all()),
                $dd('Entry Numbers for Session...', $perSession('/admin/output/pullsheets?go=judging_tables&view=entry')),
                $block([$l('/admin/output/pullsheets?go=judging_tables&id=default', 'All By Table - Judging Numbers')]),
                $dd('Judging Numbers for Table...', $tbls->map(fn ($t) => $l('/admin/output/pullsheets?go=judging_tables&id='.$t->id, 'Table '.$t->tableNumber.': '.$t->tableName))->all()),
                $dd('Judging Numbers for Session...', $perSession('/admin/output/pullsheets?go=judging_tables')),
            ]]];
            $rows[] = ['Judge Inventories', ['blocks' => [
                $dd('Entry Numbers for Session...', $perSession('/admin/output/pullsheets?go=all_entry_info&view=judge_inventory&filter=J')),
                $dd('Judging Numbers for Session...', $perSession('/admin/output/pullsheets?go=all_entry_info&view=judge_inventory&filter=J&sort=entry')),
            ]]];
        }
        $rows[] = ['Table Cards', ['blocks' => [
            $block([$l('/admin/output/table_cards', 'All Tables')]),
            $dd('For Table...', $tbls->map(fn ($t) => $l('/admin/output/table_cards?id='.$t->id, 'Table '.$t->tableNumber.': '.$t->tableName))->all()),
            $dd('For Session...', $perSession('/admin/output/table_cards?go=judging_locations')),
        ]]];
        $rows[] = ['Sign In Sheets', ['blocks' => [
            $inline([$l('/admin/output/assignments?filter=judges&view=sign-in', 'Judges'),
                $l('/admin/output/assignments?filter=stewards&view=sign-in', 'Stewards')]),
        ]]];
        if ($tables > 0) {
            $judgesForSession = $locRows->flatMap(fn ($loc) => [$l('/admin/output/assignments?filter=judges&location='.$loc->id.'&view=name', $loc->judgingLocName.' By Name'),
                $l('/admin/output/assignments?filter=judges&location='.$loc->id.'&view=table', $loc->judgingLocName.' By Table')])->all();
            $stewardsForSession = $locRows->flatMap(fn ($loc) => [$l('/admin/output/assignments?filter=stewards&location='.$loc->id.'&view=name', $loc->judgingLocName.' By Name'),
                $l('/admin/output/assignments?filter=stewards&location='.$loc->id.'&view=table', $loc->judgingLocName.' By Table')])->all();
            $rows[] = ['Assignments', ['blocks' => [
                $block([$l('/admin/output/assignments?filter=judges&view=name', 'All Judges By Last Name'),
                    $l('/admin/output/assignments?filter=judges&view=table', 'All Judges By Table'),
                    $l('/admin/output/assignments?filter=judges&view=location', 'All Judges By Session')]),
                $dd('Judges for Session...', $judgesForSession),
                $block([$l('/admin/output/assignments?filter=stewards&view=name', 'All Stewards Last Name'),
                    $l('/admin/output/assignments?filter=stewards&view=table', 'All Stewards By Table'),
                    $l('/admin/output/assignments?filter=stewards&view=location', 'All Stewards By Session')]),
                $dd('Stewards for Session...', $stewardsForSession),
            ]]];
        }
        $rows[] = ['Judge Scoresheet Labels', ['blocks' => [
            $inline([$l('/admin/output/labels?go=participants&action=judging_labels&psort=5160', 'Letter'),
                $l('/admin/output/labels?go=participants&action=judging_labels&psort=3422', 'A4')]),
        ]]];
        if ($obfuscate === 0) {
            // Entry Required Info Scoresheet Labels (Received Entries Only)
            // (default.admin.php:1656-1749): four "by Style" dropdowns
            // (Letter/A4 x Entry/Judging Numbers). Each legacy <li> pairs a
            // literal caption with the 1-12 dropdown; the collapsible "By
            // Table" form is an inline JS widget outside the dashboard link
            // model and is not reproduced.
            $bottleItems = static fn (string $action, string $psort): array => collect(range(1, 12))
                ->map(fn (int $i) => $l('/admin/output/labels?go=entries&action='.$action.'&view=special&psort='.$psort.'&sort='.$i.'&tb=received', (string) $i))->all();
            $rows[] = ['Entry Required Info Scoresheet Labels (Received Entries Only)', ['blocks' => [
                $dd('Number of Labels per Entry', $bottleItems('bottle-entry', '5160'), 'Letter - Entry Numbers by Style'),
                $dd('Number of Labels per Entry', $bottleItems('bottle-judging', '5160'), 'Letter - Judging Numbers by Style'),
                $dd('Number of Labels per Entry', $bottleItems('bottle-entry', '3422'), 'A4 - Entry Numbers by Style'),
                $dd('Number of Labels per Entry', $bottleItems('bottle-judging', '3422'), 'A4 - Judging Numbers by Style'),
            ]]];
            $rows[] = ['Name Tags', ['blocks' => [
                $inline([$l('/admin/output/labels?go=participants&action=judging_nametags&psort=5395', 'Letter')]),
            ]]];
        }


        // Pro-Am/Scale-Up method captions (default.admin.php:140-157).
        $proAmCaption = static fn (int $i): string => $i === 1 ? '1st Place Only'
            : ($i === 2 ? '1st and 2nd Places' : '1st, 2nd, and 3rd Places');

        // ============================ During Judging ============================
        // gated tables>0 && obfuscate 0 (default.admin.php:1777).
        if (($tables > 0) && ($obfuscate === 0)) {
            $rows[] = ['_section', 'During Judging'];
            $rows[] = ['Mini-BOS Pullsheets', ['blocks' => [
                $block([$l('/admin/output/pullsheets?go=mini_bos&view=entry', 'All - Entry Numbers'),
                    $l('/admin/output/pullsheets?go=judging_tables&view=entry&filter=mini_bos&id=default', 'All By Table - Entry Numbers')]),
                $dd('Entry Numbers for Table...', $tbls->map(fn ($t) => $l('/admin/output/pullsheets?go=judging_tables&view=entry&filter=mini_bos&id='.$t->id, 'Table '.$t->tableNumber.': '.$t->tableName))->all()),
                $dd('Entry Numbers for Session...', $perSession('/admin/output/pullsheets?go=mini_bos&view=entry')),
                $block([$l('/admin/output/pullsheets?go=mini_bos', 'All - Judging Numbers'),
                    $l('/admin/output/pullsheets?go=judging_tables&filter=mini_bos&id=default', 'All By Table - Judging Numbers')]),
                $dd('Judging Numbers for Table...', $tbls->map(fn ($t) => $l('/admin/output/pullsheets?go=judging_tables&filter=mini_bos&id='.$t->id, 'Table '.$t->tableNumber.': '.$t->tableName))->all()),
                $dd('Judging Numbers for Session...', $perSession('/admin/output/pullsheets?go=mini_bos')),
            ]]];
            $rows[] = ['Mini-BOS Cup Mats', ['blocks' => [
                $block([$l('/admin/output/bos_mat?action=blank&view=mini-bos', 'Blank')]),
                $block([$l('/admin/output/bos_mat?action=mini-bos&filter=entry', 'All Tables - Entry Numbers')]),
                $dd('Entry Numbers for Table...', $tbls->map(fn ($t) => $l('/admin/output/bos_mat?action=mini-bos&filter=entry&view='.$t->id, (string) $t->tableNumber.': '.$t->tableName))->all()),
                $block([$l('/admin/output/bos_mat?action=mini-bos', 'All Tables - Judging Numbers')]),
                $dd('Judging Numbers for Table...', $tbls->map(fn ($t) => $l('/admin/output/bos_mat?action=mini-bos&view='.$t->id, (string) $t->tableNumber.': '.$t->tableName))->all()),
            ]]];
            $rows[] = ['BOS Pullsheets', ['blocks' => [
                $block([$l('/admin/output/pullsheets?go=judging_scores_bos&view=entry', 'All Style Types - Entry Numbers')]),
                $dd('Entry Numbers for Style Type...', $bosStyleTypes->map(fn ($t) => $l('/admin/output/pullsheets?go=judging_scores_bos&view=entry&id='.$t->id, $t->styleTypeName))->all()),
                $block([$l('/admin/output/pullsheets?go=judging_scores_bos', 'All Style Types - Judging Numbers')]),
                $dd('Judging Numbers for Style Type...', $bosStyleTypes->map(fn ($t) => $l('/admin/output/pullsheets?go=judging_scores_bos&id='.$t->id, $t->styleTypeName))->all()),
            ]]];
            $rows[] = ['BOS Cup Mats', ['blocks' => [
                $block([$l('/admin/output/bos_mat?action=blank', 'Blank')]),
                $block([$l('/admin/output/bos_mat?filter=entry', 'All Style Types - Entry Numbers')]),
                $dd('Entry Numbers for Style Type...', $bosStyleTypes->map(fn ($t) => $l('/admin/output/bos_mat?view='.$t->id.'&filter=entry', $t->styleTypeName))->all()),
                $block([$l('/admin/output/bos_mat', 'All Style Types - Judging Numbers')]),
                $dd('Judging Numbers for Style Type...', $bosStyleTypes->map(fn ($t) => $l('/admin/output/bos_mat?view='.$t->id, $t->styleTypeName))->all()),
            ]]];
            $rows[] = ['Pro-Am/Scale-Up Pullsheets', ['blocks' => [
                $dd('Entry Numbers for Style Type...', $bosStyleTypes->flatMap(fn ($t) => collect(range(1, 3))->map(fn ($s) => $l('/admin/output/pullsheets?go=judging_scores_bos&action=pro-am&filter='.$s.'&view=entry&id='.$t->id, $t->styleTypeName.' - '.$proAmCaption($s))))->all()),
                $dd('Judging Numbers for Style Type...', $bosStyleTypes->flatMap(fn ($t) => collect(range(1, 3))->map(fn ($s) => $l('/admin/output/pullsheets?go=judging_scores_bos&action=pro-am&filter='.$s.'&id='.$t->id, $t->styleTypeName.' - '.$proAmCaption($s))))->all()),
            ]]];
            $rows[] = ['Pro-Am/Scale-Up Cup Mats', ['blocks' => [
                $block([$l('/admin/output/bos_mat?action=blank&view=pro-am', 'Blank')]),
                $dd('Entry Numbers for Style Type...', $bosStyleTypes->flatMap(fn ($t) => collect(range(1, 3))->map(fn ($s) => $l('/admin/output/bos_mat?action=pro-am&sort='.$s.'&filter=entry&view='.$t->id, $t->styleTypeName.' - '.$proAmCaption($s))))->all()),
                $dd('Judging Numbers for Style Type...', $bosStyleTypes->flatMap(fn ($t) => collect(range(1, 3))->map(fn ($s) => $l('/admin/output/bos_mat?action=pro-am&sort='.$s.'&view='.$t->id, $t->styleTypeName.' - '.$proAmCaption($s))))->all()),
            ]]];
        }

        // ============================ After Judging ============================
        // gated on $judging_started (default.admin.php:1963).
        if ($judgingStarted) {
            $rows[] = ['_section', 'After Judging'];
            if ($tables > 0) {
                $rows[] = ['BOS Results', ['blocks' => [
                    $inline([$l('/admin/output/results?go=judging_scores_bos&action=print&tb=bos&view=default', 'Print'),
                        $l('/admin/output/results?go=judging_scores_bos&action=download&view=pdf', 'PDF'),
                        $l('/admin/output/results?go=judging_scores_bos&action=download&view=html', 'HTML')]),
                ]]];
            }
            if ($prefs['showBestBrewer'] || $prefs['showBestClub']) {
                $rows[] = ['Best Brewer'.($prefs['proEdition'] === 0 ? ' and/or Club' : ''), ['blocks' => [
                    $inline([$l('/admin/output/results?go=best&action=print&view=default', 'Print')]),
                ]]];
            }
            // Results — method 0 renders dropdown families; other methods flat.
            $methodLabel = $this->resultsMethodLabel($prefs['winnerMethod']);
            $resultsSortLinks = static fn (string $base): array => [
                $l($base, 'By Table Number'),
                $l($base.'&psort=table-entry-count-asc', 'By Table/Medal Group Entry Count - Ascending'),
                $l($base.'&psort=table-entry-count-desc', 'By Table/Medal Group Entry Count - Descending'),
            ];
            if ($prefs['winnerMethod'] === 0) {
                $rows[] = ['Results ('.$methodLabel.')', ['blocks' => [
                    $dd('All with Scores...', $resultsSortLinks('/admin/output/results?go=judging_scores&action=print&tb=scores&view=default')),
                    $dd('Winners Only with Scores...', $resultsSortLinks('/admin/output/results?go=judging_scores&action=print&tb=scores&view=winners')),
                    $dd('All without Scores...', $resultsSortLinks('/admin/output/results?go=judging_scores&action=print&view=default')),
                    $dd('Winners Only without Scores...', $resultsSortLinks('/admin/output/results?go=judging_scores&action=print&view=winners')),
                    $inline([$l('/admin/output/results?go=judging_scores&action=default&tb=none&view=pdf', 'PDF'),
                        $l('/admin/output/results?go=judging_scores&action=default&tb=none&view=html', 'HTML')]),
                ]]];
                $rows[] = ['All Results ('.$methodLabel.' - Single Report)', ['blocks' => [
                    $dd('All with Scores...', $resultsSortLinks('/admin/output/results?go=all&action=print&tb=scores&view=default')),
                    $dd('Winners Only with Scores...', $resultsSortLinks('/admin/output/results?go=all&action=print&tb=scores&view=winners')),
                    $dd('All without Scores...', $resultsSortLinks('/admin/output/results?go=all&action=print&view=default')),
                    $dd('Winners Only without Scores...', $resultsSortLinks('/admin/output/results?go=all&action=print&view=winners')),
                ]]];
            } else {
                $rows[] = ['Results ('.$methodLabel.')', ['blocks' => [
                    $inline([$l('/admin/output/results?go=judging_scores&action=print&tb=scores&view=default', 'All with Scores'),
                        $l('/admin/output/results?go=judging_scores&action=print&tb=scores&view=winners', 'Winners Only with Scores')]),
                    $inline([$l('/admin/output/results?go=judging_scores&action=print', 'All without Scores'),
                        $l('/admin/output/results?go=judging_scores&action=print&view=winners', 'Winners Only without Scores')]),
                ]]];
            }
            $rows[] = ['BJCP Points', ['blocks' => [
                $inline([$l('/admin/output/staff_points', 'Print'),
                    $l('/admin/output/staff_points?action=download&view=pdf', 'PDF')]),
            ]]];
            if ($tables > 0) {
                $rows[] = ['Award Labels', ['blocks' => [
                    $inline([$l('/admin/output/labels?go=judging_scores&action=awards&filter=default&psort=5160', 'Letter'),
                        $l('/admin/output/labels?go=judging_scores&action=awards&filter=default&psort=3422', 'A4')]),
                ]]];
            }
            // Address Labels — three labeled inline groups (default.admin.php:
            // 2155-2184): Winners, All Participants, All Participants with Entries.
            $rows[] = ['Address Labels', ['blocks' => [
                $inline([$text('Winners'),
                    $l('/admin/output/labels?go=judging_scores&action=awards&filter=address&psort=5160', 'Letter'),
                    $l('/admin/output/labels?go=judging_scores&action=awards&filter=address&psort=3422', 'A4')]),
                $inline([$text('All Participants'),
                    $l('/admin/output/labels?go=participants&action=address_labels&filter=default&psort=5160', 'Letter'),
                    $l('/admin/output/labels?go=participants&action=address_labels&filter=default&psort=3422', 'A4')]),
                $inline([$text('All Participants with Entries'),
                    $l('/admin/output/labels?go=participants&action=address_labels&filter=with_entries&psort=5160', 'Letter'),
                    $l('/admin/output/labels?go=participants&action=address_labels&filter=with_entries&psort=3422', 'A4')]),
            ]]];
            $rows[] = ['Summaries', ['blocks' => [
                $inline([$l('/admin/output/participant_summary', 'All Participants with Entries'),
                    $l('/admin/output/participant_entries_list', 'All Entries by Particpant')]),
            ]]];
            $rows[] = ['Inventory', ['blocks' => [
                $inline([$l('/admin/output/post_judge_inventory?go=scores', 'With Scores'),
                    $l('/admin/output/post_judge_inventory', 'Without Scores')]),
            ]]];
        }


        $right = [['Reports', 'fa-file', 'reports',
            $reportsItems,
        ]];

        // Data Exports (default.admin.php:2199-2252). Three rows of flat
        // CSV-download links (target=_blank, like legacy). The port's export
        // route serves csv/all/all at byte parity; the tab/winners/circuit/
        // mhp/email/paid/nopay/required variants are documented un-ported and
        // are rendered here for dashboard parity as they return the route.
        $csv = static fn (string $href, string $label, string $note = ''): array => [
            'label' => $label,
            'href' => $href,
            'target' => '_blank',
        ] + ($note !== '' ? ['note' => $note] : []);
        $block = static fn (array $items): array => ['block' => $items];

        $dataExportItems = [];
        $dataExportItems[] = ['Email Addresses and Associated Contact Data (CSV)', ['blocks' => [
            $block([
                $csv('/admin/output/export', 'All Participants'),
                $csv('/admin/output/export?go=csv&filter=avail_judges&action=email', 'Available Judges'),
                $csv('/admin/output/export?go=csv&filter=avail_stewards&action=email', 'Available Stewards'),
                $csv('/admin/output/export?go=csv&filter=judges&action=email', 'Assigned Judges'),
                $csv('/admin/output/export?go=csv&filter=stewards&action=email', 'Assigned Stewards'),
                $csv('/admin/output/export?go=csv&filter=staff&action=email', 'Available and Assigned Staff'),
            ]),
        ]]];
        $dataExportItems[] = ['Participant Data (CSV)', ['blocks' => [
            $block(array_merge(
                [$csv('/admin/output/export?go=csv', 'All Participants')],
                [$csv('/admin/output/export?go=csv&tb=winners', 'Winners: Limited Data', 'for generating award labels, etc.')],
                $prefs['proEdition'] === 0
                    ? [$csv('/admin/output/export?go=csv&tb=circuit', 'Winners: Circuit Data', 'suitable for local/regional circuits')]
                    : [],
                ($prefs['proEdition'] === 0 && $prefs['mhpDisplay'])
                    ? [$csv('/admin/output/export?go=csv&tb=circuit&filter=mhp', 'Winners: Master Homebrewer Program Member Data')]
                    : [],
            )),
        ]]];
        if ($obfuscate === 0) {
            $dataExportItems[] = ['Entries and Associated Data (CSV)', ['blocks' => [
                $block([
                    $csv('/admin/output/export?go=csv&action=all&tb=all', 'All Entries: All Data'),
                    $csv('/admin/output/export?go=csv', 'All Entries: Limited Data'),
                    $csv('/admin/output/export?go=csv&tb=brewer_contact_info', 'All Entries: Limited Data with Participant Contact Info'),
                    $csv('/admin/output/export?go=csv&tb=paid&view=all', 'Paid Entries'),
                    $csv('/admin/output/export?go=csv&tb=paid', 'Paid & Received Entries'),
                    $csv('/admin/output/export?go=csv&tb=paid&view=not_received', 'Paid Entries Not Received'),
                    $csv('/admin/output/export?go=csv&tb=nopay&view=all', 'Non-Paid Entries'),
                    $csv('/admin/output/export?go=csv&tb=nopay', 'Non-Paid & Received Entries'),
                    $csv('/admin/output/export?go=csv&action=required&tb=required', 'Entries with Required & Optional Info'),
                ]),
            ]]];
        }

        $right[] = ['Data Exports', 'fa-download', 'data-exports',
            $dataExportItems,
        ];

        if ($level0) {
            // Data Management (default.admin.php:2267-2395). Each purge item
            // deep-links to its confirmation card on the purge page
            // (POST /admin/purge/{flow}, confirm-gated; see PurgeController).
            $inline2 = static fn (array $items): array => ['inline' => $items];
            $block2 = static fn (array $items): array => ['block' => $items];
            $purge = static fn (string $flow, string $label): array => [
                'label' => $label,
                'href' => '/admin/purge#flow-'.$flow,
            ];
            $hasPayments = \Illuminate\Support\Facades\Schema::hasTable('payments');

            $dataMgmtItems = [];
            $dataMgmtItems[] = ['Integrity', ['blocks' => [
                $inline2([$purge('cleanup', 'Clean-Up Data')]),
            ]]];
            $dataMgmtItems[] = ['Entries', ['blocks' => [
                $block2([
                    $purge('confirmed', 'Confirm All Unconfirmed'),
                    $purge('unconfirmed', 'Purge All Unconfirmed'),
                    $purge('unpaid', 'Purge All Unpaid'),
                ]),
            ]]];
            $dataMgmtItems[] = ['Purge', ['blocks' => [
                $block2(array_merge(
                    [$purge('entries', 'Entries')],
                    $hasPayments ? [$purge('payments', 'Payments')] : [],
                    [
                        $purge('participants', 'Participants'),
                        $purge('tables', 'Judging Tables'),
                        $purge('scores', 'Scores'),
                        $purge('custom', 'Custom Categories'),
                        $purge('availability', 'Entrant Availability'),
                        $purge('evaluation', 'Entry Evaluations'),
                        $purge('scoresheets', 'Uploaded Scoresheets'),
                        $purge('purge-all', 'All Purge Functions'),
                    ],
                )),
            ]]];
            $dataMgmtItems[] = ['Archives', ['blocks' => [
                $inline2([
                    $l('/admin/archive', 'Manage'),
                    $l('/admin/archive?action=add', 'Archive Current Data'),
                ]),
            ]]];
            $right[] = ['Data Management', 'fa-archive', 'data-mgmt',
                $dataMgmtItems,
            ];

            // Preferences (default.admin.php:2420-2455). Rows use the block
            // model so the Preferences / Custom Modules rows share the same
            // vertical spacing as Reports/Data Exports.
            $prefBlock = static fn (array $items): array => ['block' => $items];
            $prefInline = static fn (array $items): array => ['inline' => $items];
            $prefItems = [
                ['Preferences', ['blocks' => [
                    $prefBlock([
                        $l('/admin/site-preferences', 'General'),
                        $l('/admin/site-preferences/entries', 'Entry'),
                        $l('/admin/hero-images', 'Banner Images'),
                        $l('/admin/site-preferences/email', 'Email Sending / Contact Display'),
                        $l('/admin/site-preferences/payment', 'Currency and Payment'),
                        $l('/admin/site-preferences/best', 'Best Brewer'.($prefs['proEdition'] === 0 ? ' and/or Club' : '')),
                        $l('/admin/judging/preferences', 'Judging/Competition Organization'),
                    ]),
                ]]],
            ];
            if ($prefs['useMods']) {
                $prefItems[] = ['Custom Modules', ['blocks' => [
                    $prefInline([
                        $l('/admin/mods', 'Manage'),
                        $l('/admin/mods/create', 'Add'),
                    ]),
                ]]];
            }
            $right[] = ['Preferences', 'fa-cog', 'preferences',
                $prefItems,
            ];
        }

        // More Help (legacy dashboard-help panel, default.admin.php:2470-2600).
        // On a non-hosted, up-to-date install legacy shows Version Information,
        // Guides, Customize Installation (the per-section help modals), and
        // How Do I…. Version Updates (recent-update summary) and Customize
        // (hosted-only) are correctly absent for this install type.
        $helpGuide = static fn (string $slug, string $label): array => [
            'label' => $label, 'href' => 'https://brewingcompetitions.com/'.$slug, 'target' => '_blank',
        ];
        $helpModal = static fn (string $label, string $suffix): array => [
            'label' => $label, 'modal' => 'dashboard-help-modal-'.$suffix,
        ];
        $helpBlock = static fn (array $items): array => ['block' => $items];

        $guideItems = [$helpGuide('comp-org', "Competition Organizer's Guide"),
            $helpGuide('reset-comp', 'Reset Competition Information Guide'),
            $helpGuide('paypal-ipn', 'Implement PayPal Instant Payment Notifications Guide'),
            $helpGuide('upload-scoresheets', "Upload Scanned Judges' Scoresheets Guide"),
            $helpGuide('barcode-check-in', 'Barcode or QR Code Entry Check-In Guide'),
        ];
        if ($prefs['eval']) {
            $guideItems[] = $helpGuide('setup-electronic-scoresheets', 'Setup BCOE&M Electronic Scoresheets Guide');
            $guideItems[] = $helpGuide('judging-with-electronic-scoresheets', 'Judging with BCOE&M Electronic Scoresheets Guide');
            $guideItems[] = $helpGuide('virtual-judging', 'Virtual Judging Guide');
            $guideItems[] = $helpGuide('virtual-judging/tips', 'Virtual Judging - Tips for Judges');
        }

        $customizeItems = [];
        if ($level0) {
            $customizeItems[] = $helpModal('Competition Preparation', 'comp-prep');
        }
        $customizeItems[] = $helpModal('Entries and Participants', 'entries-participants');
        $customizeItems[] = $helpModal('Entry Sorting', 'sorting');
        $customizeItems[] = $helpModal('Organizing', 'organizing');
        if ($obfuscate === 0) {
            $customizeItems[] = $helpModal('Scoring', 'scoring');
        }
        if ($level0) {
            $customizeItems[] = $helpModal('Preferences', 'preferences');
        }
        $customizeItems[] = $helpModal('Reports', 'reports');
        $customizeItems[] = $helpModal('Data Exports', 'data-exports');
        if ($level0) {
            $customizeItems[] = $helpModal('Data Management', 'data-mgmt');
        }

        $helpItems = [
            ['Version Information', ['blocks' => [
                $helpBlock([['label' => 'Release Notes, New Features, and Bug Fixes', 'href' => 'https://brewingcompetitions.com/release-notes', 'target' => '_blank']]),
            ]]],
            ['Guides', ['blocks' => [$helpBlock($guideItems)]]],
            ['Customize Installation', ['blocks' => [$helpBlock($customizeItems)]]],
            ['How Do I...', ['blocks' => [
                $helpBlock([['label' => 'Report an Issue', 'href' => 'https://github.com/geoffhumphrey/brewcompetitiononlineentry/issues/new/choose', 'target' => '_blank']]),
            ]]],
        ];
        $right[] = ['More Help', 'fa-question-circle', null,
            $helpItems,
        ];

        return ['left' => $left, 'right' => $right];
    }

    /** Legacy $results_method (constants.inc.php:594). */
    private function resultsMethodLabel(int $method): string
    {
        return match ($method) {
            1 => 'By Style',
            2 => 'By Sub-Style',
            default => 'By Table/Medal Group',
        };
    }
}
