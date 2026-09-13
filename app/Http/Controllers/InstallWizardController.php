<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RunInstallationJob;
use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\Exceptions\InstallationException;
use App\Services\Installation\InstallationService;
use App\Support\Wizard\ProgressTracker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
        $input = $this->databaseInput($request);
        $service = app(InstallationService::class);
        $credentials = $this->credentials($input);

        $connection = $service->testDatabaseConnection($credentials);
        if (! $connection->success) {
            return response()->json([
                'success' => false,
                'state' => 'unreachable',
                'message' => $connection->message,
                'canInstall' => false,
                'canAdopt' => false,
                'warning' => '',
            ]);
        }

        // Connected: the useful question is now what the database already holds.
        // A visitor who replaced the old files but kept their database must be
        // offered that site, not an install that would replace its accounts.
        $inspection = $service->inspectDatabase($credentials);

        return response()->json([
            'success' => true,
            'state' => $inspection->state,
            'version' => $inspection->version,
            'message' => $inspection->message,
            'canInstall' => $inspection->canInstall(),
            'canAdopt' => $inspection->canAdopt(),
            'warning' => $inspection->privilegeWarning,
        ]);
    }

    /**
     * Screen 3's other exit: the database is already a finished site, so keep it
     * and record only how to reach it. No schema import, no accounts created —
     * the site boots on its existing data and the upgrade path takes it forward.
     */
    public function adopt(Request $request): RedirectResponse
    {
        $credentials = $this->credentials($this->databaseInput($request));

        try {
            app(InstallationService::class)->adoptExistingInstallation($credentials, $request->getSchemeAndHttpHost());
        } catch (InstallationException $e) {
            return redirect()->route('wizard.install.database')->withErrors(['database' => $e->plainMessage]);
        }

        // Every later request boots as an installed site; an administrator who
        // signs in is then offered the upgrade to this release's version.
        return redirect('/');
    }

    public function storeDatabase(Request $request): RedirectResponse
    {
        $input = $this->databaseInput($request);
        $service = app(InstallationService::class);
        $credentials = $this->credentials($input);

        // The button is only offered for an empty database, but the client is
        // never trusted: reaching screen 4 with a populated database would end in
        // an install that replaces the club's accounts and results.
        $connection = $service->testDatabaseConnection($credentials);
        if (! $connection->success) {
            return redirect()->route('wizard.install.database')->withErrors(['database' => $connection->message]);
        }

        $inspection = $service->inspectDatabase($credentials);
        if (! $inspection->canInstall()) {
            return redirect()->route('wizard.install.database')->withErrors(['database' => $inspection->message]);
        }

        $request->session()->put('wizard.install.db', $input);

        return redirect()->route('wizard.install.site');
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function databaseInput(Request $request): array
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'string', 'max:5'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
        ]);

        return [
            'host' => $data['host'],
            'port' => $data['port'],
            'database' => $data['database'],
            'username' => $data['username'],
            'password' => (string) ($data['password'] ?? ''),
        ];
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $input
     */
    private function credentials(array $input): DbCredentials
    {
        return new DbCredentials($input['host'], $input['port'], $input['database'], $input['username'], $input['password']);
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
            // Encrypted before it reaches the session store: SESSION_ENCRYPT is
            // off by default, so a plaintext password would wait in the clear
            // in the session store between screens 4 and 6.
            'admin_password' => Crypt::encryptString($data['admin_password']),
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
        if (! is_array($db) || ! is_array($site) || ! is_string($site['admin_password'] ?? null)) {
            return response()->json(['error' => 'Your details expired. Please start again.'], 422);
        }

        try {
            $adminPassword = Crypt::decryptString($site['admin_password']);
        } catch (\Throwable) {
            return response()->json(['error' => 'Your details expired. Please start again.'], 422);
        }

        // ponytail: an abandoned run holds the install lock for 900s; a shorter
        // TTL risks releasing a genuinely slow step.
        $tracker = new ProgressTracker;
        if (! $tracker->acquire('install')) {
            return response()->json(['error' => 'An installation is already running. Please wait for it to finish.'], 409);
        }

        $input = new InstallInput(
            new DbCredentials($db['host'], $db['port'], $db['database'], $db['username'], $db['password']),
            $site['app_url'],
            $site['admin_name'],
            $site['admin_email'],
            $adminPassword,
        );

        // Bookkeeping only: no install work runs here. The input (including the
        // admin password) is encrypted into the marker, so it survives across
        // the per-step polling requests.
        $tracker->pending($token);
        $tracker->put($token, ['payload' => $this->encodeInput($input)]);

        // The password now lives in the cache payload, not the session store.
        $request->session()->forget('wizard.install.site');

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
        // live marker; also requiring the session is impossible because the
        // final step rotates APP_KEY and invalidates the cookie.
        $marker = $tracker->get($token);
        if ($marker === null || in_array($marker['status'], ['complete', 'failed'], true)) {
            return response()->json($marker ?? ['status' => 'unknown']);
        }

        // One step per request, and a retried poll must not run the same step
        // twice, so the step is serialised by its own short lock.
        if (! $tracker->acquire('step-'.$token, 60)) {
            return response()->json($marker);
        }

        try {
            $payload = $raw['payload'] ?? null;

            if (! is_string($payload) || $payload === '') {
                // The payload is written before run() returns; this is the
                // browser polling ahead of the POST, so let it poll again.
                return response()->json($marker);
            }

            $input = $this->decodeInput($payload);
            if ($input === null) {
                // The payload exists but will not decrypt — e.g. APP_KEY was
                // rotated between steps. Fail loudly: the old behaviour looped
                // on a `running` marker forever and the install hung.
                $tracker->fail(
                    $token,
                    'We couldn\'t continue the installation. Please start again.',
                    'The stored install details could not be decrypted; the application key changed mid-install.',
                );
                $tracker->release('install');

                return response()->json($tracker->get($token) ?? $marker);
            }

            // ponytail: one step is one request, so a host's max_execution_time
            // still caps a single unit. set_time_limit(0) covers hosts where
            // that is allowed; otherwise importBaseSchema() is the ceiling.
            @set_time_limit(0);

            RunInstallationJob::dispatch($input, $token, $marker['cursor']);
        } finally {
            $tracker->release('step-'.$token);
        }

        // The request that ran the last step returns the terminal marker in
        // band: EnsureInstalled 404s /install/* once the install is marked done,
        // so a following poll could never observe completion.
        return response()->json($tracker->get($token) ?? $marker);
    }

    /**
     * The input as a single encrypted string. The `wizard` cache store has
     * `serializable_classes => false`, so an object cannot be stored — a string
     * is mandatory.
     */
    private function encodeInput(InstallInput $input): string
    {
        return Crypt::encryptString((string) json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function decodeInput(string $payload): ?InstallInput
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode(Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $db */
            $db = $data['db'];

            return new InstallInput(
                new DbCredentials(
                    (string) $db['host'],
                    (string) $db['port'],
                    (string) $db['database'],
                    (string) $db['username'],
                    (string) $db['password'],
                ),
                (string) $data['appUrl'],
                (string) $data['adminName'],
                (string) $data['adminEmail'],
                (string) $data['adminPassword'],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function validToken(Request $request): ?string
    {
        $token = (string) $request->input('token', '');

        return Validator::make(['token' => $token], ['token' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/']])->passes()
            ? $token
            : null;
    }
}
