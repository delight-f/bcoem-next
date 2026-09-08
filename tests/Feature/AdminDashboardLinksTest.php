<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

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

    /** Insert judging tables so tables>0 (and >1) conditional links render. */
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
    }

    protected function tearDown(): void
    {
        if ($this->tableIds !== []) {
            DB::table('judging_tables')->whereIn('id', $this->tableIds)->delete();
            $this->tableIds = [];
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
            ['/admin/judging/flights', 'Judges'],
            ['/admin/judging/flights', 'Stewards'],
            ['/admin/judging/flights', 'Staff'],
            ['/admin/judging/tables', 'Manage'],
            ['/admin/judging/tables/create', 'Add'],
            ['/admin/judging/flights', 'Assign Judges/Stewards'],
            ['/admin/judging/flights', 'Manage'],
            ['/admin/judging/bos', 'Add'],
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
            ['/admin/output/participant_summary', 'Participant Summaries'],
            ['/admin/output/participant_entries_list', 'Participant Entries List (Address)'],
            ['/admin/output/staff_points', 'Print'],
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

        // Bottle-label families must be present (not silently dropped).
        $response->assertSee('Letter (Avery 5160) — Entry Numbers', false)
            ->assertSee('A4 (Avery 3422) — Entry Numbers', false)
            ->assertSee('Round (Avery OL5275WR) — All Entries', false);
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
