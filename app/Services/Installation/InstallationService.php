<?php

declare(strict_types=1);

namespace App\Services\Installation;

use App\Services\Installation\Data\AdminInput;
use App\Services\Installation\Data\BackupResult;
use App\Services\Installation\Data\ConnectionTestResult;
use App\Services\Installation\Data\DatabaseInspection;
use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\Data\PreconditionCheck;
use App\Services\Installation\Data\PreconditionResult;
use App\Services\Installation\Exceptions\AlreadyInstalledException;
use App\Services\Installation\Exceptions\DatabaseConnectionException;
use App\Services\Installation\Exceptions\InstallationException;
use App\Services\Installation\Exceptions\NotInstalledException;
use App\Services\Installation\Exceptions\WritePermissionException;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use PDOStatement;

/**
 * The one place that knows how to stand up a fresh install.
 *
 * Everything the install wizard, the `app:install` command and the SSH script
 * do routes through here; none of them re-implement any of it.
 */
class InstallationService
{
    /** Version recorded in `bcoem_sys` when the release zip ships no VERSION file. */
    public const SHIPPED_VERSION = '4.0.0';

    private string $rootPath;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = rtrim($rootPath ?? base_path(), '/');
    }

    public function isAlreadyInstalled(): bool
    {
        try {
            if (! Schema::hasTable('bcoem_sys')) {
                return false;
            }

            return (int) DB::table('bcoem_sys')->where('id', 1)->value('setup') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The version this copy of the application ships: the release's VERSION
     * file, or SHIPPED_VERSION when there is none (a git checkout).
     *
     * The marker written at the end of an install must record this, not the
     * constant: a release whose VERSION is "4.1.0-alpha.3" that marks itself
     * "4.0.0" immediately advertises an upgrade to the version it is running.
     * Static so UpgradeService resolves the same value from its own root.
     */
    public static function versionIn(string $rootPath): string
    {
        $file = rtrim($rootPath, '/').'/VERSION';
        if (is_file($file)) {
            $version = trim((string) file_get_contents($file));
            if ($version !== '') {
                return $version;
            }
        }

        return self::SHIPPED_VERSION;
    }

    public function incomingVersion(): string
    {
        return self::versionIn($this->rootPath);
    }

    public function checkPreconditions(): PreconditionResult
    {
        return new PreconditionResult([
            $this->checkPhpVersion(),
            ...$this->checkExtensions(),
            $this->checkWritable('storage', base_path('storage')),
            $this->checkWritable('bootstrap/cache', base_path('bootstrap/cache')),
        ]);
    }

    public function testDatabaseConnection(DbCredentials $credentials): ConnectionTestResult
    {
        try {
            $this->connect($credentials)->query('SELECT 1');

            return new ConnectionTestResult(true, 'Connected to the database successfully.');
        } catch (PDOException $e) {
            return new ConnectionTestResult(false, $this->plainConnectionMessage($e, $credentials));
        }
    }

    /**
     * A throwaway connection to the database the credentials name. Deliberately
     * independent of the ambient `mysql` config: every caller here is looking at
     * a database the application is not (yet) configured to use.
     */
    private function connect(DbCredentials $credentials): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $credentials->host,
                $credentials->port,
                $credentials->database,
            ),
            $credentials->username,
            $credentials->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
    }

    /**
     * What the target database already holds, read through the credentials the
     * caller supplied. Read-only, and safe to call before anything is written.
     *
     * A caller that has replaced the old application's files but kept its
     * database has to be told that, and offered the existing site rather than an
     * install that would replace it. Expects testDatabaseConnection() to have
     * succeeded already; this throws if the database cannot be reached.
     */
    public function inspectDatabase(DbCredentials $credentials): DatabaseInspection
    {
        $pdo = $this->connect($credentials);

        $statement = $pdo->query('SHOW TABLES');
        $tables = $statement === false ? [] : array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $count = count($tables);
        $warning = $this->privilegeWarning($pdo);

        if ($count === 0) {
            return new DatabaseInspection(
                DatabaseInspection::STATE_EMPTY,
                'That database is empty, so it is ready for a new site.',
                '',
                0,
                $warning,
            );
        }

        if (! in_array('bcoem_sys', $tables, true)) {
            return new DatabaseInspection(
                DatabaseInspection::STATE_UNRECOGNISED,
                'That database already holds '.$count.' tables, but none of them belong to a Brew Competition site. Check the database name: installing here would overwrite whatever is in it.',
                '',
                $count,
                $warning,
            );
        }

        $row = $pdo->query('SELECT setup, version FROM bcoem_sys WHERE id = 1') ?: [];
        $record = $row instanceof PDOStatement ? ($row->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $setup = (int) ($record['setup'] ?? 0);
        $version = trim((string) ($record['version'] ?? ''));

        if ($setup === 1) {
            return new DatabaseInspection(
                DatabaseInspection::STATE_INSTALLED,
                'This database already holds a Brew Competition site'
                    .($version !== '' ? ' (version '.$version.')' : '')
                    .'. Keep its entries, members and results by attaching this installation to it.',
                $version,
                $count,
                $warning,
            );
        }

        return new DatabaseInspection(
            DatabaseInspection::STATE_INCOMPLETE,
            'This database holds a Brew Competition site that never finished setting up. Installing over it could damage what is there — start from an empty database, or restore a backup first.',
            $version,
            $count,
            $warning,
        );
    }

    /**
     * Advisory note when the account being stored can administer the whole
     * server. `.env` lives in the served directory, so those credentials sit in
     * plain text behind the web server; a user scoped to this one database keeps
     * that leak cheap. Never fatal — plenty of hosts issue only one account.
     */
    private function privilegeWarning(PDO $pdo): string
    {
        try {
            $statement = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
            if ($statement === false) {
                return '';
            }

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $grant = trim((string) reset($row));

                // "GRANT USAGE ON *.*" is the empty marker every account carries;
                // any wider privilege covering the whole server is not.
                if (preg_match('/\bON\s+\*\.\*/i', $grant) === 1
                    && preg_match('/^GRANT\s+USAGE\s+ON\s+\*\.\*/i', $grant) !== 1) {
                    return 'This database account can administer the entire database server. The site keeps these details in plain text inside the web folder, so a dedicated account limited to this one database is safer.';
                }
            }
        } catch (\Throwable) {
            // Advisory only: a host that hides SHOW GRANTS is not an error.
        }

        return '';
    }

    /**
     * Attach this copy of the application to a database that is already a
     * finished installation (someone uploaded the new files over the old site
     * and kept the database).
     *
     * Writes connection details only — no schema import, no admin account, no
     * version marker. The data stays exactly as it is, the site boots on it, and
     * the ordinary upgrade path carries it forward from the version the database
     * records.
     */
    public function adoptExistingInstallation(DbCredentials $credentials, string $appUrl): void
    {
        $inspection = $this->inspectDatabase($credentials);

        if (! $inspection->canAdopt()) {
            throw new NotInstalledException(
                'Refusing to adopt: target database is '.$inspection->state.'.',
                $inspection->message,
            );
        }

        $this->writeEnvValues([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $credentials->host,
            'DB_PORT' => $credentials->port,
            'DB_DATABASE' => $credentials->database,
            'DB_USERNAME' => $credentials->username,
            'DB_PASSWORD' => $credentials->password,
            'DB_TABLE_PREFIX' => '',
        ]);
    }

    /**
     * @param  (callable(string, BackupResult|null): void)|null  $onStep  Same progress hook UpgradeService uses.
     */
    public function install(InstallInput $input, ?callable $onStep = null): void
    {
        $state = [];

        foreach ($this->steps($input) as $step) {
            $this->step($onStep, $step['label']);
            ($step['run'])($state);
        }
    }

    /**
     * The install sequence as individually runnable units. `install()` loops
     * this same list, so the CLI (one process) and the resumable wizard (one
     * unit per request) can never diverge.
     *
     * Every step re-applies the caller's database credentials: a later request
     * must never rely on the ambient connection still pointing at the target.
     *
     * @return list<array{label: string, run: \Closure(array<string, mixed> &$state): void}>
     */
    public function steps(InstallInput $input): array
    {
        return [
            [
                'label' => 'Writing your configuration…',
                'run' => function (array &$state) use ($input): void {
                    $ambientMysql = (array) config('database.connections.mysql');
                    $ambientDefault = config('database.default');

                    // Point the connection at the credentials the caller named
                    // before the "already installed" probe. Probing first reads
                    // whatever the ambient config points at (a stale .env,
                    // exported DB_*), which can throw against a valid input.
                    $this->applyDatabaseConfig($input->db, '');

                    try {
                        // Fail before writing anything: a bad password must leave the site untouched.
                        $connection = $this->testDatabaseConnection($input->db);
                        if (! $connection->success) {
                            throw new DatabaseConnectionException(
                                'Database connection failed for '.$input->db->host.':'.$input->db->port.'/'.$input->db->database.'.',
                                $connection->message,
                            );
                        }

                        // Refuse a database that already holds anything. install()
                        // imports the baseline schema and createAdmin() deletes every
                        // row in `users` and `brewer`, so pointed at an existing site
                        // it destroys the club's accounts and results rather than
                        // setting one up. Adopting is the supported path instead.
                        $inspection = $this->inspectDatabase($input->db);
                        if (! $inspection->canInstall()) {
                            throw new AlreadyInstalledException(
                                'Refusing to install: target database is '.$inspection->state.' ('.$inspection->tableCount.' tables).',
                                $inspection->message,
                            );
                        }
                    } catch (\Throwable $e) {
                        // A failed pre-flight must not strand the rest of the
                        // request on the credentials it just rejected; put the
                        // ambient connection back before marking the failure.
                        config()->set('database.connections.mysql', $ambientMysql);
                        config()->set('database.default', $ambientDefault);
                        DB::purge('mysql');
                        DB::setDefaultConnection(is_string($ambientDefault) ? $ambientDefault : 'mysql');

                        throw $e;
                    }

                    $this->writeEnvValues([
                        'APP_ENV' => 'production',
                        'APP_DEBUG' => 'false',
                        'APP_URL' => $input->appUrl,
                        'DB_CONNECTION' => 'mysql',
                        'DB_HOST' => $input->db->host,
                        'DB_PORT' => $input->db->port,
                        'DB_DATABASE' => $input->db->database,
                        'DB_USERNAME' => $input->db->username,
                        'DB_PASSWORD' => $input->db->password,
                        'DB_TABLE_PREFIX' => '',
                        // A fresh install has no `cache` table (it lives in the
                        // skipped framework 0001 migration), so the shipped
                        // default would break the site the moment caching is
                        // used. File cache needs only storage/.
                        'CACHE_STORE' => 'file',
                        // Same reason: the `sessions` table is skipped too, so
                        // the shipped database driver would break the first
                        // request after an otherwise successful install.
                        'SESSION_DRIVER' => 'file',
                        // And the `jobs` table is skipped with them, so the
                        // shipped database driver would stall every queued job.
                        // The wizard never runs a worker: sync is the only queue
                        // that works on a fresh host.
                        'QUEUE_CONNECTION' => 'sync',
                    ]);
                },
            ],
            // ponytail: one step is one request, but importBaseSchema() is a
            // single exec of the whole baseline dump, so a very large baseline
            // can still outlast the host's limit. set_time_limit(0) in the
            // runner covers the common case; chunking the dump is a further,
            // larger change.
            [
                'label' => 'Setting up your database…',
                'run' => function (array &$state) use ($input): void {
                    $this->applyDatabaseConfig($input->db, '');
                    $this->importBaseSchema();
                },
            ],
            [
                'label' => 'Applying database updates…',
                'run' => function (array &$state) use ($input): void {
                    $this->applyDatabaseConfig($input->db, '');
                    $this->runPendingMigrations();
                },
            ],
            [
                'label' => 'Creating your admin account…',
                'run' => function (array &$state) use ($input): void {
                    $this->applyDatabaseConfig($input->db, '');
                    $this->createAdmin($input->admin());
                },
            ],
            [
                'label' => 'Finishing up…',
                'run' => function (array &$state) use ($input): void {
                    $this->applyDatabaseConfig($input->db, '');

                    // The key is generated in the FINAL step, not its own. The
                    // wizard's payload is Crypt-encrypted with APP_KEY, so
                    // rotating it earlier would leave every later progress
                    // request unable to decrypt the input — production boots a
                    // fresh process from the rotated .env per request, and the
                    // install would hang. Nothing before this point encrypts:
                    // the baseline import and migrations use no Crypt, and
                    // createAdmin() is bcrypt only.
                    $key = 'base64:'.base64_encode(random_bytes(32));
                    $this->writeEnvValues(['APP_KEY' => $key]);
                    config()->set('app.key', $key);

                    $this->writeInstalledMarker($this->incomingVersion());
                },
            ],
        ];
    }

    /**
     * The plain + technical pair for the marker. The one place that knows how
     * an install failure should read, so the CLI and the wizard agree.
     *
     * @return array{plain: string, technical: string, backup_path: null}
     */
    public function describeFailure(\Throwable $e): array
    {
        return [
            'plain' => $e instanceof InstallationException
                ? $e->plainMessage
                : 'Something went wrong while installing your site.',
            'technical' => $e->getMessage(),
            'backup_path' => null,
        ];
    }

    /**
     * Apply every port-added migration. Shared by fresh install and upgrade so
     * the migrator is invoked from exactly one place.
     */
    public function runPendingMigrations(): void
    {
        $paths = [];
        foreach (glob(base_path('database/migrations/*.php')) ?: [] as $file) {
            if (str_starts_with(basename($file), '0001_')) {
                continue;
            }
            $paths[] = 'database/migrations/'.basename($file);
        }

        Artisan::call('migrate', ['--force' => true, '--path' => $paths]);
    }

    /**
     * The legacy schema this port keeps: `sql/bcoem_baseline_3.0.X.sql`, table
     * prefix rewritten. A fresh install cannot be produced from
     * `database/migrations/` alone — those are port additions on top of it.
     */
    public function importBaseSchema(string $prefix = ''): void
    {
        $sql = (string) file_get_contents(base_path('sql/bcoem_baseline_3.0.X.sql'));
        $sql = str_replace('`baseline_', '`'.$prefix, $sql);

        DB::connection()->getPdo()->exec($sql);
    }

    private function checkPhpVersion(): PreconditionCheck
    {
        $constraint = (string) ($this->composerRequire()['php'] ?? '^8.4');

        // PHP_VERSION carries vendor suffixes ("8.4.22-nfsn1") that composer/semver
        // rejects as an invalid version string; the numeric constants never do.
        $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;

        $satisfied = Semver::satisfies($version, $constraint);

        return new PreconditionCheck(
            'php_version',
            $satisfied,
            $satisfied
                ? 'PHP '.$version.' meets the requirement ('.$constraint.').'
                : 'This site needs PHP '.$constraint.'. This server runs PHP '.$version.'.',
        );
    }

    /**
     * The extension list is read from composer.json so this service, CI and the
     * SSH check script all agree on one source of truth.
     *
     * @return list<PreconditionCheck>
     */
    private function checkExtensions(): array
    {
        $checks = [];
        foreach (array_keys($this->composerRequire()) as $package) {
            if (! is_string($package) || ! str_starts_with($package, 'ext-')) {
                continue;
            }
            $extension = substr($package, 4);
            $loaded = extension_loaded($extension);
            $checks[] = new PreconditionCheck(
                $package,
                $loaded,
                $loaded
                    ? 'The '.$extension.' extension is installed.'
                    : 'The '.$extension.' PHP extension is missing. Ask your host to enable '.$package.'.',
            );
        }

        return $checks;
    }

    /**
     * @return array<string, mixed>
     */
    private function composerRequire(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        /** @var array<string, mixed> $require */
        $require = is_array($decoded) && is_array($decoded['require'] ?? null) ? $decoded['require'] : [];

        return $require;
    }

    private function checkWritable(string $name, string $path): PreconditionCheck
    {
        $writable = is_dir($path) && is_writable($path);

        return new PreconditionCheck(
            'writable:'.$name,
            $writable,
            $writable
                ? $name.' is writable.'
                : $name.' is not writable. Set its permissions so PHP can write to it (usually chmod 775).',
        );
    }

    private function plainConnectionMessage(PDOException $e, DbCredentials $c): string
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        $technical = $e->getMessage();

        $unreachable = in_array($driverCode, [2002, 2003, 2005], true)
            || str_contains($technical, 'getaddrinfo')
            || str_contains($technical, 'Connection refused')
            || str_contains($technical, 'Name or service not known');

        if ($unreachable) {
            return 'We couldn\'t reach a database server at '.$c->host.':'.$c->port.'. Check the host name, and that your database server is running.';
        }

        return match ($driverCode) {
            1045 => 'That database password looks wrong. Check the password from your hosting control panel and try again.',
            1044 => 'That database user isn\'t allowed to use "'.$c->database.'". Check the username and permissions in your hosting control panel.',
            1049 => 'We reached the database server, but a database called "'.$c->database.'" doesn\'t exist yet. Create it in your hosting control panel, then try again.',
            default => 'We couldn\'t connect to the database. Check the details from your hosting control panel and try again.',
        };
    }

    private function applyDatabaseConfig(DbCredentials $credentials, string $prefix): void
    {
        $connection = array_merge((array) config('database.connections.mysql'), [
            'host' => $credentials->host,
            'port' => $credentials->port,
            'database' => $credentials->database,
            'username' => $credentials->username,
            'password' => $credentials->password,
            'prefix' => $prefix,
        ]);

        config()->set('database.connections.mysql', $connection);
        config()->set('database.default', 'mysql');
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnvValues(array $values): void
    {
        $path = $this->rootPath.'/.env';

        if (is_file($path)) {
            $contents = (string) file_get_contents($path);
        } else {
            $example = base_path('.env.example');
            $contents = is_file($example) ? (string) file_get_contents($example) : '';
        }

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->envValue($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents) === 1
                ? (string) preg_replace($pattern, $line, $contents)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new WritePermissionException(
                'Could not write '.$path.'.',
                'We couldn\'t save your settings. Make sure the site\'s files are writable, then try again.',
            );
        }
    }

    private function envValue(string $value): string
    {
        return preg_match('/[\\s#"\'$]/', $value) === 1 ? '"'.$value.'"' : $value;
    }

    private function createAdmin(AdminInput $admin): void
    {
        // The baseline dump ships a known-password fixture administrator. It is
        // not the account this install was asked to create, so remove it rather
        // than leaving a publicly documented login on the new site.
        DB::table('users')->delete();
        DB::table('brewer')->delete();

        $parts = preg_split('/\s+/', trim($admin->name), 2) ?: [];
        $first = $parts[0] === '' ? $admin->email : $parts[0];
        $last = $parts[1] ?? '';

        $id = DB::table('users')->insertGetId([
            'user_name' => $admin->email,
            'password' => Hash::make($admin->password),
            'userLevel' => '0',
            'userCreated' => now()->toDateTimeString(),
            'userFailedLogins' => 0,
            'userAdminObfuscate' => 0,
        ]);

        DB::table('brewer')->insert([
            'uid' => $id,
            'brewerFirstName' => $first,
            'brewerLastName' => $last,
            'brewerEmail' => $admin->email,
        ]);
    }

    private function writeInstalledMarker(string $version): void
    {
        $values = [
            'version' => $version,
            'version_date' => now()->toDateString(),
            'setup' => 1,
            'setup_last_step' => 8,
            'update_date' => (string) time(),
        ];

        if (DB::table('bcoem_sys')->where('id', 1)->exists()) {
            DB::table('bcoem_sys')->where('id', 1)->update($values);
        } else {
            DB::table('bcoem_sys')->insert(array_merge(['id' => 1], $values));
        }
    }

    /**
     * @param  (callable(string, BackupResult|null): void)|null  $onStep
     */
    private function step(?callable $onStep, string $label): void
    {
        if ($onStep !== null) {
            $onStep($label, null);
        }
    }
}
