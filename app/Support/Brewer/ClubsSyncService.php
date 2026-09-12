<?php

declare(strict_types=1);

namespace App\Support\Brewer;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mirrors the published central clubs list into the local `clubs` table
 * (issue #22, Part B).
 *
 * The service knows only the agreed JSON shape ({version, clubs[]}); it has
 * no knowledge of the upstream JavaScript, GitHub, or how the artifact was
 * produced. Part A's converter is the only thing that understands
 * `clubs.js`, which is exactly the decoupling the issue asks for.
 *
 * Contract:
 *  - A fetch failure, a non-2xx response, a timeout or a malformed payload
 *    is a genuine no-op: a warning is logged and nothing is written. The app
 *    keeps using whatever was synced last time.
 *  - The stored content-derived `version` short-circuits a run whose upstream
 *    content has not changed, so a daily schedule does not churn the table.
 *  - All upserts happen in one transaction; the stored version/timestamp is
 *    written only after that transaction has committed.
 *  - Clubs are never deleted because they disappeared upstream. A dropped
 *    club keeps its history and is surfaced for admin review instead.
 *
 * Casing precedence (an explicit decision, per the issue): when an upstream
 * club matches an existing local row by normalized name, the LOCAL spelling
 * is kept and only `last_seen_at` is refreshed. An admin may have adjusted a
 * name deliberately for display, and casing differences are not worth
 * contesting. Upstream casing is used only for rows that do not exist yet.
 */
final class ClubsSyncService
{
    public function sync(): SyncResult
    {
        $url = (string) config('services.clubs_list.source_url');
        $timeout = (int) config('services.clubs_list.timeout_seconds', 10);

        if ($url === '') {
            Log::warning('Clubs list sync: no source URL configured.');

            return SyncResult::failure('no clubs list URL is configured');
        }

        try {
            $response = Http::timeout($timeout)->acceptJson()->get($url);
        } catch (Throwable $e) {
            Log::warning('Clubs list sync: could not reach the source.', [
                'url' => $url,
                'exception' => $e->getMessage(),
            ]);

            return SyncResult::failure('could not reach the clubs list source ('.$e->getMessage().')');
        }

        if (! $response->successful()) {
            Log::warning('Clubs list sync: the source returned a non-success status.', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return SyncResult::failure('the clubs list source returned HTTP '.$response->status());
        }

        $payload = $response->json();
        $clubs = is_array($payload) ? ($payload['clubs'] ?? null) : null;
        $version = is_array($payload) ? ($payload['version'] ?? null) : null;

        if (! is_string($version) || $version === '' || ! is_array($clubs) || $clubs === []) {
            Log::warning('Clubs list sync: malformed payload.', ['url' => $url]);

            return SyncResult::failure('the clubs list payload was malformed (no version, or no non-empty clubs array)');
        }

        $names = $this->validatedNames($clubs, $url);

        if ($names === null) {
            return SyncResult::failure('the clubs list payload contained an invalid club entry');
        }

        if ($this->currentVersion() === $version) {
            return SyncResult::upToDate($version);
        }

        $now = now();
        $added = 0;
        $updated = 0;

        DB::transaction(function () use ($names, $now, &$added, &$updated): void {
            foreach ($names as $name) {
                $normalized = mb_strtolower($name);

                $existingId = DB::table('clubs')->where('name_normalized', $normalized)->value('id');

                if ($existingId !== null) {
                    // Local casing wins: refresh the sighting, never the name.
                    DB::table('clubs')->where('id', $existingId)->update([
                        'last_seen_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $updated++;

                    continue;
                }

                DB::table('clubs')->insert([
                    'name' => $name,
                    'name_normalized' => $normalized,
                    'source' => 'upstream',
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $added++;
            }
        });

        // Only after the transaction has committed.
        $this->rememberVersion($version, $now);

        return SyncResult::success($version, $added, $updated, 0);
    }

    /**
     * Trim, reject empty/non-string entries and drop any duplicate the
     * published artifact should not have contained. Returns null when the
     * payload is not usable.
     *
     * @param  array<array-key, mixed>  $clubs
     * @return list<string>|null
     */
    private function validatedNames(array $clubs, string $url): ?array
    {
        $names = [];
        $seen = [];

        foreach ($clubs as $club) {
            if (! is_string($club) || trim($club) === '') {
                Log::warning('Clubs list sync: malformed payload (invalid club entry).', [
                    'url' => $url,
                    'entry' => $club,
                ]);

                return null;
            }

            $name = trim($club);
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $names[] = $name;
        }

        return $names === [] ? null : $names;
    }

    private function currentVersion(): ?string
    {
        $version = DB::table('clubs_sync_state')->where('id', 1)->value('version');

        return is_string($version) ? $version : null;
    }

    private function rememberVersion(string $version, DateTimeInterface $at): void
    {
        if (DB::table('clubs_sync_state')->where('id', 1)->exists()) {
            DB::table('clubs_sync_state')->where('id', 1)->update([
                'version' => $version,
                'synced_at' => $at,
                'updated_at' => $at,
            ]);

            return;
        }

        DB::table('clubs_sync_state')->insert([
            'id' => 1,
            'version' => $version,
            'synced_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
