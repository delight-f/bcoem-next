<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Support\Wizard\ProgressTracker;
use Tests\TestCase;

final class ProgressTrackerTest extends TestCase
{
    /**
     * The exact cache-store pinning bug: install() rewrites .env (including
     * CACHE_STORE) while the job owns the marker. A pinned repository keeps
     * writing to the store it started with; a lazily resolved one would move.
     */
    public function test_marker_survives_a_cache_store_change_when_the_store_is_pinned(): void
    {
        config()->set('cache.default', 'array');

        $tracker = new ProgressTracker('array');
        $tracker->pending('token12345678');
        $tracker->step('token12345678', 'Setting up your database…');

        // The install rewrite lands: the default store is something else now.
        config()->set('cache.default', 'file');

        $marker = $tracker->get('token12345678') ?? [];
        $this->assertSame('running', $marker['status'] ?? null);
        $this->assertSame('Setting up your database…', $marker['current_step_label'] ?? null);

        // A tracker that resolved the *new* default would not see it.
        $this->assertNull((new ProgressTracker('file'))->get('token12345678'));
    }

    public function test_marker_carries_plain_and_technical_messages_on_failure(): void
    {
        $tracker = new ProgressTracker('array');
        $tracker->pending('tokenfail1234');
        $tracker->fail(
            'tokenfail1234',
            'That database password looks wrong.',
            'SQLSTATE[HY000] [1045] Access denied',
            '/tmp/pre-upgrade.sql',
        );

        $marker = $tracker->get('tokenfail1234') ?? [];
        $error = $marker['error'] ?? [];

        $this->assertSame('failed', $marker['status'] ?? null);
        $this->assertSame('That database password looks wrong.', $error['plain'] ?? null);
        $this->assertSame('SQLSTATE[HY000] [1045] Access denied', $error['technical'] ?? null);
        $this->assertSame('/tmp/pre-upgrade.sql', $error['backup_path'] ?? null);
    }

    public function test_lock_admits_only_one_run_at_a_time(): void
    {
        $tracker = new ProgressTracker('array');

        $this->assertTrue($tracker->acquire('install'));
        $this->assertFalse($tracker->acquire('install'));

        $tracker->release('install');
        $this->assertTrue($tracker->acquire('install'));
    }

    public function test_marker_transitions_pending_running_complete(): void
    {
        $tracker = new ProgressTracker('array');

        $this->assertNull($tracker->get('token1abc234'));

        $tracker->pending('token1abc234');
        $this->assertSame('pending', ($tracker->get('token1abc234') ?? [])['status'] ?? null);

        $tracker->step('token1abc234', 'Writing your configuration…');
        $this->assertSame('running', ($tracker->get('token1abc234') ?? [])['status'] ?? null);

        $tracker->complete('token1abc234', 'Your site is ready.');
        $marker = $tracker->get('token1abc234') ?? [];
        $this->assertSame('complete', $marker['status'] ?? null);
        $this->assertSame('Your site is ready.', $marker['current_step_label'] ?? null);
        $this->assertNull($marker['error'] ?? null);
    }
}
