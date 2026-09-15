<?php

declare(strict_types=1);

namespace App\Services\Installation;

use App\Services\Installation\Data\PreconditionResult;

/**
 * The single step list the browser update runner walks.
 *
 * Manual mode (files already uploaded) is exactly UpgradeService::steps(), so
 * the existing wizard behaviour is untouched. Auto mode PREPENDS the
 * ReleaseUpdater file steps and then runs the same UpgradeService steps: the
 * upgrade order stays the documented contract, and no caller re-implements any
 * database, backup or migration logic.
 */
final class UpdateService
{
    /**
     * @param  array<string, mixed>  $state
     * @return list<array{label: string, run: \Closure(array<string, mixed> &$state): void}>
     */
    public function steps(array $state): array
    {
        $upgrade = app(UpgradeService::class);

        if (($state['mode'] ?? 'manual') !== 'auto') {
            return $upgrade->steps();
        }

        return array_merge(app(ReleaseUpdater::class)->steps(), $upgrade->steps());
    }

    public function checkPreconditions(bool $auto): PreconditionResult
    {
        $upgrade = app(UpgradeService::class)->checkPreconditions();

        if (! $auto) {
            return $upgrade;
        }

        return new PreconditionResult(array_merge(
            $upgrade->checks,
            app(ReleaseUpdater::class)->checkPreconditions()->checks,
        ));
    }

    /**
     * Which half a failure came from, decided by where the cursor sat when the
     * step threw.
     *
     * @param  array<string, mixed>  $state
     * @return array{plain: string, technical: string, backup_path: string|null}
     */
    public function describeFailure(array $state, \Throwable $e, int $cursor): array
    {
        if (($state['mode'] ?? 'manual') === 'auto'
            && $cursor < count(app(ReleaseUpdater::class)->steps())) {
            return app(ReleaseUpdater::class)->describeFailure($state, $e);
        }

        return app(UpgradeService::class)->describeFailure($state, $e);
    }
}
