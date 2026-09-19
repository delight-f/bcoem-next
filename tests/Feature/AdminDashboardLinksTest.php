<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admin dashboard link parity. The rebuilt dashboard (Phase 2) presents
 * every legacy admin/default.admin.php subheading + link; links whose port
 * backend does not exist are rendered DISABLED (dimmed + `<!-- TODO: legacy
 * output -->`), so only the ACTIVELY-linked ports are exercised here.
 *
 * The level-0 fixture admin sees every legacy panel (Competition
 * Preparation / Data Management / Preferences included). Judging tables are
 * primed so tables>0 (>1) conditional links render.
 */
final class AdminDashboardLinksTest extends AdminScreensTestCase
{
    /** @var list<int> */
    private array $tableIds = [];

    private ?int $locationId = null;

    /** Insert judging tables so tables>0 (and >1) conditional links render,
     *  plus a past-dated judging session so judgingStarted is true and the
     *  During/After Judging Reports rows render (default.admin.php:1777/1963). */
    private function primeTables(): void
    {
        if ($this->tableIds !== []) {
            return;
        }
        for ($n = 1; $n <= 2; $n++) {
            $this->tableIds[] = (int) DB::table('judging_tables')->insertGetId([
                'tableName' => 'P54 Dashboard Table '.$n,
                'tableNumber' => $n,
            ]);
        }
        if ($this->locationId === null) {
            $this->locationId = (int) DB::table('judging_locations')->insertGetId([
                'judgingLocName' => 'P54 Reports Session',
                'judgingLocType' => 0,
                'judgingDate' => (string) (time() - 86400),
                'judgingDateEnd' => null,
                'judgingRounds' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->tableIds !== []) {
            DB::table('judging_tables')->whereIn('id', $this->tableIds)->delete();
            $this->tableIds = [];
        }
        if ($this->locationId !== null) {
            DB::table('judging_locations')->where('id', $this->locationId)->delete();
            $this->locationId = null;
        }
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string}> [uri, label] for active links. */
    private function activeLinks(): array
    {
        return [
            // Competition Preparation
            ['/admin/dates', 'Edit'],
            ['/admin/competition-info', 'Edit'],
            ['/admin/upload', 'Upload Logo'],
            ['/admin/contacts', 'Manage'],
            ['/admin/contacts/create', 'Add'],
            ['/admin/judging/special-best', 'Manage'],
            ['/admin/judging/special-best/create', 'Add'],
            ['/admin/dropoff', 'Manage'],
            ['/admin/dropoff/create', 'Add'],
            ['/admin/judging/locations', 'Manage'],
            ['/admin/judging/locations/create', 'Add'],
            ['/admin/judging/non-judging', 'Manage'],
            ['/admin/judging/non-judging/create', 'Add'],
            ['/admin/sponsors', 'Manage'],
            ['/admin/sponsors/create', 'Add'],
            ['/admin/styles', 'Manage'],
            ['/admin/styles/create', 'Add'],
            ['/admin/style-types', 'Manage'],
            ['/admin/style-types/create', 'Add'],
            // Entries, Payments, and Participants
            ['/backoffice/entries', 'Manage'],
            ['/backoffice/participants', 'Manage'],
            ['/admin/judging/flights', 'Assign/Unassign Judges'],
            ['/admin/judging/flights', 'Assign/Unassign Stewards'],
            ['/admin/judging/flights', 'Assign/Unassign Staff'],
            ['/register/entrant', 'A Participant'],
            ['/register/judge?view=quick', 'A Judge (Quick)'],
            ['/register/judge', 'A Judge (Standard)'],
            ['/register/steward?view=quick', 'A Steward (Quick)'],
            ['/register/steward', 'A Steward (Standard)'],
            // Entry Sorting
            ['/backoffice/entries', 'Manually'],
            ['/admin/judging/checkin', 'Via Barcode Scanner (Entry/Judging Numbers Only)'],
            ['/admin/judging/checkin?filter=box-paid', 'Via Barcode Scanner (Entry/Judging Numbers, Box, and Paid)'],
            ['/admin/output/table_cards?psort=sorting-placards&view=master-list', 'Sorting Placards'],
            ['/admin/output/sorting?go=default&filter=default&view=entry', 'Entry Numbers'],
            ['/admin/output/sorting?go=default&filter=default', 'Judging Numbers'],
            ['/admin/output/sorting?go=cheat&filter=default', 'Cheat Sheets'],
            ['/admin/output/table_cards?psort=sorting-tables&view=master-list', 'Tables and Associated Styles Master List'],
            ['/admin/output/table_cards?psort=sorting-tables', 'Tables and Associated Styles Placards'],
            // Organizing
            ['/admin/judging/pool-assign?filter=judges', 'Judges'],
            ['/admin/judging/pool-assign?filter=stewards', 'Stewards'],
            ['/admin/judging/pool-assign?filter=staff', 'Staff'],
            ['/admin/judging/tables', 'Manage'],
            ['/admin/judging/tables/create', 'Add'],
            ['/admin/judging/tables', 'Assign Judges/Stewards'],
            ['/admin/judging/pool-assign?filter=bos', 'Add'],
            ['/admin/judging/flights', 'Manage'],
            // Scoring
            ['/admin/upload-scoresheets', 'Upload Multiple'],
            ['/admin/upload-scoresheets', 'Upload Individually'],
            ['/eval', 'Manage'],
            ['/admin/judging/scores', 'Manage'],
            ['/admin/judging/bos', 'Manage'],
            ['/admin/judging/special-best-data', 'Manage'],
            // Reports — active port routes
            ['/admin/output/judge_notes?go=org_notes', 'Notes to Organizer'],
            ['/admin/output/judge_notes?go=admin', 'Admin and Staff Notes'],
            ['/admin/output/judge_notes?go=allergens', 'Possible Allergens in Entries'],
            ['/admin/output/dropoff', 'Entry Totals'],
            ['/admin/output/dropoff?go=check', 'List of Entries'],
            ['/admin/output/table_cards', 'All Tables'],
            ['/admin/output/assignments?filter=judges', 'All Judges By Last Name'],
            ['/admin/output/assignments?filter=stewards', 'All Stewards Last Name'],
            ['/admin/output/bos_mat?action=blank&view=mini-bos', 'Blank'],
            ['/admin/output/bos_mat?action=mini-bos&filter=entry', 'All Tables - Entry Numbers'],
            ['/admin/output/bos_mat?action=mini-bos', 'All Tables - Judging Numbers'],
            ['/admin/output/bos_mat?filter=entry', 'All Style Types - Entry Numbers'],
            ['/admin/output/bos_mat', 'All Style Types - Judging Numbers'],
            ['/admin/output/pullsheets', 'All By Table'],
            ['/admin/output/participant_summary', 'All Participants with Entries'],
            ['/admin/output/participant_entries_list', 'All Entries by Particpant'],
            ['/admin/output/staff_points', 'Print'],
            ['/admin/output/staff_points?view=xml', 'XML'],
            ['/admin/output/post_judge_inventory', 'With Scores'],
            ['/admin/output/post_judge_inventory', 'Without Scores'],
            // Data Exports — active CSV
            ['/admin/output/export?go=csv&action=all&tb=all', 'All Entries: All Data'],
            ['/admin/output/export?go=csv', 'All Entries: Limited Data'],
            // Data Management
            ['/admin/purge', 'Entries'],
            ['/admin/archive', 'Manage'],
            ['/admin/archive', 'Archive Current Data'],
            // Preferences
            ['/admin/site-preferences', 'General'],
            ['/admin/site-preferences/entries', 'Entry'],
            ['/admin/hero-images', 'Banner Images'],
            ['/admin/site-preferences/email', 'Email Sending / Contact Display'],
            ['/admin/site-preferences/payment', 'Currency and Payment'],
            ['/admin/site-preferences/best', 'Best Brewer and/or Club'],
            ['/admin/judging/preferences', 'Judging/Competition Organization'],
            ['/admin/mods', 'Manage'],
            ['/admin/mods/create', 'Add'],
            // Scoring — eval import (un-stubbed PARITY-014 tail)
            ['/eval/import-scores', 'Import Scores'],
        ];
    }

    public function test_dashboard_renders_all_subheadings(): void
    {
        $this->primeTables();
        $response = $this->get('/admin');
        $response->assertOk();

        foreach ([
            'Competition Preparation',
            'Entries, Payments, and Participants',
            'Entry Sorting',
            'Organizing',
            'Scoring',
            'Reports',
            'Data Exports',
            'Data Management',
            'Preferences',
            'More Help',
        ] as $title) {
            $response->assertSee($title, false);
        }

        // Bottle/box label matrix papers + their per-option dropdown labels must
        // be present (not silently dropped). Paper tooltips carry the product
        // code, so assert on the option text and the shared button labels.
        $response->assertSee('Print Bottle Labels (PDF)', false)
            ->assertSee('With Required Info - All Styles (Entry Numbers)', false)
            ->assertSee('Quicksort - 6 Labels per Entry', false)
            ->assertSee('Print Box Labels (PDF)', false)
            ->assertSee('Virtual Judging Box Labels (by Judge Name)', false)
            ->assertSee('Number of Labels per Entry', false)
            ->assertSee('Number of Labels per Table', false)
            ->assertSee('Number of Labels per Judge', false);

        // Issue #49 follow-up: both label-matrix category headers carry the
        // section band (same treatment as the judging-phase headings), so they
        // cannot blend into the paper rows beneath them.
        self::assertSame(
            2,
            substr_count((string) $response->getContent(), 'class="row bcoem-dash-subhead py-2"'),
        );
    }

    public function test_every_active_dashboard_link_renders_with_label(): void
    {
        $this->primeTables();
        $response = $this->get('/admin');
        $response->assertOk();

        foreach ($this->activeLinks() as [$uri, $label]) {
            $response->assertSee($label, false);
        }
    }

    #[Group('slow')]
    public function test_every_active_dashboard_route_responds(): void
    {
        $this->primeTables();
        foreach ($this->activeLinks() as [$uri]) {
            $response = $this->get($uri);
            self::assertContains(
                $response->getStatusCode(),
                [200, 302],
                sprintf('Route %s should respond 200 (or 302). Got %s.', $uri, $response->getStatusCode()),
            );
        }
    }

    /**
     * Issue 18: Data Management actions open a per-flow confirmation modal
     * (Cancel/Yes) instead of dumping the admin on the all-flows purge page.
     * The previously un-routed flows must now resolve (no 404) and stay
     * confirm-gated.
     */
    public function test_data_management_actions_use_confirmation_modals(): void
    {
        $this->primeTables();
        $response = $this->get('/admin')->assertOk();

        foreach (['cleanup', 'confirmed', 'unconfirmed', 'unpaid', 'entries', 'participants', 'tables', 'scores', 'custom', 'availability', 'evaluation', 'scoresheets', 'purge-all'] as $flow) {
            $response->assertSee('id="purge-'.$flow.'"', false);
        }

        $response->assertSee('name="confirm" value="yes"', false);
        $response->assertSee('data-bs-dismiss="modal">Cancel', false);
        $response->assertSee('btn-success">Yes', false);

        // Every flow resolves and, without confirm=yes, mutates nothing.
        foreach (['cleanup', 'confirmed', 'scoresheets', 'purge-all'] as $flow) {
            $this->post('/admin/purge/'.$flow, [])->assertRedirect('/admin/purge');
        }
    }

    /** PARITY-015: legacy results matrix labels + hrefs by winner method. */
    public function test_results_matrix_matches_legacy_labels(): void
    {
        $this->primeTables();
        $response = $this->get('/admin');
        $response->assertOk();

        // Method 0 (default fixture): both categories, four families each.
        $response->assertSee('Results (By Table/Medal Group)', false)
            ->assertSee('All Results (By Table/Medal Group - Single Report)', false)
            ->assertSee('All with Scores...', false)
            ->assertSee('Winners Only with Scores...', false)
            ->assertSee('All without Scores...', false)
            ->assertSee('Winners Only without Scores...', false)
            ->assertSee('By Table/Medal Group Entry Count - Ascending', false)
            ->assertSee('go=all&amp;action=print&amp;tb=scores&amp;view=default', false);
    }
}
