<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Services\Installation\UpgradeFixup;
use App\Services\Installation\UpgradeFixups;
use Tests\TestCase;

/**
 * The registry is process-global static state, so this test owns a version
 * pair nothing else registers: it can register freely and still prove that a
 * fixup is matched by the exact jump alone.
 */
final class UpgradeFixupsTest extends TestCase
{
    public function test_a_fixup_is_returned_for_its_exact_jump_only(): void
    {
        UpgradeFixups::register('9.8', '9.9', RecordingFixup::class);

        $fixups = (new UpgradeFixups)->for('9.8', '9.9');

        $this->assertCount(1, $fixups);
        $this->assertInstanceOf(RecordingFixup::class, $fixups[0]);

        $before = RecordingFixup::$runs;
        $fixups[0]->run();
        $this->assertSame($before + 1, RecordingFixup::$runs, 'the returned fixup must be runnable');

        $this->assertSame([], (new UpgradeFixups)->for('9.8', '10.0'), 'a wider jump must not run it');
        $this->assertSame([], (new UpgradeFixups)->for('9.9', '10.0'), 'a later jump must not run it');
    }
}

final class RecordingFixup implements UpgradeFixup
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
    }
}
