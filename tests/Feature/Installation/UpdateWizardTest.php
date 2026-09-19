<?php

declare(strict_types=1);

namespace Tests\Feature\Installation;

use App\Services\Installation\InstallationService;
use App\Services\Installation\ReleaseUpdater;
use App\Services\Installation\UpgradeService;
use App\Support\Wizard\ProgressTracker;
use App\Support\Wizard\RemoteVersionChecker;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use ZipArchive;

/**
 * The automatic update: the wizard is reachable while a newer release is only
 * *published* (files still current), and mode=auto runs the ReleaseUpdater file
 * steps followed by the ordinary UpgradeService database steps.
 *
 * The file work is pointed at a throwaway app-data fixture bound into the
 * container, so the repository is never touched.
 */
#[Group('slow')]
final class UpdateWizardTest extends WizardTestCase
{
    private string $fixtureDoc;

    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The zip extension is required to exercise the automatic updater.');
        }

        $base = sys_get_temp_dir().'/bcoem-update-'.bin2hex(random_bytes(5));
        $this->fixtureDoc = $base;
        $this->fixtureRoot = $base.'/app-data';

        mkdir($this->fixtureRoot.'/storage/app', 0775, true);
        file_put_contents($this->fixtureRoot.'/.env', 'APP_KEY=live-key');
        file_put_contents($this->fixtureRoot.'/VERSION', '4.0.0');
        file_put_contents($this->fixtureRoot.'/storage/app/upload.txt', 'user upload');

        (new ProgressTracker)->release('upgrade');
    }

    protected function tearDown(): void
    {
        try {
            app(Application::class)->maintenanceMode()->deactivate();
        } catch (\Throwable) {
        }

        (new ProgressTracker)->release('upgrade');

        foreach (glob(storage_path('framework/updates/*')) ?: [] as $zip) {
            @unlink($zip);
        }

        $this->deleteTree($this->fixtureDoc);

        parent::tearDown();
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

    private function bindFixture(): void
    {
        $this->app->instance(InstallationService::class, new InstallationService($this->fixtureRoot));
        $this->app->instance(
            UpgradeService::class,
            new UpgradeService(new InstallationService($this->fixtureRoot), $this->fixtureRoot),
        );
        $this->app->instance(
            ReleaseUpdater::class,
            new ReleaseUpdater($this->fixtureRoot, $this->fixtureDoc, new RemoteVersionChecker('delight-f/bcoem-next')),
        );
    }

    /**
     * A real release zip as build/release.sh packages it, returned as bytes for
     * the fake download response.
     */
    private function releaseZipBytes(string $version): string
    {
        $path = sys_get_temp_dir().'/bcoem-zip-'.bin2hex(random_bytes(5)).'.zip';

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $root = 'bcoem-'.$version.'-webroot';
        $zip->addFromString($root.'/index.php', '<?php // new front controller');
        $zip->addFromString($root.'/app-data/VERSION', $version);
        $zip->addFromString($root.'/app-data/vendor/autoload.php', 'autoload');
        $zip->addFromString($root.'/app-data/.env', 'APP_KEY=placeholder');
        $zip->addFromString($root.'/assets/app.js', 'new asset');
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    public function test_wizard_is_reachable_while_a_newer_release_is_only_published(): void
    {
        $this->bindFixture();
        $this->setInstalled(true, '4.0.0');
        Http::fake();
        Cache::put('bcoem.remote-version', ['version' => '4.1.0', 'checked_at' => time()], 86400);

        $this->actingAs($this->user('0'))->get('/upgrade')
            ->assertOk()
            ->assertSee('Update automatically');
    }

    public function test_published_release_is_still_top_level_admin_only(): void
    {
        $this->bindFixture();
        $this->setInstalled(true, '4.0.0');
        Http::fake();
        Cache::put('bcoem.remote-version', ['version' => '4.1.0', 'checked_at' => time()], 86400);

        $this->actingAs($this->user('1'))->get('/upgrade')->assertForbidden();
    }

    public function test_wizard_still_404s_when_nothing_is_available(): void
    {
        $this->setInstalled(true, '4.0.0');
        Http::fake();
        Cache::forget('bcoem.remote-version');

        $this->actingAs($this->user('0'))->get('/upgrade')->assertNotFound();
    }

    public function test_auto_mode_downloads_swaps_and_then_migrates(): void
    {
        $this->bindFixture();
        $this->setInstalled(true, '4.0.0');
        Cache::put('bcoem.remote-version', ['version' => '4.1.0', 'checked_at' => time()], 86400);

        $bytes = $this->releaseZipBytes('4.1.0');
        Http::fake([
            'api.github.com/*' => Http::response(['tag_name' => 'v4.1.0'], 200),
            'github.com/*' => Http::response($bytes, 200),
        ]);

        $admin = $this->user('0');
        $token = 'autoupdate000001';

        $this->actingAs($admin)
            ->postJson('/upgrade/run', ['token' => $token, 'mode' => 'auto'])
            ->assertOk();

        // One step per request: four file steps then five database steps.
        $terminal = [];
        for ($i = 0; $i < 16; $i++) {
            $terminal = (array) $this->actingAs($admin)->getJson('/upgrade/progress?token='.$token)->json();
            if (in_array($terminal['status'] ?? null, ['complete', 'failed'], true)) {
                break;
            }
        }

        $error = is_array($terminal['error'] ?? null) ? $terminal['error'] : [];
        $this->assertSame('complete', $terminal['status'] ?? null, (string) ($error['technical'] ?? ''));

        // The file side actually fetched and swapped the release.
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/releases/download/v4.1.0/bcoem-4.1.0-webroot.zip'));
        $this->assertSame('4.1.0', trim((string) file_get_contents($this->fixtureRoot.'/VERSION')));
        $this->assertSame('APP_KEY=live-key', trim((string) file_get_contents($this->fixtureRoot.'/.env')));
        $this->assertSame('user upload', trim((string) file_get_contents($this->fixtureRoot.'/storage/app/upload.txt')));

        // The database side then advanced the marker to the swapped-in version.
        $this->assertSame('4.1.0', (string) DB::table('bcoem_sys')->where('id', 1)->value('version'));
        $this->assertFalse(app(Application::class)->isDownForMaintenance());

        // No staging directory is left in the document root.
        $this->assertEmpty(glob($this->fixtureDoc.'/.bcoem-update-*') ?: []);
    }
}
