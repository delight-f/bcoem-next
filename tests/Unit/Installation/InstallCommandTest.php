<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Console\Commands\InstallCommand;
use App\Services\Installation\Data\ConnectionTestResult;
use App\Services\Installation\Data\DbCredentials;
use App\Services\Installation\Data\InstallInput;
use App\Services\Installation\Data\PreconditionCheck;
use App\Services\Installation\Data\PreconditionResult;
use App\Services\Installation\InstallationService;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class InstallCommandTest extends TestCase
{
    private RecordingInstallationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('BCOEM_INSTALL_DB_PASSWORD');
        putenv('BCOEM_INSTALL_ADMIN_PASSWORD');

        $this->service = new RecordingInstallationService;
        $this->app->instance(InstallationService::class, $this->service);
    }

    protected function tearDown(): void
    {
        putenv('BCOEM_INSTALL_DB_PASSWORD');
        putenv('BCOEM_INSTALL_ADMIN_PASSWORD');

        parent::tearDown();
    }

    public function test_secrets_are_read_from_one_stdin_line_each(): void
    {
        putenv('BCOEM_INSTALL_DB_PASSWORD=db-from-env');
        putenv('BCOEM_INSTALL_ADMIN_PASSWORD=admin-from-env');

        [$exit, $output] = $this->runCommand($this->baseOptions() + [
            '--db-password-stdin' => true,
            '--admin-password-stdin' => true,
        ], "db-from-stdin\nadmin-from-stdin\n");

        $this->assertSame(0, $exit);
        $this->assertCount(1, $this->service->installs);
        $this->assertSame('db-from-stdin', $this->service->installs[0]->db->password);
        $this->assertSame('admin-from-stdin', $this->service->installs[0]->adminPassword);
        $this->assertStringNotContainsString('db-from-stdin', $output);
        $this->assertStringNotContainsString('admin-from-stdin', $output);
    }

    public function test_secrets_are_read_from_the_environment(): void
    {
        putenv('BCOEM_INSTALL_DB_PASSWORD=db-from-env');
        putenv('BCOEM_INSTALL_ADMIN_PASSWORD=admin-from-env');

        [$exit] = $this->runCommand($this->baseOptions());

        $this->assertSame(0, $exit);
        $this->assertCount(1, $this->service->installs);
        $this->assertSame('db-from-env', $this->service->installs[0]->db->password);
        $this->assertSame('admin-from-env', $this->service->installs[0]->adminPassword);
    }

    public function test_explicit_flag_beats_stdin_and_environment(): void
    {
        putenv('BCOEM_INSTALL_DB_PASSWORD=db-from-env');
        putenv('BCOEM_INSTALL_ADMIN_PASSWORD=admin-from-env');

        [$exit] = $this->runCommand($this->baseOptions() + [
            '--db-password' => 'db-from-flag',
            '--db-password-stdin' => true,
        ], "db-from-stdin\n");

        $this->assertSame(0, $exit);
        $this->assertSame('db-from-flag', $this->service->installs[0]->db->password);
        $this->assertSame('admin-from-env', $this->service->installs[0]->adminPassword);
    }

    public function test_a_missing_secret_still_fails_cleanly(): void
    {
        [$exit, $output] = $this->runCommand($this->baseOptions(), '', false);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Missing required value(s)', $output);
        $this->assertStringContainsString('db-password', $output);
        $this->assertStringContainsString('admin-password', $output);
        $this->assertSame([], $this->service->installs);
    }

    /**
     * @return array<string, string>
     */
    private function baseOptions(): array
    {
        return [
            '--db-host' => '127.0.0.1',
            '--db-port' => '3306',
            '--db-name' => 'bcoem',
            '--db-username' => 'bcoem',
            '--app-url' => 'https://beer.example.com',
            '--admin-name' => 'Club Admin',
            '--admin-email' => 'admin@example.com',
        ];
    }

    /**
     * Run the command directly so a stream can stand in for STDIN.
     *
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function runCommand(array $options, string $stdin = '', bool $interactive = true): array
    {
        $input = new ArrayInput($options);
        $input->setInteractive($interactive);

        if ($stdin !== '') {
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                self::fail('Could not open an in-memory stream.');
            }
            fwrite($stream, $stdin);
            rewind($stream);
            $input->setStream($stream);
        }

        /** @var Application $app */
        $app = $this->app;
        $command = $app->make(InstallCommand::class);
        $command->setLaravel($app);

        $output = new BufferedOutput;
        $exit = $command->run($input, $output);

        return [$exit, $output->fetch()];
    }
}

final class RecordingInstallationService extends InstallationService
{
    /** @var list<InstallInput> */
    public array $installs = [];

    public function checkPreconditions(): PreconditionResult
    {
        return new PreconditionResult([
            new PreconditionCheck('php_version', true, 'PHP meets the requirement.'),
        ]);
    }

    public function testDatabaseConnection(DbCredentials $credentials): ConnectionTestResult
    {
        return new ConnectionTestResult(true, 'Connected to the database successfully.');
    }

    public function install(InstallInput $input, ?callable $onStep = null): void
    {
        $this->installs[] = $input;
    }
}
