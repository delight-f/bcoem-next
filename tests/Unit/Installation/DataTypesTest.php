<?php

declare(strict_types=1);

namespace BCOEM\Tests\Unit\Installation;

use App\Services\Installation\Data\BackupResult;
use App\Services\Installation\Data\ConnectionTestResult;
use App\Services\Installation\Data\PreconditionCheck;
use App\Services\Installation\Data\PreconditionResult;
use App\Services\Installation\Exceptions\AlreadyInstalledException;
use PHPUnit\Framework\TestCase;

final class DataTypesTest extends TestCase
{
    public function test_precondition_result_reports_aggregate_and_failures(): void
    {
        $passing = new PreconditionResult([
            new PreconditionCheck('php_version', true, 'ok'),
        ]);
        $this->assertTrue($passing->passed());
        $this->assertSame([], $passing->failed());

        $failing = new PreconditionResult([
            new PreconditionCheck('php_version', true, 'ok'),
            new PreconditionCheck('ext-gd', false, 'missing'),
        ]);
        $this->assertFalse($failing->passed());
        $this->assertSame(['ext-gd'], array_map(
            static fn (PreconditionCheck $check): string => $check->name,
            $failing->failed(),
        ));
    }

    public function test_exception_keeps_plain_message_separate_from_technical(): void
    {
        $exception = new AlreadyInstalledException('bcoem_sys.setup is already 1', 'This site is already installed.');

        $this->assertSame('bcoem_sys.setup is already 1', $exception->getMessage());
        $this->assertSame('This site is already installed.', $exception->plainMessage);
    }

    public function test_result_types_expose_their_fields(): void
    {
        $connection = new ConnectionTestResult(true, 'Connected.');
        $this->assertTrue($connection->success);
        $this->assertSame('Connected.', $connection->message);

        $backup = new BackupResult('/tmp/pre-upgrade.sql', 2048, 'php_export');
        $this->assertSame('/tmp/pre-upgrade.sql', $backup->path);
        $this->assertSame(2048, $backup->sizeBytes);
        $this->assertSame('php_export', $backup->method);
    }
}
