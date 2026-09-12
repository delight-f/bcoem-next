<?php

declare(strict_types=1);

namespace App\Services\Installation\Data;

final readonly class AdminInput
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}
}
