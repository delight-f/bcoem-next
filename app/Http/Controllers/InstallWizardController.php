<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RunInstallationJob;
use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\InstallationService;
use App\Support\Wizard\ProgressTracker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The browser install wizard. Screen by screen, plain language, one decision
 * per screen. It contains no install logic: everything goes through
 * InstallationService, and progress goes through the shared ProgressTracker
 * the CLI hook also feeds.
 */
final class InstallWizardController extends Controller
{
    public function welcome(): View
    {
        return view('wizard.install.welcome');
    }

    public function checks(Request $request): View|JsonResponse
    {
        $result = app(InstallationService::class)->checkPreconditions();

        if ($request->wantsJson()) {
            return response()->json([
                'passed' => $result->passed(),
                'checks' => array_map(
                    static fn ($check): array => ['name' => $check->name, 'passed' => $check->passed, 'message' => $check->message],
                    $result->checks,
                ),
            ]);
        }

        return view('wizard.install.checks', ['result' => $result]);
    }

    public function database(Request $request): View|RedirectResponse
    {
        return view('wizard.install.database', [
            'values' => $request->session()->get('wizard.install.db', []),
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'string', 'max:5'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
        ]);

        $result = app(InstallationService::class)->testDatabaseConnection(new DbCredentials(
            $data['host'],
            $data['port'],
            $data['database'],
            $data['username'],
            (string) ($data['password'] ?? ''),
        ));

        return response()->json(['success' => $result->success, 'message' => $result->message]);
    }

    public function storeDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'string', 'max:5'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
        ]);

        $request->session()->put('wizard.install.db', [
            'host' => $data['host'],
            'port' => $data['port'],
            'database' => $data['database'],
            'username' => $data['username'],
            'password' => (string) ($data['password'] ?? ''),
        ]);

        return redirect()->route('wizard.install.site');
    }

    public function site(Request $request): View|RedirectResponse
    {
        if (! is_array($request->session()->get('wizard.install.db'))) {
            return redirect()->route('wizard.install.database');
        }

        return view('wizard.install.site', [
            'values' => $request->session()->get('wizard.install.site', []),
            'defaultUrl' => $request->getSchemeAndHttpHost(),
        ]);
    }

    public function storeSite(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_url' => ['required', 'url', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->session()->put('wizard.install.site', [
            'app_url' => $data['app_url'],
            'admin_name' => $data['admin_name'],
            'admin_email' => $data['admin_email'],
            'admin_password' => $data['admin_password'],
        ]);

        return redirect()->route('wizard.install.confirm');
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $db = $request->session()->get('wizard.install.db');
        $site = $request->session()->get('wizard.install.site');

        if (! is_array($db) || ! is_array($site)) {
            return redirect()->route('wizard.install.database');
        }

        return view('wizard.install.confirm', ['db' => $db, 'site' => $site]);
    }

    public function run(Request $request): JsonResponse
    {
        $token = $this->validToken($request);
        if ($token === null) {
            return response()->json(['error' => 'This install session is no longer valid. Please start again.'], 422);
        }

        // Double-submit guard: a resubmitted Screen 5 must not install twice.
        if (app(InstallationService::class)->isAlreadyInstalled()) {
            return response()->json(['error' => 'This site is already installed.'], 409);
        }

        $db = $request->session()->get('wizard.install.db');
        $site = $request->session()->get('wizard.install.site');
        if (! is_array($db) || ! is_array($site)) {
            return response()->json(['error' => 'Your details expired. Please start again.'], 422);
        }

        $tracker = new ProgressTracker;
        if (! $tracker->acquire('install')) {
            return response()->json(['error' => 'An installation is already running. Please wait for it to finish.'], 409);
        }

        $tracker->pending($token);

        $input = new InstallInput(
            new DbCredentials($db['host'], $db['port'], $db['database'], $db['username'], $db['password']),
            $site['app_url'],
            $site['admin_name'],
            $site['admin_email'],
            $site['admin_password'],
        );

        RunInstallationJob::dispatch($input, $token);

        return response()->json(['token' => $token]);
    }

    public function progress(Request $request): JsonResponse
    {
        $token = $this->validToken($request);
        $marker = $token !== null ? (new ProgressTracker)->get($token) : null;

        if ($marker === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        return response()->json($marker);
    }

    private function validToken(Request $request): ?string
    {
        $token = (string) $request->input('token', '');

        return Validator::make(['token' => $token], ['token' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/']])->passes()
            ? $token
            : null;
    }
}
