<?php

declare(strict_types=1);

namespace App\Services\Installation;

/**
 * A single, individually testable data fixup for one exact version jump.
 * `UpgradeService` runs every fixup registered for the jump it is performing.
 */
interface UpgradeFixup
{
    public function run(): void;
}
