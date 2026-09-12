<?php

declare(strict_types=1);

namespace App\Support\Wizard;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Tells a Top-Level Administrator that a newer release has been published.
 *
 * Deliberately standalone and separate from UpgradeService: that service
 * compares the VERSION file already sitting on the server to the recorded
 * version, while this one is about files the admin has not fetched yet. The
 * two checks share no state and neither depends on the other.
 *
 * Every external failure (timeout, no network, rate limit, malformed body) is
 * swallowed into `null`; a host with restricted outbound access must never see
 * an error because of this.
 */
final class RemoteVersionChecker
{
    private const CACHE_KEY = 'bcoem.remote-version';

    private const TTL_SECONDS = 86400;

    public function __construct(private readonly ?string $repository = null) {}

    /**
     * The version of the latest published GitHub release, e.g. "4.0.1", or
     * null when the check cannot be completed. Never throws.
     */
    public function latestPublishedVersion(): ?string
    {
        $cached = $this->read();
        if ($cached !== null && $this->isFresh($cached['checked_at'])) {
            return $cached['version'];
        }

        $version = $this->fetch();
        $this->write($version);

        return $version;
    }

    /**
     * The last checked version without ever making an HTTP request.
     */
    public function cachedVersion(): ?string
    {
        return $this->read()['version'] ?? null;
    }

    /**
     * Refresh a stale (or absent) cache after the response is sent, so a page
     * load never waits on an external call the admin did not ask for.
     */
    public function refreshIfStale(): void
    {
        $cached = $this->read();
        if ($cached !== null && $this->isFresh($cached['checked_at'])) {
            return;
        }

        defer(fn (): ?string => $this->latestPublishedVersion());
    }

    /**
     * The dashboard notice, or null when there is nothing to say. The check
     * does not run at all for anyone below Top-Level Administrator, rather
     * than running and being hidden in the view.
     *
     * @return array{version: string, url: string}|null
     */
    public function noticeFor(?int $userLevel, string $currentVersion): ?array
    {
        if ($userLevel !== 0) {
            return null;
        }

        $this->refreshIfStale();

        $latest = $this->cachedVersion();
        if ($latest === null || $currentVersion === '' || ! version_compare($latest, $currentVersion, '>')) {
            return null;
        }

        return ['version' => $latest, 'url' => $this->releasesUrl()];
    }

    public function releasesUrl(): string
    {
        return 'https://github.com/'.$this->repositoryName().'/releases';
    }

    private function fetch(): ?string
    {
        try {
            $response = Http::timeout((int) config('services.github.timeout_seconds', 5))
                ->acceptJson()
                ->get('https://api.github.com/repos/'.$this->repositoryName().'/releases/latest');

            if (! $response->successful()) {
                return null;
            }

            $tag = trim((string) $response->json('tag_name'));

            return $tag !== '' ? ltrim($tag, 'v') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{version: string|null, checked_at: int}|null
     */
    private function read(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached) || ! array_key_exists('version', $cached) || ! isset($cached['checked_at'])) {
            return null;
        }

        return ['version' => is_string($cached['version']) ? $cached['version'] : null, 'checked_at' => (int) $cached['checked_at']];
    }

    private function write(?string $version): void
    {
        Cache::put(self::CACHE_KEY, ['version' => $version, 'checked_at' => time()], self::TTL_SECONDS);
    }

    private function isFresh(int $checkedAt): bool
    {
        return (time() - $checkedAt) < self::TTL_SECONDS;
    }

    private function repositoryName(): string
    {
        $repository = $this->repository ?? (string) config('services.github.repository', 'bcoem/bcoem-next');

        return trim($repository, '/') !== '' ? trim($repository, '/') : 'bcoem/bcoem-next';
    }
}
