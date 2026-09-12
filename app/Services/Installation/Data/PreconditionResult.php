<?php

declare(strict_types=1);

namespace App\Services\Installation\Data;

final readonly class PreconditionResult
{
    /**
     * @param  list<PreconditionCheck>  $checks
     */
    public function __construct(
        public array $checks = [],
    ) {}

    public function passed(): bool
    {
        foreach ($this->checks as $check) {
            if (! $check->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<PreconditionCheck>
     */
    public function failed(): array
    {
        return array_values(array_filter($this->checks, static fn (PreconditionCheck $c): bool => ! $c->passed));
    }
}
