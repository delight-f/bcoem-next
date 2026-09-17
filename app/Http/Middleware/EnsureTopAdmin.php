<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Top-Level Administrator gate (userLevel 0) for the handful of actions a
 * mid-level admin may not perform: changing another user's level or password,
 * publishing results, purging, regenerating judging numbers, running the
 * on-demand update check.
 *
 * Mirrors the inline `$user === null || (int) $user->userLevel !== 0` checks
 * it replaces (the explicit null guard matters — `(int) null === 0` would
 * otherwise let a level-less account through).
 */
final class EnsureTopAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (int) $user->userLevel === 0) {
            return $next($request);
        }

        return EnsureAdmin::deny($request);
    }
}
