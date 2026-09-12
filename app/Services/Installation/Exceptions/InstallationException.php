<?php

declare(strict_types=1);

namespace App\Services\Installation\Exceptions;

use RuntimeException;

/**
 * Base for every typed failure the installation/upgrade services raise.
 *
 * `getMessage()` stays technical (for logs, CLI output and support tickets);
 * `plainMessage` is the same failure written for a non-technical club member
 * and is what the web wizard shows by default.
 */
abstract class InstallationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $plainMessage,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
