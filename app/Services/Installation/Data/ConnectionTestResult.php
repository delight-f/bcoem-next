<?php

declare(strict_types=1);

namespace App\Services\Installation\Data;

final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $success,
        public string $message,
    ) {}
}
