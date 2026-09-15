<?php

declare(strict_types=1);

namespace App\Services\Installation;

use App\Services\Installation\Data\PreconditionCheck;
use App\Services\Installation\Data\PreconditionResult;
use App\Services\Installation\Exceptions\InstallationException;
use App\Services\Installation\Exceptions\UpgradeException;
use App\Support\Wizard\RemoteVersionChecker;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * The file-side half of an update: download the published release, unpack it,
 * carry the live configuration and uploaded data across, and swap the running
 * tree for the new one.
 *
 * It contains NO database, backup, migration or version-marker logic — after
 * the swap the ordinary UpgradeService steps run against the new files. That
 * split is the whole point: the risky filesystem work is small, isolated and
 * testable, and the existing upgrade contract is untouched.
 *
 * Only the app-data layout is supported. There the executing entry point
 * (`index.php`) sits OUTSIDE the directory being replaced, so a rename is safe
 * from a running request. The flat layout keeps its entry point inside the
 * tree and is left to the CLI script and the manual instructions.
 */
final class ReleaseUpdater
{
    /** Minimum free space (bytes) the download check insists on. */
    private const MIN_FREE_BYTES = 50 * 1024 * 1024;

    /** A release zip is tens of MB; give a slow shared host time to fetch it. */
    private const DOWNLOAD_TIMEOUT_SECONDS = 600;

    private string $rootPath;

    private string $docRoot;

    private RemoteVersionChecker $checker;

    public function __construct(
        ?string $rootPath = null,
        ?string $docRoot = null,
        ?RemoteVersionChecker $checker = null,
    ) {
        $this->rootPath = rtrim($rootPath ?? base_path(), '/');
        $this->docRoot = rtrim($docRoot ?? public_path(), '/');
        $this->checker = $checker ?? app(RemoteVersionChecker::class);
    }

    /**
     * True when the application lives in `app-data/` directly beneath the
     * document root — the shared-hosting layout `build/release.sh` packages and
     * the only one this updater may swap.
     */
    public function isWebrootLayout(): bool
    {
        return basename($this->rootPath) === 'app-data'
            && rtrim(dirname($this->rootPath), '/') === $this->docRoot;
    }

