<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Services\Installation\Exceptions\UpgradeException;
use App\Services\Installation\ReleaseUpdater;
use App\Support\Wizard\RemoteVersionChecker;
use Tests\TestCase;

/**
 * The file-side updater against a temporary app-data layout. No HTTP, no
 * database: swapIntoPlace is driven directly so the risky rename/overlay work
 * is proven without a network or a schema.
 */
final class ReleaseUpdaterTest extends TestCase
{
    private string $base;

    private string $docRoot;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/bcoem-release-'.bin2hex(random_bytes(5));
        $this->docRoot = $this->base;
        $this->root = $this->base.'/app-data';

        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);

        parent::tearDown();
    }

    private function updater(): ReleaseUpdater
    {
        return new ReleaseUpdater($this->root, $this->docRoot, new RemoteVersionChecker('delight-f/bcoem-next'));
    }

    private function write(string $path, string $contents): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $contents);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->deleteTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }

    /**
     * A staged release as build/release.sh packages it: a webroot directory
     * holding index.php and app-data/.
     */
    private function stagedRelease(string $version, string $name = 'bcoem-release'): string
    {
        $staging = $this->docRoot.'/.bcoem-update-'.$name;
        $root = $staging.'/bcoem-'.$version.'-webroot';

        $this->write($root.'/index.php', 'new index');
        $this->write($root.'/app-data/VERSION', $version);
        $this->write($root.'/app-data/vendor/autoload.php', 'new autoload');
        $this->write($root.'/app-data/.env', 'APP_KEY=placeholder');
        $this->write($root.'/app-data/storage/framework/placeholder', 'x');
        $this->write($root.'/assets/app.js', 'new asset');

        return $root;
    }

    public function test_webroot_layout_is_detected_from_the_app_data_directory(): void
    {
        $this->assertTrue($this->updater()->isWebrootLayout());

        $flat = new ReleaseUpdater($this->docRoot.'/flat', $this->docRoot.'/flat/public', new RemoteVersionChecker('x/y'));

        $this->assertFalse($flat->isWebrootLayout());
    }

    public function test_can_self_update_is_false_off_the_app_data_layout(): void
    {
        $flat = new ReleaseUpdater($this->docRoot.'/flat', $this->docRoot.'/flat/public', new RemoteVersionChecker('x/y'));

        $this->assertFalse($flat->canSelfUpdate());
    }

    public function test_swap_replaces_the_application_and_preserves_configuration_and_uploads(): void
    {
        $this->write($this->root.'/.env', 'APP_KEY=live-key');
        $this->write($this->root.'/storage/app/upload.txt', 'user upload');
        $this->write($this->root.'/VERSION', '4.0.0');
        $this->write($this->root.'/old-only-file.php', 'old');
        $this->write($this->docRoot.'/index.php', 'old index');
        $this->write($this->docRoot.'/uploads/mine.png', 'keep me');

        $stagingRoot = $this->stagedRelease('4.1.0', 'swap');

        $state = [];
        $this->updater()->swapIntoPlace($stagingRoot, $state);

        // The new application is in place…
        $this->assertSame('4.1.0', trim((string) file_get_contents($this->root.'/VERSION')));
        $this->assertSame('new autoload', trim((string) file_get_contents($this->root.'/vendor/autoload.php')));
        // …a file the release no longer ships is gone…
        $this->assertFileDoesNotExist($this->root.'/old-only-file.php');

        // …but the live configuration and uploaded data survived.
        $this->assertSame('APP_KEY=live-key', trim((string) file_get_contents($this->root.'/.env')));
        $this->assertSame('user upload', trim((string) file_get_contents($this->root.'/storage/app/upload.txt')));

        // The document root is overlaid, and an operator's own files are kept.
        $this->assertSame('new index', trim((string) file_get_contents($this->docRoot.'/index.php')));
        $this->assertSame('new asset', trim((string) file_get_contents($this->docRoot.'/assets/app.js')));
        $this->assertFileExists($this->docRoot.'/uploads/mine.png');

        // Staging is gone; the previous tree is kept as the rollback point.
        $this->assertDirectoryDoesNotExist($this->docRoot.'/.bcoem-update-swap');
        $this->assertIsString($state['files_backup_dir'] ?? null);
        $this->assertDirectoryExists((string) $state['files_backup_dir']);
    }

    public function test_failed_overlay_restores_the_original_tree(): void
    {
        $this->write($this->root.'/.env', 'APP_KEY=live-key');
        $this->write($this->root.'/VERSION', '4.0.0');

        // The release ships `assets/` as a directory, but the live document root
        // already has `assets` as a file: the overlay copy fails AFTER the
        // rename, which is exactly the half-completed state the rollback exists
        // for.
        $stagingRoot = $this->stagedRelease('4.1.0', 'rollback');
        $this->write($this->docRoot.'/assets', 'i am a file, not a directory');

        $state = [];
        $thrown = null;

        try {
            $this->updater()->swapIntoPlace($stagingRoot, $state);
        } catch (UpgradeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'a failed overlay must surface as an UpgradeException');
        $this->assertStringContainsString('still running the version it had', $thrown->plainMessage);

        // The original tree is back, untouched.
        $this->assertSame('4.0.0', trim((string) file_get_contents($this->root.'/VERSION')));
        $this->assertSame('APP_KEY=live-key', trim((string) file_get_contents($this->root.'/.env')));
        $this->assertNull($state['files_backup_dir'] ?? null, 'nothing to roll back to once restored');

        // No stray backup directory is left behind.
        $this->assertEmpty(glob($this->docRoot.'/app-data.bak-*') ?: []);
    }

    public function test_describe_failure_names_the_files_backup_directory(): void
    {
        $failure = $this->updater()->describeFailure(
            ['files_backup_dir' => '/tmp/app-data.bak-20260101000000'],
            new \RuntimeException('boom'),
        );

        $this->assertStringContainsString('/tmp/app-data.bak-20260101000000', $failure['plain']);
        $this->assertStringContainsString('boom', $failure['technical']);
        $this->assertNull($failure['backup_path']);
    }

    public function test_download_url_points_at_the_webroot_asset(): void
    {
        config()->set('services.github.repository', 'some-org/some-repo');

        $this->assertSame(
            'https://github.com/some-org/some-repo/releases/download/v4.1.0/bcoem-4.1.0-webroot.zip',
            $this->updater()->downloadUrl('4.1.0'),
        );
    }
}
