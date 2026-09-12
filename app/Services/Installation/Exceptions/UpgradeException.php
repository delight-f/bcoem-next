<?php

declare(strict_types=1);

namespace App\Services\Installation\Exceptions;

/**
 * Wraps whatever failed after the maintenance-mode step of an upgrade, so the
 * backup file that was already taken travels with the failure and every caller
 * (CLI, web) can tell the operator where their data is. The original throwable
 * is preserved as `getPrevious()`.
 */
final class UpgradeException extends InstallationException
{
    public function __construct(
        string $message,
        string $plainMessage,
        int $code,
        ?\Throwable $previous,
        public readonly ?string $backupPath = null,
    ) {
        parent::__construct($message, $plainMessage, $code, $previous);
    }
}
