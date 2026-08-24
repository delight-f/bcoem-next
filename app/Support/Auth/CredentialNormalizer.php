<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Legacy credential normalization (`normalize_email_username()` in
 * legacy/common.lib.php): lowercase + trim + FILTER_SANITIZE_EMAIL. The
 * same pipeline must be applied at registration and lookup so stored and
 * queried values never diverge (legacy comment: using HTML-entity encoding
 * anywhere in this chain silently locks the account out).
 */
final class CredentialNormalizer
{
    public static function username(mixed $value): string
    {
        return strtolower(trim((string) filter_var((string) $value, FILTER_SANITIZE_EMAIL)));
    }
}
