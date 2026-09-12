<?php

declare(strict_types=1);

namespace App\Services\Installation\Data;

final readonly class BackupResult
{
    public function __construct(
        public string $path,
        public int $sizeBytes,
        public string $method,
    ) {}
}
