<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RunUpgradeJob;
use App\Services\Installation\ReleaseUpdater;
use App\Services\Installation\UpdateService;
use App\Services\Installation\UpgradeService;
use App\Support\Wizard\ProgressTracker;
use App\Support\Wizard\RemoteVersionChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The browser update wizard, reachable only by a Top-Level Administrator
 * (EnsureInstalled gates the routes). It contains no upgrade logic: the
 * backup, migration and version-marker work all lives in UpgradeService, and
 * the file download/swap lives in ReleaseUpdater.
 *
 * Two modes share these screens. Manual (the default, unchanged): the new
 * files are already on the server and only the database work remains. Auto:
 * nothing has been uploaded — ReleaseUpdater fetches and swaps the release
 * first, then the same database steps run. UpdateService builds the one list
 * both modes walk.
 */
final class UpgradeWizardController extends Controller
{
    public function whatsNew(UpgradeService $service, ReleaseUpdater $release): View
    {
        $current = $service->getCurrentVersion();
        $target = $this->autoTarget($service, $release);
        $incoming = $target ?? $service->getIncomingVersion();

        return view('wizard.upgrade.whats-new', [
            'current' => $current,
            'incoming' => $incoming,
            'sections' => $this->changelog($current, $incoming),
            'auto' => $target !== null,
            'mode' => $target !== null ? 'auto' : 'manual',
        ]);
    }

    public function checks(Request $request, UpgradeService $service, UpdateService $update, ReleaseUpdater $release): View|JsonResponse
    {
        $auto = $request->query('mode') === 'auto' && $this->autoTarget($service, $release) !== null;
        $result = $update->checkPreconditions($auto);

        if ($request->wantsJson()) {
            return response()->json([
                'passed' => $result->passed(),
                'checks' => array_map(
                    static fn ($check): array => ['name' => $check->name, 'passed' => $check->passed, 'message' => $check->message],
                    $result->checks,
                ),
            ]);
        }

        return view('wizard.upgrade.checks', ['result' => $result, 'mode' => $auto ? 'auto' : 'manual']);
    }

    public function confirm(UpgradeService $service, ReleaseUpdater $release): View
    {
        $target = $this->autoTarget($service, $release);

        return view('wizard.upgrade.confirm', [
            'current' => $service->getCurrentVersion(),
            'incoming' => $target ?? $service->getIncomingVersion(),
            'supportEmail' => (string) config('services.support.email'),
            'mode' => $target !== null ? 'auto' : 'manual',
        ]);
    }

    public function run(Request $request, UpgradeService $service, ReleaseUpdater $release): JsonResponse
    {
        $token = $this->validToken($request);
        if ($token === null) {
            return response()->json(['error' => 'This update session is no longer valid. Please start again.'], 422);
        }

        $mode = $request->input('mode') === 'auto' ? 'auto' : 'manual';

        if ($mode === 'auto') {
            // Auto is only valid while a newer release is genuinely waiting to
            // be fetched; the files being current is the normal case here.
            if ($this->autoTarget($service, $release) === null) {
                return response()->json(['error' => 'There is no update to apply.'], 409);
            }
        } elseif (! $service->needsUpgrade()) {
            return response()->json(['error' => 'There is no update to apply.'], 409);
        }

        // ponytail: an abandoned run holds the upgrade lock for 900s; a shorter
        // TTL risks releasing a genuinely slow step.
        $tracker = new ProgressTracker;
        if (! $tracker->acquire('upgrade')) {
            return response()->json(['error' => 'An update is already running. Please wait for it to finish.'], 409);
        }

        // Bookkeeping only: no upgrade work runs here. The steps run from
        // progress(), one per request. The mode rides on the marker so every
        // step request builds the same list.
        $tracker->pending($token);
        $tracker->put($token, ['state' => ['mode' => $mode]]);

        return response()->json(['token' => $token]);
    }

    /**
     * The published version to fetch automatically, or null when the automatic
     * path is not the right answer. Null whenever the files already hold a
     * version the database has not caught up with — that is the manual upgrade,
     * and re-downloading it would only repeat work.
     */
    private function autoTarget(UpgradeService $service, ReleaseUpdater $release): ?string
    {
        if ($service->needsUpgrade() || ! $release->canSelfUpdate()) {
            return null;
        }

        $latest = app(RemoteVersionChecker::class)->cachedVersion();
        $onDisk = $service->getIncomingVersion();

        if ($latest === null || $latest === '' || $onDisk === '' || ! version_compare($latest, $onDisk, '>')) {
            return null;
        }

        return $latest;
    }

    public function progress(Request $request): JsonResponse
    {
        $token = $this->validToken($request);
        if ($token === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        $tracker = new ProgressTracker;
        $raw = $tracker->raw($token);
        if ($raw === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        // ponytail: this GET mutates. It is gated by the 48-hex token and a
        // live marker, and it is the only path that can resume a dead upgrade.
        $marker = $tracker->get($token);
        if ($marker === null || in_array($marker['status'], ['complete', 'failed'], true)) {
            return response()->json($marker ?? ['status' => 'unknown']);
        }

        if (! $tracker->acquire('step-'.$token, 60)) {
            return response()->json($marker);
        }

        try {
            // Exempt from maintenance mode in bootstrap/app.php: this route is
            // the control plane that has to finish the upgrade that took the
            // site down. One step per request.
            @set_time_limit(0);

            RunUpgradeJob::dispatch($token, $marker['cursor']);
        } finally {
            $tracker->release('step-'.$token);
        }

        // The request that ran the last step returns the terminal marker in
        // band: EnsureInstalled 404s /upgrade/* once the version marker is
        // current, so a following poll could never observe completion.
        return response()->json($tracker->get($token) ?? $marker);
    }

    private function validToken(Request $request): ?string
    {
        $token = (string) $request->input('token', '');

        return Validator::make(['token' => $token], ['token' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/']])->passes()
            ? $token
            : null;
    }

    /**
     * Changelog sections for every version after the installed one up to the
     * files on disk, rendered as plain text rather than raw markdown.
     *
     * @return list<array{version: string, body: string}>
     */
    private function changelog(string $current, string $incoming): array
    {
        $file = base_path('CHANGELOG.md');
        if (! is_file($file)) {
            return [];
        }

        $sections = [];
        foreach (preg_split('/^## \[/m', (string) file_get_contents($file)) ?: [] as $chunk) {
            if (! str_contains($chunk, ']')) {
                continue;
            }

            [$version, $body] = explode(']', $chunk, 2);
            $version = trim($version);

            if ($version === '' || strcasecmp($version, 'Unreleased') === 0) {
                continue;
            }
            if (! version_compare($version, $current, '>') || version_compare($version, $incoming, '>')) {
                continue;
            }

            $sections[] = ['version' => $version, 'body' => $this->plainText($body)];
        }

        return $sections;
    }

    private function plainText(string $markdown): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*\[[^\]]+\]:\s*\S+/', $line) === 1) {
                continue; // link reference definitions
            }
            $line = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $line) ?? $line;
            $line = str_replace(['**', '__', '`'], '', $line);
            $line = preg_replace('/(?<![A-Za-z0-9])\*(?=\S)([^*]+)\*/', '$1', $line) ?? $line;
            $line = preg_replace('/^\s*#{1,6}\s*/', '', $line) ?? $line;

            if (trim($line) !== '') {
                $lines[] = rtrim($line);
            }
        }

        return trim(implode("\n", $lines));
    }
}
