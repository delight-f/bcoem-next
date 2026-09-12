<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Brewer\ClubsSyncService;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central clubs list admin surface (issue #22, Task B.5).
 *
 * A small settings page rather than a section bolted onto competition-info:
 * this is about the installed site's mirrored list, not one contest field.
 * The admin gate matches every other screen in routes/admin.php
 * (userLevel <= 1 via UsersRow::isAdmin()).
 *
 * The "no longer in the central list" review is informational only — it is
 * the human-visible side of ClubsSyncService's never-delete rule. Nothing
 * here removes a club.
 */
final class ClubsAdminController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $version = DB::table('clubs_sync_state')->where('id', 1)->value('version');
        $syncedAt = DB::table('clubs_sync_state')->where('id', 1)->value('synced_at');
        $syncedAt = is_string($syncedAt) && $syncedAt !== '' ? $syncedAt : null;

        return view('admin.clubs', [
            'ctx' => TenantContext::load(),
            'version' => is_string($version) && $version !== '' ? $version : null,
            'syncedAt' => $syncedAt,
            'total' => DB::table('clubs')->count(),
            'upstreamCount' => DB::table('clubs')->where('source', 'upstream')->count(),
            'dropped' => $this->droppedOff($syncedAt),
        ]);
    }

    public function sync(Request $request, ClubsSyncService $service): RedirectResponse
    {
        if (! ($request->user()?->isAdmin() ?? false)) {
            return redirect('/?msg=99');
        }

        $result = $service->sync();

        return redirect('/admin/clubs')->with($result->ok ? 'status' : 'error', $result->summary());
    }

    /**
     * Clubs that came from the central list but were absent from the most
     * recent successful sync (their last_seen_at predates it, or was never
     * set). Returns [] before the first sync.
     *
     * @return list<array{name: string, last_seen_at: string|null}>
     */
    private function droppedOff(?string $syncedAt): array
    {
        if ($syncedAt === null) {
            return [];
        }

        $rows = DB::table('clubs')
            ->where('source', 'upstream')
            ->where(function (Builder $query) use ($syncedAt): void {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $syncedAt);
            })
            ->orderBy('name')
            ->get(['name', 'last_seen_at'])
            ->map(static function (object $row): array {
                $values = (array) $row;

                return [
                    'name' => (string) ($values['name'] ?? ''),
                    'last_seen_at' => isset($values['last_seen_at']) ? (string) $values['last_seen_at'] : null,
                ];
            })
            ->all();

        return array_values($rows);
    }
}
