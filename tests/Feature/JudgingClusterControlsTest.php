<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Entries\UserDocs;
use Illuminate\Support\Facades\DB;

/**
 * Judging-cluster B-class control parity (structural gaps). Pins the
 * missing controls on the six screens in the batch:
 *   - /admin/judging/tables: View.../Print... dropdowns + un-assigned modal
 *   - /admin/judging/special-best: paragraph + View + Add/Edit dropdowns
 *   - /admin/judging/special-best-data: View + Add + Add/Edit + paragraph
 *   - /admin/judging/preferences: sibling tabs + help modals + help text
 *   - /admin/upload-scoresheets: "Files in the Directory" + judging-number
 *     naming instruction
 *   - /admin/judging/non-judging: two explanatory paragraphs
 * One control-presence assertion + one data-driven assertion per page.
 */
final class JudgingClusterControlsTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'cluster.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9601;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $cleanupStaff = [];

    /** @var list<int> */
    private array $cleanupBrewers = [];

    /** @var list<int> */
    private array $cleanupCategories = [];

    /** @var list<string> */
    private array $uploadedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->where('user_name', self::ADMIN_EMAIL)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->post('/login', [
            'loginUsername' => self::ADMIN_EMAIL,
            'loginPassword' => 'bcoem',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupStaff as $id) {
            DB::table('staff')->where('id', $id)->delete();
        }
        foreach ($this->cleanupBrewers as $uid) {
            DB::table('brewer')->where('uid', $uid)->delete();
        }
        foreach ($this->cleanupCategories as $id) {
            DB::table('special_best_info')->where('id', $id)->delete();
        }
        foreach ($this->uploadedFiles as $file) {
            $path = UserDocs::path($file);
            if (is_file($path)) {
                unlink($path);
            }
        }

        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    public function test_tables_renders_view_print_dropdowns_and_modals(): void
    {
        // Data-driven: signed-up judge/steward with no table assignment shows
        // in the legac not_assigned() modals.
        $this->unassigned('J', 'Smith', 'John', 'Certified Cicerone');
        $this->unassigned('S', 'Doe', 'Jane', 'Advanced Cicerone');

        $this->get('/admin/judging/tables')
            ->assertOk()
            ->assertSee('Judge Assignments By Last Name')
            ->assertSee('Judge Assignments By Table')
            ->assertSee('Steward Assignments By Last Name')
            ->assertSee('Steward Assignments By Table')
            ->assertSee('Judges Not Assigned to a Table')
            ->assertSee('Stewards Not Assigned to a Table')
            ->assertSee('Pullsheets by Table')
            ->assertSee('Tables List')
            ->assertSee('Caution! Judges and/or Stewards Were Un-Assigned')
            ->assertSee('Smith, John')
            ->assertSee('Doe, Jane');
    }

    public function test_special_best_renders_paragraph_and_dropdowns(): void
    {
        $this->category('Pro-Am with Test Brewery', '2', '3');

        $this->get('/admin/judging/special-best')
            ->assertOk()
            ->assertSee('Custom categories are useful if your competition features unique')
            ->assertSee('View...')
            ->assertSee('Add/Edit Entries For...')
            ->assertSee('All Custom Category Entries')
            ->assertSee('Pro-Am with Test Brewery');
    }

    public function test_special_best_data_renders_controls_and_paragraph(): void
    {
        $this->category('Best Name', '1', '1');

        $this->get('/admin/judging/special-best-data')
            ->assertOk()
            ->assertSee('All Custom Categories')
            ->assertSee('Add a Custom Category')
            ->assertSee('Add/Edit Entries For...')
            ->assertSee('Best Name')
            ->assertSee('Custom categories are useful if your competition features unique');
    }

    public function test_preferences_renders_tabs_help_modals_and_help_text(): void
    {
        $this->get('/admin/judging/preferences')
            ->assertOk()
            ->assertSee('General Preferences')
            ->assertSee('Entry Preferences')
            ->assertSee('Email Sending / Contact Display Preferences')
            ->assertSee('Currency and Payment Preferences')
            ->assertSee('Best Brewer and/or Club Preferences')
            ->assertSee('Judging/Competition Organization Preferences')
            ->assertSee('Queued Judging Info')
            ->assertSee('Electronic Scoresheets Info')
            ->assertSee('How entries are identified to judges when evaluating')
            ->assertSee('Set Preferences');
    }

    public function test_upload_scoresheets_lists_directory_and_judging_number_naming(): void
    {
        $file = '01-234.pdf';
        $this->uploadedFiles[] = $file;
        $dir = UserDocs::root();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(UserDocs::path($file), 'scoresheet');

        $this->get('/admin/upload-scoresheets')
            ->assertOk()
            ->assertSee('Files in the Directory')
            ->assertSee('01-234.pdf')
            ->assertSee('six (6) character')
            ->assertSee('File Name')
            ->assertDontSee('The directory does not contain any PDF files.');
    }

    public function test_non_judging_renders_explanatory_paragraphs(): void
    {
        $this->get('/admin/judging/non-judging')
            ->assertOk()
            ->assertSee('Non-judging sessions are scheduled periods of time that necessitate staffing')
            ->assertSee('Anyone with an account who inicates they are willing to serve as staff');
    }

    /**
     * Seed a signed-up judge/steward (staff flag) with no table assignment.
     */
    private function unassigned(string $assignment, string $last, string $first, string $rank): void
    {
        $uid = random_int(70000, 79999);
        DB::table('brewer')->insert([
            'uid' => $uid,
            'brewerFirstName' => $first,
            'brewerLastName' => $last,
            'brewerJudgeRank' => $rank,
        ]);
        $this->cleanupBrewers[] = $uid;

        DB::table('staff')->insert([
            'uid' => $uid,
            'staff_judge' => $assignment === 'J' ? 1 : 0,
            'staff_steward' => $assignment === 'S' ? 1 : 0,
        ]);
        $this->cleanupStaff[] = (int) DB::table('staff')->max('id');
    }

    private function category(string $name, string $places, string $rank): int
    {
        $id = (int) DB::table('special_best_info')->insertGetId([
            'sbi_name' => $name,
            'sbi_places' => $places,
            'sbi_rank' => $rank,
            'sbi_description' => '',
            'sbi_display_places' => null,
        ]);
        $this->cleanupCategories[] = $id;

        return $id;
    }
}
