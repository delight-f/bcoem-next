<?php

declare(strict_types=1);

namespace App\Services\Installation;

use App\Services\Installation\Data\AdminInput;
use App\Services\Installation\Data\BackupResult;
use App\Services\Installation\Data\ConnectionTestResult;
use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\Data\PreconditionCheck;
use App\Services\Installation\Data\PreconditionResult;
use App\Services\Installation\Exceptions\AlreadyInstalledException;
use App\Services\Installation\Exceptions\DatabaseConnectionException;
use App\Services\Installation\Exceptions\WritePermissionException;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;

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
            $pdo = new PDO(
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
            $pdo->query('SELECT 1');

            return new ConnectionTestResult(true, 'Connected to the database successfully.');
        } catch (PDOException $e) {
            return new ConnectionTestResult(false, $this->plainConnectionMessage($e, $credentials));
        }
    }

    /**
     * @param  (callable(string, BackupResult|null): void)|null  $onStep  Same progress hook UpgradeService uses.
     */
    public function install(InstallInput $input, ?callable $onStep = null): void
    {
        $ambientMysql = (array) config('database.connections.mysql');
        $ambientDefault = config('database.default');

        // Point the connection at the credentials the caller named before the
        // "already installed" probe. Probing first reads whatever the ambient
        // config points at (a stale .env, exported DB_*), which can throw
        // against a perfectly valid input. The probe must mean "is the
        // database I was asked to install into already installed".
        $this->applyDatabaseConfig($input->db, '');

        try {
            if ($this->isAlreadyInstalled()) {
                throw new AlreadyInstalledException(
                    'Refusing to install: bcoem_sys.setup is already 1.',
                    'This site is already installed. Use the upgrade process to update it instead.',
                );
            }

            // Fail before writing anything: a bad password must leave the site untouched.
            $connection = $this->testDatabaseConnection($input->db);
            if (! $connection->success) {
                throw new DatabaseConnectionException(
                    'Database connection failed for '.$input->db->host.':'.$input->db->port.'/'.$input->db->database.'.',
                    $connection->message,
                );
            }
        } catch (\Throwable $e) {
            // A failed pre-flight must not strand the rest of the request on
            // the credentials it just rejected (the wizard tears down its own
            // connection after a pollable failure); put the ambient one back.
            config()->set('database.connections.mysql', $ambientMysql);
            config()->set('database.default', $ambientDefault);
            DB::purge('mysql');
            DB::setDefaultConnection(is_string($ambientDefault) ? $ambientDefault : 'mysql');

            throw $e;
        }

        $this->step($onStep, 'Writing your configuration…');
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
            // A fresh install has no `cache` table (it lives in the skipped
            // framework 0001 migration), so the shipped default would break
            // the site the moment caching is used. File cache needs only the
            // already-checked storage/ directory.
            'CACHE_STORE' => 'file',
            // Same reason: the `sessions` table is skipped with the framework
            // migrations too, so the shipped database driver would break the
            // first request after an otherwise successful install.
            'SESSION_DRIVER' => 'file',
            // And the `jobs` table is skipped with them, so the shipped
            // database driver would stall every queued job. The wizard never
            // runs a worker: sync is the only queue that works on a fresh host.
            'QUEUE_CONNECTION' => 'sync',
        ]);

        $this->step($onStep, 'Generating a new application key…');
        $key = 'base64:'.base64_encode(random_bytes(32));
        $this->writeEnvValues(['APP_KEY' => $key]);
        config()->set('app.key', $key);

        $this->step($onStep, 'Setting up your database…');
        $this->importBaseSchema();
        $this->runPendingMigrations();

        $this->step($onStep, 'Creating your admin account…');
        $this->createAdmin($input->admin());

        $this->step($onStep, 'Finishing up…');
        $this->writeInstalledMarker(self::SHIPPED_VERSION);
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

        $satisfied = Semver::satisfies(PHP_VERSION, $constraint);

        return new PreconditionCheck(
            'php_version',
            $satisfied,
            $satisfied
                ? 'PHP '.PHP_VERSION.' meets the requirement ('.$constraint.').'
                : 'This site needs PHP '.$constraint.'. This server runs PHP '.PHP_VERSION.'.',
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
