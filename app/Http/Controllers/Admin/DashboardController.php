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
     * @return list<array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: string}>}>
     */
    private function leftSections(): array
    {
        return [
            ['Competition Preparation', 'fa-wrench',
                'Set up the competition: important dates, contest info, logos and banners, contacts, special-best awards, drop-off/judging locations, sponsors, and the style stack. Everything here shapes what entrants see.',
                [
                    ['/admin/dates', 'Edit Important Dates'],
                    ['/admin/competition-info', 'Edit Competition Info'],
                    ['/admin/hero-images', 'Upload Logo & Banner Images'],
                    ['/admin/contacts', 'Manage Contacts'],
                    ['/admin/contacts/create', 'Add Contact'],
                    ['/admin/judging/special-best', 'Manage Special Best'],
                    ['/admin/dropoff', 'Manage Drop-off Locations'],
                    ['/admin/judging/locations', 'Manage Judging Locations'],
                    ['/admin/judging/non-judging', 'Manage Non-Judging Locations'],
                    ['/admin/sponsors', 'Manage Sponsors'],
                    ['/admin/sponsors/create', 'Add Sponsor'],
                    ['/admin/styles', 'Manage Styles'],
                    ['/admin/style-types', 'Manage Style Types'],
                ]],
            ['Entries, Payments, and Participants', 'fa-beer',
                'Manage received entries, mark payments, and maintain participant accounts. The assignment links route to the flights screen.',
                [
                    ['/backoffice/entries', 'Manage Entries'],
                    ['/backoffice/payments', 'Manage Payments'],
                    ['/backoffice/participants', 'Manage Participants'],
                    ['/admin/judging/flights', 'Assign/Unassign Judges'],
                    ['/admin/judging/flights', 'Assign/Unassign Stewards'],
                    ['/admin/judging/flights', 'Assign/Unassign Staff'],
                    ['/register/entrant', 'Register a Participant'],
                ]],
            ['Entry Sorting', 'fa-exchange',
                'Check entries in via barcode scanner and print sorting/quicksort labels for bottle handling.',
                [
                    ['/backoffice/entries', 'Sort Manually'],
                    ['/admin/judging/checkin', 'Check-in Via Barcode Scanner'],
                    ['/admin/output/sorting', 'Quicksort & Sorting Sheets'],
                    ['/admin/output/labels', 'Quicksort Labels'],
                ]],
            ['Organizing', 'fa-tasks',
                'Work with the judge, steward, and staff pools, manage judging tables, and assign crews to tables and flights.',
                [
                    ['/backoffice/participants?filter=judges', 'Judges'],
                    ['/backoffice/participants?filter=stewards', 'Stewards'],
                    ['/backoffice/participants?filter=staff', 'Staff'],
                    ['/admin/judging/tables', 'Manage Judging Tables'],
                    ['/admin/judging/tables/create', 'Add Judging Table'],
                    ['/admin/judging/flights', 'Assign Judges/Stewards to Tables'],
                    ['/admin/judging/flights', 'Manage Flights'],
                ]],
            ['Scoring', 'fa-trophy',
                'Scoresheet output, the evaluation sub-app, score entry, best-of-show rounds, and special-best data entry.',
                [
                    ['/admin/output/scoresheets', 'Scoresheets'],
                    ['/eval', 'Evaluation Sub-app'],
                    ['/admin/judging/scores', 'Manage Scores'],
                    ['/admin/judging/bos', 'Manage Best of Show'],
                    ['/admin/judging/special-best-data', 'Manage Special Best Data'],
                ]],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: string}>}>
     */
    private function rightSections(): array
    {
        return [
            ['Reports', 'fa-file',
                'Printable views: judging assignments, organizer/admin notes, allergen report, drop-off tallies, pull sheets, and staff points.',
                [
                    ['/admin/output/assignments', 'Judging Assignments by Last Name'],
                    ['/admin/output/judge-notes?go=org_notes', 'Notes to Organizer'],
                    ['/admin/output/judge-notes?go=admin', 'Admin and Staff Notes'],
                    ['/admin/output/judge-notes?go=allergens', 'Possible Allergens in Entries'],
                    ['/admin/output/dropoff', 'Entry Totals by Drop-off'],
                    ['/admin/output/dropoff?go=check', 'List of Entries by Drop-off'],
                    ['/admin/output/pullsheets', 'Pull Sheets — All by Table'],
                    ['/admin/output/staff-points', 'Staff Points'],
                ]],
            ['Data Exports', 'fa-download',
                'CSV exports of entries and participants for external tools (BJCP reporting, circuit data, email lists).',
                [
                    ['/admin/output/export?go=csv&action=all&tb=all', 'All Entries: All Data'],
                    ['/admin/output/export?go=csv', 'All Entries: Limited Data'],
                    ['/admin/output/export?go=csv&tb=p', 'Paid Entries'],
                    ['/admin/output/export?go=csv&tb=w', 'Winners: Limited Data'],
                    ['/admin/output/participant-entries-list', 'Participant Entries List'],
                    ['/admin/output/participant-summary', 'Participant Summary'],
                ]],
            ['Data Management', 'fa-archive',
                'End-of-competition close-out. Archive preserves history in sibling tables; purge flows irreversibly delete — read the warnings before confirming.',
                [
                    ['/admin/archive', 'Archive Current Data'],
                    ['/admin/purge', 'Purge & Reset Flows'],
                ]],
            ['Preferences', 'fa-cog',
                'General/entry/email/payment/best-brewer preferences, banner images, judging organization defaults, and organizer-defined custom categories.',
                [
                    ['/admin/site-preferences', 'General & Entry Preferences'],
                    ['/admin/site-preferences#email', 'Email Sending / Contact Display'],
                    ['/admin/site-preferences#payments', 'Currency and Payment'],
                    ['/admin/site-preferences#best-brewer', 'Best Brewer'],
                    ['/admin/judging/preferences', 'Judging / Competition Organization'],
                    ['/admin/hero-images', 'Banner Images'],
                    ['/admin/mods', 'Manage Custom Categories'],
                ]],
        ];
    }
}
