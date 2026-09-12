<?php

declare(strict_types=1);

namespace App\Services\Installation\Data;

final readonly class PreconditionCheck
{
    public function __construct(
        public string $name,
        public bool $passed,
        public string $message,
    ) {}
}
