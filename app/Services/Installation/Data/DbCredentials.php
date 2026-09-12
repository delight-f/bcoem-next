<?php

declare(strict_types=1);

namespace App\Services\Installation\Data;

final readonly class DbCredentials
{
    public function __construct(
        public string $host,
        public string $port,
        public string $database,
        public string $username,
        public string $password,
    ) {}
}
