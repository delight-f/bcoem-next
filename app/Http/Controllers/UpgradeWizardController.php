<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RunUpgradeJob;
use App\Services\Installation\UpgradeService;
use App\Support\Wizard\ProgressTracker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The browser upgrade wizard, reachable only by a Top-Level Administrator
 * (EnsureInstalled gates the routes). It contains no upgrade logic: the
 * backup, migration and version-marker work all lives in UpgradeService.
 */
final class UpgradeWizardController extends Controller
{
    public function whatsNew(UpgradeService $service): View
    {
        $current = $service->getCurrentVersion();
        $incoming = $service->getIncomingVersion();

        return view('wizard.upgrade.whats-new', [
            'current' => $current,
            'incoming' => $incoming,
            'sections' => $this->changelog($current, $incoming),
        ]);
    }

    public function checks(Request $request, UpgradeService $service): View|JsonResponse
    {
        $result = $service->checkPreconditions();

        if ($request->wantsJson()) {
            return response()->json([
                'passed' => $result->passed(),
                'checks' => array_map(
                    static fn ($check): array => ['name' => $check->name, 'passed' => $check->passed, 'message' => $check->message],
                    $result->checks,
                ),
            ]);
        }

        return view('wizard.upgrade.checks', ['result' => $result]);
    }

    public function confirm(UpgradeService $service): View
    {
        return view('wizard.upgrade.confirm', [
            'current' => $service->getCurrentVersion(),
            'incoming' => $service->getIncomingVersion(),
            'supportEmail' => (string) config('services.support.email'),
        ]);
    }

    public function run(Request $request, UpgradeService $service): JsonResponse
    {
        $token = $this->validToken($request);
        if ($token === null) {
            return response()->json(['error' => 'This update session is no longer valid. Please start again.'], 422);
        }

        if (! $service->needsUpgrade()) {
            return response()->json(['error' => 'There is no update to apply.'], 409);
        }

        // ponytail: an abandoned run holds the upgrade lock for 900s; a shorter
        // TTL risks releasing a genuinely slow step.
        $tracker = new ProgressTracker;
        if (! $tracker->acquire('upgrade')) {
            return response()->json(['error' => 'An update is already running. Please wait for it to finish.'], 409);
        }

        // Bookkeeping only: no upgrade work runs here. The steps run from
        // progress(), one per request.
        $tracker->pending($token);

        return response()->json(['token' => $token]);
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
