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
 * Admin landing dashboard — port of admin/default.admin.php: page-header h1,
 * "Hello" lead, action-button row (Post-Competition Tasks modal, Best
 * Brewer/Club Results modal), the two-column accordion (Competition
 * Preparation / Entries-Payments-Participants / Entry Sorting / Organizing /
 * Scoring | Reports / Data Exports / Data Management / Preferences), and the
 * right sidebar (admin/sidebar.admin.php): Donate button + Competition
 * Status panel. Links and buttons with no port equivalent (driver.js tour,
 * awards presentation, publish action) are omitted (see graduation report).
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

        return view('admin.dashboard', [
            'left' => $this->leftSections(),
            'right' => $this->rightSections(),
            'status' => $this->status($ctx, $windows, $now),
            'firstName' => DB::table('brewer')->where('uid', (int) $request->user()->id)->value('brewerFirstName') ?? '',
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
     * Admin dashboard panels in default.admin.php order and shape: each
     * section carries rows of [category label, links] mirroring the legacy
     * accordion bodies (strong label left, inline action links right).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: list<array{0: string, 1: string}>}>}>
     */
    private function leftSections(): array
    {
        return [
            ['Competition Preparation', 'fa-wrench',
                'Your competition&#39;s vital information is managed and maintained here. Manage all dates, contacts, custom categories, drop-off locations, judging and non-judging sessions, sponsors, and accepted styles and style types.',
                [
                    ['All Competition Dates', [['/admin/dates', 'Edit']]],
                    ['Competition Info', [['/admin/competition-info', 'Edit'], ['/admin/hero-images', 'Upload Logo']]],
                    ['Contacts', [['/admin/contacts', 'Manage'], ['/admin/contacts/create', 'Add']]],
                    ['Custom Categories', [['/admin/judging/special-best', 'Manage'], ['/admin/judging/special-best/create', 'Add']]],
                    ['Drop-Off Locations', [['/admin/dropoff', 'Manage'], ['/admin/dropoff/create', 'Add']]],
                    ['Judging Sessions', [['/admin/judging/locations', 'Manage'], ['/admin/judging/locations/create', 'Add']]],
                    ['Non-Judging Sessions', [['/admin/judging/non-judging', 'Manage'], ['/admin/judging/non-judging/create', 'Add']]],
                    ['Sponsors', [['/admin/sponsors', 'Manage'], ['/admin/sponsors/create', 'Add'], ['/admin/hero-images', 'Upload Logos']]],
                    ['Styles Accepted', [['/admin/styles', 'Manage'], ['/admin/styles/create', 'Add']]],
                    ['Style Types', [['/admin/style-types', 'Manage'], ['/admin/style-types/create', 'Add']]],
                ]],
            ['Entries, Payments, and Participants', 'fa-beer',
                'Everything to manage your competition entries and associated participants. Add, edit, or delete user accounts, register, designate, and assign judges, stewards, and staff.',
                [
                    ['Entries', [['/backoffice/entries', 'Manage']]],
                    ['Payments', [['/admin/payments/mark', 'Manage']]],
                    ['Participants', [
                        ['/backoffice/participants', 'Manage'],
                        ['/admin/judging/flights', 'Assign/Unassign Judges'],
                        ['/admin/judging/flights', 'Assign/Unassign Stewards'],
                        ['/admin/judging/flights', 'Assign/Unassign Staff'],
                    ]],
                    ['Register', [
                        ['/register/entrant', 'A Participant'],
                        ['/register/judge?view=quick', 'A Judge (Quick)'],
                        ['/register/judge', 'A Judge (Standard)'],
                        ['/register/steward?view=quick', 'A Steward (Quick)'],
                        ['/register/steward', 'A Steward (Standard)'],
                    ]],
                ]],
            ['Entry Sorting', 'fa-exchange',
                'Everything you need to help you with sorting received entries from participants. Check-in entries and print sorting sheets.',
                [
                    ['Entry Check-In', [
                        ['/backoffice/entries', 'Manually'],
                        ['/admin/judging/checkin', 'Via Barcode Scanner (Entry/Judging Numbers Only)'],
                        ['/admin/judging/checkin?filter=box-paid', 'Via Barcode Scanner (Entry/Judging Numbers, Box, and Paid)'],
                    ]],
                    ['Sorting Sheets', [['/admin/output/sorting', 'Print']]],
                    ['Bottle Labels', [['/admin/output/bottle_label', 'Print (PDF)']]],
                    ['Box Labels', [['/admin/output/labels', 'Print (PDF)']]],
                ]],
            ['Organizing', 'fa-tasks',
                'Post-sort vital functions like assigning personnel as judges, stewards, and/or staff, defining table/medal group configurations, assigning judges and stewards to tables/medal groups, and designating best of show judges.',
                [
                    ['Assign/Unassign', [
                        ['/admin/judging/flights', 'Judges'],
                        ['/admin/judging/flights', 'Stewards'],
                        ['/admin/judging/flights', 'Staff'],
                    ]],
                    ['Tables', [
                        ['/admin/judging/tables', 'Manage'],
                        ['/admin/judging/tables/create', 'Add'],
                        ['/admin/judging/flights', 'Assign Judges/Stewards'],
                    ]],
                    ['Flights', [['/admin/judging/flights', 'Manage'], ['/admin/judging/flights', 'Add']]],
                    ['BOS Judges', [['/admin/judging/bos', 'Add']]],
                ]],
            ['Scoring', 'fa-trophy',
                'Manage all functions related to evaluating and scoring participant entries for all stages of judging.',
                [
                    ['Scoresheets and Docs', [
                        ['/admin/upload-scoresheets', 'Upload Multiple'],
                        ['/admin/upload-scoresheets', 'Upload Individually'],
                    ]],
                    ['Entry Evaluations', [['/eval', 'Manage']]],
                    ['Scores', [['/admin/judging/scores', 'Manage']]],
                    ['BOS Entries and Places', [['/admin/judging/bos', 'Manage']]],
                    ['Custom Categories', [['/admin/judging/special-best-data', 'Manage']]],
                ]],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: list<array{0: string, 1: string}>}>}>
     */
    private function rightSections(): array
    {
        return [
            ['Reports', 'fa-file',
                'A wide range of reports is available for all stages of your competition - before, during, and after your designated judging sessions.',
                [
                    ['Before Judging', [
                        ['/admin/output/assignments', 'Judging Assignments by Last Name'],
                        ['/admin/output/judge_notes?go=org_notes', 'Notes to Organizer'],
                        ['/admin/output/judge_notes?go=admin', 'Admin and Staff Notes'],
                        ['/admin/output/judge_notes?go=allergens', 'Possible Allergens in Entries'],
                    ]],
                    ['Drop-Off and Shipping', [
                        ['/admin/output/dropoff', 'Entry Totals by Drop-off'],
                        ['/admin/output/dropoff?go=check', 'List of Entries by Drop-off'],
                    ]],
                    ['Pullsheets', [['/admin/output/pullsheets', 'All by Table']]],
                    ['During Judging', [['/admin/output/bos_mat', 'Cup Mats']]],
                    ['After Judging', [['/admin/output/staff_points', 'Staff Points']]],
                ]],
            ['Data Exports', 'fa-download',
                'Export participant and entry data collected by your installation to CSV files, including contact info of participants in addition to entry data in various configurations.',
                [
                    ['Entries and Associated Data (CSV)', [
                        ['/admin/output/export?go=csv&action=all&tb=all', 'All Data'],
                        ['/admin/output/export?go=csv', 'Limited Data'],
                        ['/admin/output/export?go=csv&tb=w', 'Winners: Limited Data'],
                    ]],
                    ['Participant Data (CSV)', [
                        ['/admin/output/participant_entries_list', 'Participant Entries List'],
                        ['/admin/output/participant_summary', 'Participant Summary'],
                    ]],
                ]],
            ['Data Management', 'fa-archive',
                'Actions to help maintain the data collected by your installation including various archive and purge functions.',
                [
                    ['Archives', [['/admin/archive', 'Manage'], ['/admin/archive', 'Archive Current Data']]],
                    ['Purge', [['/admin/purge', 'Purge & Reset Flows']]],
                ]],
            ['Preferences', 'fa-cog',
                'Define site-wide preferences for entries, email sending, currency and payment, best brewer, and judging/competition organization.',
                [
                    ['Preferences', [
                        ['/admin/site-preferences', 'General'],
                        ['/admin/site-preferences#email', 'Email Sending / Contact Display'],
                        ['/admin/site-preferences#payments', 'Currency and Payment'],
                        ['/admin/site-preferences#best-brewer', 'Best Brewer'],
                        ['/admin/judging/preferences', 'Judging/Competition Organization'],
                        ['/admin/hero-images', 'Banner Images'],
                    ]],
                    ['Custom Modules', [['/admin/mods', 'Manage']]],
                ]],
        ];
    }
}
