<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Entries\UserDocs;
use Illuminate\Support\Facades\DB;

/**
 * Upload-scoresheets delete controls: legacy process.inc.php
 * action=delete_scoresheets (Delete All) and process_delete.inc.php go=doc
 * (single file). Admin-only, and the filename must never escape the docs
 * root.
 */
final class UploadScoresheetsDeleteTest extends PublicSurfaceTestCase
{
    private const ADMIN_ID = 9751;

    private const ADMIN_EMAIL = 'scoresheets.admin@brewingcompetitions.com';

    /** bcrypt hash whose plaintext is "bcoem". */
    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<string> */
    private array $fixtures = ['zz-test-one.pdf', 'zz-test-two.pdf'];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->where('id', self::ADMIN_ID)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '0',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        if (! is_dir(UserDocs::root())) {
            mkdir(UserDocs::root(), 0755, true);
        }
        foreach ($this->fixtures as $name) {
            file_put_contents(UserDocs::path($name), '%PDF-1.4 fixture');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $name) {
            @unlink(UserDocs::path($name));
        }
        @unlink(UserDocs::root().'/../scoresheet-outside.pdf');
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    private function loginAdmin(): void
    {
        $this->post('/login', ['loginUsername' => self::ADMIN_EMAIL, 'loginPassword' => 'bcoem']);
        $this->assertAuthenticated();
    }

    public function test_guest_cannot_delete(): void
    {
        $this->delete('/admin/upload-scoresheets/zz-test-one.pdf')->assertRedirect('/login');
        self::assertFileExists(UserDocs::path('zz-test-one.pdf'));
    }

    public function test_admin_deletes_one_file(): void
    {
        $this->loginAdmin();

        $this->delete('/admin/upload-scoresheets/zz-test-one.pdf')
            ->assertRedirect('/admin/upload-scoresheets?msg=31');

        self::assertFileDoesNotExist(UserDocs::path('zz-test-one.pdf'));
        self::assertFileExists(UserDocs::path('zz-test-two.pdf'));
    }

    public function test_admin_deletes_all_files(): void
    {
        $this->loginAdmin();

        $this->delete('/admin/upload-scoresheets')
            ->assertRedirect('/admin/upload-scoresheets?msg=31');

        self::assertFileDoesNotExist(UserDocs::path('zz-test-one.pdf'));
        self::assertFileDoesNotExist(UserDocs::path('zz-test-two.pdf'));
    }

    public function test_traversal_names_are_rejected(): void
    {
        // A path separator never reaches the controller: the route pattern
        // refuses it.
        $this->loginAdmin();
        $this->delete('/admin/upload-scoresheets/..%2Fscoresheet-outside.pdf')->assertNotFound();

        // And the helper refuses anything that is not a bare .pdf basename.
        file_put_contents(UserDocs::root().'/../scoresheet-outside.pdf', '%PDF-1.4 outside');
        self::assertFalse(UserDocs::delete('../scoresheet-outside.pdf'));
        self::assertFileExists(UserDocs::root().'/../scoresheet-outside.pdf');
    }
}
