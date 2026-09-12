<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Installation\UpgradeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * `php artisan app:version` — prints the stored version, optionally checking
 * GitHub for a newer release. The check is best-effort: a host with no
 * outbound network access just omits the line.
 */
final class VersionCommand extends Command
{
    private const RELEASES_URL = 'https://api.github.com/repos/delight-f/bcoem-next/releases/latest';

    protected $signature = 'app:version {--check : Also check GitHub for a newer release}';

    protected $description = 'Print the installed BCOEM version.';

    public function handle(UpgradeService $service): int
    {
        $current = $service->getCurrentVersion();
        $this->line($current !== '' ? $current : 'unknown');

        if (! $this->option('check')) {
            return self::SUCCESS;
        }

        $latest = $this->latestRelease();
        if ($latest !== null && version_compare($current, $latest, '<')) {
            $this->warn('Version '.$latest.' is available: https://github.com/delight-f/bcoem-next/releases');
        }

        return self::SUCCESS;
    }

    private function latestRelease(): ?string
    {
        try {
            $response = Http::timeout(3)->get(self::RELEASES_URL);
            if (! $response->successful()) {
                return null;
            }

            $tag = $response->json('tag_name');

            return is_string($tag) && $tag !== '' ? ltrim($tag, 'v') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