    public function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class) || $this->unzipBinary() !== null;
    }

    /**
     * Whether the dashboard may offer the automatic update at all. A false here
     * is not an error: the notice keeps its manual instructions.
     */
    public function canSelfUpdate(): bool
    {
        return $this->isWebrootLayout()
            && is_dir($this->docRoot) && is_writable($this->docRoot)
            && is_dir($this->rootPath) && is_writable($this->rootPath)
            && $this->zipAvailable();
    }

    /**
     * The extra checks the automatic path adds. The base PHP/extension/writable
     * and database-backup checks already come from UpgradeService, so they are
     * not repeated here; UpdateService concatenates the two lists.
     */
    public function checkPreconditions(): PreconditionResult
    {
        return new PreconditionResult([
            new PreconditionCheck(
                'update_layout',
                $this->isWebrootLayout(),
                $this->isWebrootLayout()
                    ? 'This site uses the app-data layout the automatic update can replace.'
                    : 'This site\'s files are laid out in a way the browser cannot update in place. Use the manual steps or the CLI.',
            ),
            new PreconditionCheck(
                'writable:document_root',
                is_dir($this->docRoot) && is_writable($this->docRoot),
                is_dir($this->docRoot) && is_writable($this->docRoot)
                    ? 'The web folder is writable.'
                    : 'The web folder ('.basename($this->docRoot).') is not writable, so the new files cannot be put in place.',
            ),
            new PreconditionCheck(
                'writable:app_data',
                is_dir($this->rootPath) && is_writable($this->rootPath),
                is_dir($this->rootPath) && is_writable($this->rootPath)
                    ? 'The application folder is writable.'
                    : 'The application folder (app-data) is not writable, so the new files cannot be put in place.',
            ),
            new PreconditionCheck(
                'zip_support',
                $this->zipAvailable(),
                $this->zipAvailable()
                    ? 'This server can unpack the release.'
                    : 'This server cannot unpack a zip file. Ask your host to enable the PHP zip extension.',
            ),
            $this->checkDiskSpace(),
        ]);
    }

    /**
     * The latest published version, fetched now (this also refreshes the cache
     * the dashboard notice reads). Throws a plain-message failure when the
     * lookup cannot be completed.
     */
    public function latestVersion(): string
    {
        $version = $this->checker->refresh();

        if ($version === null || $version === '') {
            throw new UpgradeException(
                'Remote release lookup returned nothing.',
                'We could not reach the download server to find the new version. Check that this site can make outbound connections and try again.',
                0,
                null,
            );
        }

        return $version;
    }

    public function downloadUrl(string $version): string
    {
        $repository = trim((string) config('services.github.repository', 'delight-f/bcoem-next'), '/');

        return 'https://github.com/'.$repository.'/releases/download/v'.$version.'/bcoem-'.$version.'-webroot.zip';
    }

    /**
     * The file-side steps, run one per browser poll by the same runner that
     * walks UpgradeService's steps. UpdateService prepends these in auto mode.
     *
     * @return list<array{label: string, run: \Closure(array<string, mixed> &$state): void}>
     */
    public function steps(): array
    {
        return [
            [
                'label' => 'Downloading the new version…',
                'run' => function (array &$state): void {
                    $version = $this->latestVersion();
                    $directory = storage_path('framework/updates');
                    if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                        throw new UpgradeException(
                            'Could not create '.$directory,
                            'There was nowhere on the server to store the download. Contact support.',
                            0,
                            null,
                        );
                    }

                    $zip = $directory.'/bcoem-'.$version.'-webroot.zip';

                    try {
                        $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->sink($zip)->get($this->downloadUrl($version));
                    } catch (\Throwable $e) {
                        throw new UpgradeException(
                            'Download failed: '.$e->getMessage(),
                            'We could not download the new version. Check the site\'s connection and try again.',
                            0,
                            $e,
                        );
                    }

                    // Stream to disk where the transport honours sink; fall back
                    // to the body for handlers that ignore it (some hosts, and
                    // the test double).
                    if ($response->successful() && ! is_file($zip)) {
                        $body = $response->body();
                        if ($body !== '') {
                            @file_put_contents($zip, $body);
                        }
                    }

                    if (! $response->successful() || ! is_file($zip) || (int) filesize($zip) === 0) {
                        @unlink($zip);

                        throw new UpgradeException(
                            'Download returned HTTP '.$response->status().'.',
                            'The download could not be completed. Nothing has been changed — please try again.',
                            0,
                            null,
                        );
                    }

                    $state['version'] = $version;
                    $state['zip_path'] = $zip;
                },
            ],
            [
                'label' => 'Unpacking the new version…',
                'run' => function (array &$state): void {
                    $zip = (string) ($state['zip_path'] ?? '');
                    $version = (string) ($state['version'] ?? '');

                    $staging = $this->docRoot.'/.bcoem-update-'.bin2hex(random_bytes(6));
                    if (! @mkdir($staging, 0775, true) && ! is_dir($staging)) {
                        throw new UpgradeException(
                            'Could not create the staging directory '.$staging,
                            'There was nowhere on the server to unpack the download. Contact support.',
                            0,
                            null,
                        );
                    }

                    $state['staging_root'] = $staging;

                    try {
                        $this->extractZip($zip, $staging);
                        $stagingRoot = $this->locateStagingRoot($staging, $version);

                        $extracted = is_file($stagingRoot.'/app-data/VERSION')
                            ? trim((string) file_get_contents($stagingRoot.'/app-data/VERSION'))
                            : '';
                        if ($extracted !== $version) {
                            throw new UpgradeException(
                                'Downloaded release reports version "'.$extracted.'", expected "'.$version.'".',
                                'The downloaded update was not the version we expected, so it has been discarded. Nothing has been changed — please try again.',
                                0,
                                null,
                            );
                        }
                    } catch (\Throwable $e) {
                        $this->deleteTree($staging);
                        unset($state['staging_root']);

                        throw $e;
                    }

                    $state['staging_root'] = $stagingRoot;
                },
            ],
            [
                'label' => 'Turning on maintenance mode…',
                'run' => function (array &$state): void {
                    app(UpgradeService::class)->activateMaintenance();
                    $state['files_maintenance'] = true;
                },
            ],
            [
                'label' => 'Swapping in the new version…',
                'run' => function (array &$state): void {
                    $this->swapIntoPlace((string) ($state['staging_root'] ?? ''), $state);
                },
            ],
        ];
    }

    /**
     * The atomic-ish core. Carry `.env` and `storage/` into the staged tree,
     * move the live tree aside, move the new one in, overlay the document-root
     * files (never deleting anything the release does not own), then drop the
     * staging directory.
     *
     * Public so a test can drive it against a temporary fixture without HTTP or
     * a database. On any failure the original tree is restored; the site keeps
     * running the version it had.
     *
     * @param  array<string, mixed>  $state
     */
    public function swapIntoPlace(string $stagingRoot, array &$state): void
    {
        $stagingRoot = rtrim($stagingRoot, '/');
        $stagedApp = $stagingRoot.'/app-data';

        if ($stagingRoot === '' || ! is_dir($stagedApp)) {
            throw new UpgradeException(
                'Staged application directory is missing under "'.$stagingRoot.'".',
                'The downloaded update was incomplete, so nothing has been changed. Please try again.',
                0,
                null,
            );
        }

        $backupDir = $this->rootPath.'.bak-'.date('YmdHis');
        $state['files_backup_dir'] = $backupDir;

        try {
            // The live configuration and uploaded data are not in the release.
            // Copied HERE, immediately before the rename, so the progress marker
            // written a moment ago travels into the new tree with everything
            // else in storage/.
            $liveEnv = $this->rootPath.'/.env';
            if (is_file($liveEnv) && ! @copy($liveEnv, $stagedApp.'/.env')) {
                throw new \RuntimeException('Could not carry .env into the new version.');
            }

            $liveStorage = $this->rootPath.'/storage';
            if (is_dir($liveStorage)) {
                $this->deleteTree($stagedApp.'/storage');
                $this->copyTree($liveStorage, $stagedApp.'/storage');
            }

            if (! @rename($this->rootPath, $backupDir)) {
                throw new \RuntimeException('Could not move the current application aside.');
            }

            if (! @rename($stagedApp, $this->rootPath)) {
                @rename($backupDir, $this->rootPath);

                throw new \RuntimeException('Could not move the new application into place.');
            }

            $this->overlayDocumentRoot($stagingRoot);
        } catch (\Throwable $e) {
            if (is_dir($backupDir)) {
                if (is_dir($this->rootPath)) {
                    $this->deleteTree($this->rootPath);
                }
                @rename($backupDir, $this->rootPath);
                $state['files_backup_dir'] = null;
            }

            throw new UpgradeException(
                'File swap failed: '.$e->getMessage(),
                'We could not put the new version in place, so your site is still running the version it had. Nothing was changed.',
                0,
                $e,
            );
        }

        $this->deleteTree($stagingRoot);

        // The staging directory the download step created wraps the release's
        // own top-level directory; remove it too.
        $parent = dirname($stagingRoot);
        if ($parent !== $this->docRoot && str_starts_with(basename($parent), '.bcoem-update-')) {
            $this->deleteTree($parent);
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * The plain + technical pair for the failure marker. Only the file-side
     * steps route here; a failure in a later database step is described by
     * UpgradeService.
     *
     * @param  array<string, mixed>  $state
     * @return array{plain: string, technical: string, backup_path: string|null}
     */
    public function describeFailure(array $state, \Throwable $e): array
    {
        $directory = isset($state['files_backup_dir']) && is_string($state['files_backup_dir'])
            ? $state['files_backup_dir']
            : null;

        $plain = $e instanceof InstallationException
            ? $e->plainMessage
            : 'Something went wrong while downloading and unpacking the update. Your site has not been changed.';

        if ($directory !== null) {
            $plain .= ' A copy of your previous files is kept at '.$directory.'.';
        }

        return [
            'plain' => $plain,
            'technical' => 'Update failed: '.$e->getMessage(),
            'backup_path' => null,
        ];
    }

    private function extractZip(string $zip, string $destination): void
    {
        if (! is_file($zip)) {
            throw new UpgradeException(
                'Downloaded zip is missing: '.$zip,
                'The downloaded update could not be found on the server. Nothing has been changed — please try again.',
                0,
                null,
            );
        }

        if (class_exists(ZipArchive::class)) {
            $archive = new ZipArchive;
            if ($archive->open($zip) === true) {
                $extracted = $archive->extractTo($destination);
                $archive->close();

                if ($extracted) {
                    return;
                }
            }

            throw new UpgradeException(
                'ZipArchive could not extract '.$zip,
                'The downloaded update could not be unpacked. Nothing has been changed — please try again.',
                0,
                null,
            );
        }

        $unzip = $this->unzipBinary();
        if ($unzip !== null) {
            $command = escapeshellarg($unzip).' -q '.escapeshellarg($zip).' -d '.escapeshellarg($destination).' 2>/dev/null';
            exec($command, $output, $code);

            if ($code === 0) {
                return;
            }
        }

        throw new UpgradeException(
            'No zip reader is available to unpack '.$zip,
            'This server cannot unpack the downloaded update. Ask your host to enable the PHP zip extension.',
            0,
            null,
        );
    }

    /**
     * The release zip holds one directory (`bcoem-<version>-webroot/`) whose
     * `app-data/` is the application and whose remaining entries are the
     * document root. Accept either that or a bare layout.
     */
    private function locateStagingRoot(string $staging, string $version): string
    {
        $candidates = [
            $staging.'/bcoem-'.$version.'-webroot',
        ];

        foreach (glob($staging.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $candidates[] = $directory;
        }
        $candidates[] = $staging;

        foreach ($candidates as $candidate) {
            if (is_dir($candidate.'/app-data')) {
                return $candidate;
            }
        }

        throw new UpgradeException(
            'No app-data directory found under the downloaded release.',
            'The downloaded update was not in the expected format, so nothing has been changed. Please try again.',
            0,
            null,
        );
    }

    /**
     * Copy the release's document-root files over the live ones. Overwriting
     * only — nothing is deleted, so an operator's own files in the web folder
     * survive an update.
     */
    private function overlayDocumentRoot(string $stagingRoot): void
    {
        foreach (scandir($stagingRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'app-data') {
                continue;
            }

            $source = $stagingRoot.'/'.$entry;
            $target = $this->docRoot.'/'.$entry;

            if (is_dir($source)) {
                $this->copyTree($source, $target);
            } elseif (is_file($source) && ! @copy($source, $target)) {
                throw new \RuntimeException('Could not write '.$target);
            }
        }
    }

    private function copyTree(string $source, string $target): void
    {
        if (! is_dir($target) && ! @mkdir($target, 0775, true) && ! is_dir($target)) {
            throw new \RuntimeException('Could not create '.$target);
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $from = $source.'/'.$entry;
            $to = $target.'/'.$entry;

            if (is_link($from)) {
                continue;
            }

            if (is_dir($from)) {
                $this->copyTree($from, $to);
            } elseif (! @copy($from, $to)) {
                throw new \RuntimeException('Could not write '.$to);
            }
        }
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteTree($path.'/'.$entry);
        }

        @rmdir($path);
    }

    private function unzipBinary(): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $path = trim((string) @shell_exec('command -v unzip 2>/dev/null'));

        return $path !== '' ? $path : null;
    }

    private function checkDiskSpace(): PreconditionCheck
    {
        $free = @disk_free_space($this->docRoot);
        $enough = $free === false || $free >= self::MIN_FREE_BYTES;

        return new PreconditionCheck(
            'update_disk_space',
            $enough,
            $enough
                ? 'There is enough free disk space to download and unpack the update.'
                : 'Only '.$this->formatBytes((int) $free).' of disk space is free. Free up at least 50 MB before updating.',
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' bytes';
    }
}
