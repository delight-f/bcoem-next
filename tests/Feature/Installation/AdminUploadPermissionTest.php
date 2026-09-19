<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Group;

/**
 * An upload folder the web server cannot write to must report a permissions
 * message, not a 500 — the failure a host produces when PHP runs as a different
 * user than the one owning the site's files (NearlyFreeSpeech, for one).
 */
#[Group('slow')]
final class AdminUploadPermissionTest extends WizardTestCase
{
    private string $directory = '';

    private int $originalMode = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = public_path('user_images');
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }

        $this->originalMode = fileperms($this->directory) & 0777;
    }

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            @chmod($this->directory, $this->originalMode);
        }

        parent::tearDown();
    }

    public function test_an_unwritable_upload_folder_reports_a_permission_error_not_a_500(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Permission bits do not bind as root.');
        }

        chmod($this->directory, 0555);

        $this->actingAs($this->user('0'))
            ->post('/admin/upload', ['file' => [UploadedFile::fake()->image('logo.png')]])
            ->assertRedirect('/admin/upload?msg=32');
    }
}
