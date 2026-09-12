<?php

declare(strict_types=1);

namespace App\Support\Wizard;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The one progress marker both wizard flows use. Cache-backed (a table would
 * be overkill for something used once per install/upgrade) and keyed by a
 * random token the browser generated.
 *
 * The store is resolved ONCE in the constructor and reused for every write.
 * `install()` rewrites `.env` (including CACHE_STORE) while the job that owns
 * this marker is still running; resolving the repository lazily per write
 * would switch stores mid-run and drop the marker. The dedicated `wizard`
 * store also keeps the marker out of `cache:clear`'s path (UpgradeService
 * clears the default store during an upgrade).
 */
final class ProgressTracker
{
    public const STORE = 'wizard';

    private const TTL_SECONDS = 3600;

    private const DEFAULT_MARKER = [
        'status' => 'pending',
        'current_step_label' => 'Waiting to start…',
        'cursor' => 0,
        'payload' => null,
        'state' => [],
        'error' => null,
    ];

    private Repository $store;

    public function __construct(?string $store = null)
    {
        $this->store = Cache::store($store ?? self::STORE);
    }

    public function pending(string $token): void
    {
        $this->store->put($this->key($token), self::DEFAULT_MARKER, self::TTL_SECONDS);
    }

    public function step(string $token, string $label): void
    {
        $this->merge($token, [
            'status' => 'running',
            'current_step_label' => $label,
            'error' => null,
        ]);
    }

    public function complete(string $token, string $label): void
    {
        $this->merge($token, [
            'status' => 'complete',
            'current_step_label' => $label,
            'error' => null,
        ]);
    }

    public function fail(string $token, string $plain, string $technical, ?string $backupPath = null): void
    {
        $this->merge($token, [
            'status' => 'failed',
            'error' => ['plain' => $plain, 'technical' => $technical, 'backup_path' => $backupPath],
        ]);
    }

    /**
     * Public shape the browser polls. Never exposes the encrypted payload or
     * the internal step state.
     *
     * @return array{status: string, current_step_label: string, cursor: int, error: array{plain: string, technical: string, backup_path: string|null}|null}|null
     */
    public function get(string $token): ?array
    {
        $marker = $this->raw($token);

        if ($marker === null) {
            return null;
        }

        $error = $marker['error'] ?? null;

        return [
            'status' => (string) $marker['status'],
            'current_step_label' => (string) ($marker['current_step_label'] ?? ''),
            'cursor' => (int) ($marker['cursor'] ?? 0),
            'error' => is_array($error) ? [
                'plain' => (string) ($error['plain'] ?? ''),
                'technical' => (string) ($error['technical'] ?? ''),
                'backup_path' => isset($error['backup_path']) ? (string) $error['backup_path'] : null,
            ] : null,
        ];
    }

    /**
     * The full marker, for the step runner only. Callers outside the runner
     * must use `get()` so the payload/state do not leave the tracker.
     *
     * @return array<string, mixed>|null
     */
    public function raw(string $token): ?array
    {
        $marker = $this->store->get($this->key($token));

        return is_array($marker) && isset($marker['status']) ? $marker : null;
    }

    /**
     * Merge arbitrary keys onto the raw marker (cursor, state). Public because
     * both wizard controllers advance the cursor from their progress handler.
     *
     * @param  array<string, mixed>  $values
     */
    public function put(string $token, array $values): void
    {
        $this->merge($token, $values);
    }

    public function forget(string $token): void
    {
        $this->store->forget($this->key($token));
    }

    /**
     * Double-submit guard. The first run to acquire the lock wins; a
     * resubmitted Screen 5 gets a "busy" answer instead of a second install.
     */
    public function acquire(string $name, int $seconds = 900): bool
    {
        return $this->store->add($this->key('lock-'.$name), 1, $seconds);
    }

    public function release(string $name): void
    {
        $this->store->forget($this->key('lock-'.$name));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function merge(string $token, array $values): void
    {
        // Base on the raw marker: get() strips cursor/payload/state, so merging
        // onto it would silently drop the step runner's progress.
        $marker = $this->raw($token) ?? self::DEFAULT_MARKER;

        $this->store->put($this->key($token), array_merge($marker, $values), self::TTL_SECONDS);
    }

    private function key(string $token): string
    {
        return 'wizard-progress:'.preg_replace('/[^A-Za-z0-9_-]/', '', $token);
    }
}
