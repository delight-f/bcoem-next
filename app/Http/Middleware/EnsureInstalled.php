<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Installation\InstallationService;
use App\Services\Installation\UpgradeService;
use App\Support\Wizard\RemoteVersionChecker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boot-time install/upgrade routing, applied to the whole `web` group before
 * any controller runs.
 *
 * - Not installed: every request redirects to the install wizard, except the
 *   wizard's own surface, which is an explicit allow-list (never a deny-list
 *   of known app routes — that would break again on the next route added).
 * - Installed, newer files present, Top-Level Administrator: a dismissable
 *   per-session banner links to the upgrade wizard.
 * - Installed and current: both wizards 404.
 */
final class EnsureInstalled
{
    /**
     * Reachable while uninstalled. `install/*` covers the wizard screens, the
     * `/install/database/test` connection check and `/install/progress`
     * polling; `build/*` and `vendor/*` are its static assets; `up` is the
     * health endpoint.
     */
    private const INSTALLER_SURFACE = [
        'install',
        'install/*',
        'build/*',
        'vendor/*',
        'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');

        try {
            $installed = app(InstallationService::class)->isAlreadyInstalled();
        } catch (\Throwable) {
            $installed = false;
        }

        if (! $installed) {
            return $this->matches($path, self::INSTALLER_SURFACE)
                ? $next($request)
                : redirect()->route('wizard.install.welcome');
        }

        $upgrade = app(UpgradeService::class);
        $needsUpgrade = $upgrade->needsUpgrade();

        // A newer release that has not been fetched yet also makes the wizard
        // worth reaching: the automatic update lives behind these same routes.
        // Read from the cache only — no HTTP on a page load.
        $availableRelease = $needsUpgrade ? null : $this->availableRelease($upgrade);

        $isInstallPath = $path === 'install' || str_starts_with($path, 'install/');
        $isUpgradePath = $path === 'upgrade' || str_starts_with($path, 'upgrade/');

        if (($isInstallPath || $isUpgradePath) && ! $needsUpgrade && $availableRelease === null) {
            abort(404);
        }

        if ($isUpgradePath && ! $this->isTopLevelAdmin($request)) {
            abort(403);
        }

        // Always share, null included: View::share persists for the lifetime of
        // the application instance, so a banner shared on an earlier request
        // would otherwise outlive its dismissal (visible in long-running
        // workers and across test requests).
        $banner = null;
        if (($needsUpgrade || $availableRelease !== null) && $this->isTopLevelAdmin($request)
            && ! $request->session()->get('wizard.upgrade.dismissed', false)) {
            $banner = [
                'current' => $upgrade->getCurrentVersion(),
                'version' => $availableRelease ?? $upgrade->getIncomingVersion(),
                'url' => route('wizard.upgrade.whats_new'),
                'dismiss' => route('wizard.upgrade.dismiss'),
            ];
        }
        View::share('upgradeBanner', $banner);

        return $next($request);
    }

    /**
     * The latest published version when it is newer than the files on disk, or
     * null. Cache-only, so a page load never waits on GitHub.
     */
    private function availableRelease(UpgradeService $upgrade): ?string
    {
        try {
            $latest = app(RemoteVersionChecker::class)->cachedVersion();
        } catch (\Throwable) {
            return null;
        }

        $onDisk = $upgrade->getIncomingVersion();

        return $latest !== null && $latest !== '' && $onDisk !== '' && version_compare($latest, $onDisk, '>')
            ? $latest
            : null;
    }

    /**
     * Top-Level Administrator == legacy userLevel 0; mid-level admins (1) must
     * not see the banner or reach the upgrade wizard.
     */
    private function isTopLevelAdmin(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && (int) $user->userLevel === 0;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
