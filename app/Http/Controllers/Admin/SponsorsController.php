<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sponsors admin (spec §7 P5.4) — port of admin/sponsors.admin.php +
 * process_sponsors.inc.php.
 *
 * Two write paths, mirroring legacy:
 *  - add/edit form: name/location/level/url/image/text/display with
 *    check_http() on the URL and blank_to_null everywhere;
 *  - the inline list form (action=update): bulk-writes sponsorEnable,
 *    sponsorLevel, sponsorImage, sponsorText per posted id[] row (the
 *    per-row AJAX column saves in legacy collapse into this one submit).
 *
 * The logo dropdown lists files from public/user_images (legacy
 * USER_IMAGES directory_contents_dropdown); an empty directory renders
 * "no images" exactly like legacy.
 */
final class SponsorsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();

        return view('admin.sponsors', [
            'ctx' => $ctx,
            'sponsors' => DB::table('sponsors')->orderBy('sponsorName')->get(),
            'sponsorImages' => self::imageFiles(),
            // Public-display master switches (prefsSponsors / prefsSponsorLogos)
            // live under Website Preferences; surface their state here so the
            // per-row Display tick is not a dead end.
            'sponsorsEnabled' => $ctx->prefsStr('prefsSponsors') === 'Y',
            'logosEnabled' => $ctx->prefsStr('prefsSponsorLogos') === 'Y',
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $ctx = TenantContext::load();

        return view('admin.sponsors', [
            'ctx' => $ctx,
            'sponsors' => DB::table('sponsors')->orderBy('sponsorName')->get(),
            'sponsorImages' => self::imageFiles(),
            'editing' => null,
            'sponsorsEnabled' => $ctx->prefsStr('prefsSponsors') === 'Y',
            'logosEnabled' => $ctx->prefsStr('prefsSponsorLogos') === 'Y',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $row = self::row($request);

        DB::table('sponsors')->insert($row);

        self::enablePublicDisplay((int) $row['sponsorEnable'] === 1, $row['sponsorImage'] !== null && $row['sponsorImage'] !== '');

        return redirect('/admin/sponsors?msg=9');
    }

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        $ctx = TenantContext::load();

        return view('admin.sponsors', [
            'ctx' => $ctx,
            'sponsors' => DB::table('sponsors')->orderBy('sponsorName')->get(),
            'sponsorImages' => self::imageFiles(),
            'editing' => DB::table('sponsors')->where('id', $id)->first(),
            'sponsorsEnabled' => $ctx->prefsStr('prefsSponsors') === 'Y',
            'logosEnabled' => $ctx->prefsStr('prefsSponsorLogos') === 'Y',
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $existing = DB::table('sponsors')->where('id', $id)->first();
        if ($existing === null) {
            return redirect('/admin/sponsors');
        }

        $row = self::row($request);

        // No image dropdown rendered (empty directory, or the stored file is
        // not among the listed extensions): keep the stored logo rather than
        // blanking it on an unrelated save.
        if (! $request->has('sponsorImage')) {
            $row['sponsorImage'] = $existing->sponsorImage;
        }

        DB::table('sponsors')->where('id', $id)->update($row);

        self::enablePublicDisplay((int) $row['sponsorEnable'] === 1, $row['sponsorImage'] !== null && $row['sponsorImage'] !== '');

        return redirect('/admin/sponsors?msg=9');
    }

    /** Bulk update from the inline list form. */
    public function bulkUpdate(Request $request): RedirectResponse
    {
        $ids = array_values(array_filter(
            array_map(intval(...), (array) $request->input('id', [])),
            static fn (int $id): bool => $id > 0,
        ));

        // The bulk form must enforce the same rules as the add/edit path, or
        // it becomes a weaker write route.
        if ($ids === []) {
            return redirect('/admin/sponsors')->with('error', 'No sponsors were selected to update.');
        }

        $rules = [];
        foreach ($ids as $id) {
            $rules['sponsorLevel'.$id] = ['nullable', 'in:1,2,3,4,5'];
            $rules['sponsorImage'.$id] = ['nullable', 'string', 'max:255'];
            $rules['sponsorText'.$id] = ['nullable', 'string'];
            $rules['sponsorEnable'.$id] = ['nullable', 'in:0,1'];
        }
        $request->validate($rules);

        $anyDisplayed = false;
        $anyDisplayedWithLogo = false;

        foreach ($ids as $id) {
            $enabled = $request->boolean('sponsorEnable'.$id);
            $image = self::blankToNull((string) $request->input('sponsorImage'.$id, ''));

            DB::table('sponsors')->where('id', $id)->update([
                'sponsorEnable' => $enabled ? 1 : 0,
                'sponsorLevel' => self::blankToNull((string) $request->input('sponsorLevel'.$id, '')),
                'sponsorImage' => $image,
                // Legacy purifies this HTML fragment; stored verbatim here.
                'sponsorText' => self::blankToNull(trim((string) $request->input('sponsorText'.$id, ''))),
            ]);

            if ($enabled) {
                $anyDisplayed = true;
                $anyDisplayedWithLogo = $anyDisplayedWithLogo || $image !== null;
            }
        }

        self::enablePublicDisplay($anyDisplayed, $anyDisplayedWithLogo);

        return redirect('/admin/sponsors?msg=9');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        if (! DB::table('sponsors')->where('id', $id)->exists()) {
            return redirect('/admin/sponsors');
        }

        DB::table('sponsors')->delete($id);

        return redirect('/admin/sponsors?msg=9');
    }

    /**
     * Add/edit storage row — exact process_sponsors.inc.php column map.
     *
     * @return array<string, mixed>
     */
    private static function row(Request $request): array
    {
        $data = $request->validate([
            'sponsorName' => ['required', 'string', 'max:255'],
            'sponsorLocation' => ['nullable', 'string', 'max:255'],
            'sponsorLevel' => ['nullable', 'in:1,2,3,4,5'],
            'sponsorURL' => ['nullable', 'string', 'max:255'],
            'sponsorImage' => ['nullable', 'string', 'max:255'],
            'sponsorText' => ['nullable', 'string'],
            'sponsorEnable' => ['required', 'in:0,1'],
        ]);

        return [
            'sponsorName' => self::blankToNull(ucwords((string) $data['sponsorName'])),
            'sponsorURL' => self::checkHttp((string) ($data['sponsorURL'] ?? '')), // null on empty
            'sponsorImage' => self::blankToNull((string) ($data['sponsorImage'] ?? '')),
            'sponsorText' => self::blankToNull((string) ($data['sponsorText'] ?? '')),
            'sponsorLocation' => self::blankToNull((string) ($data['sponsorLocation'] ?? '')),
            'sponsorLevel' => self::blankToNull((string) ($data['sponsorLevel'] ?? '')),
            'sponsorEnable' => (int) $data['sponsorEnable'],
        ];
    }

    /** @return list<string> */
    private static function imageFiles(): array
    {
        $directory = public_path('user_images');
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'] as $extension) {
            foreach (glob($directory.'/*.'.$extension) ?: [] as $path) {
                $files[] = basename((string) $path);
            }
        }

        sort($files);

        return $files;
    }

    /**
     * A displayed sponsor implies the public Sponsors section should be on, so
     * saving one with Display = Yes (re)enables the master switches — the row
     * tick otherwise had no visible effect and the section looked unwired.
     * It never turns a switch off: a deliberate "Disable" in Website
     * Preferences stays until the admin displays a sponsor again.
     */
    private static function enablePublicDisplay(bool $sponsorShown, bool $hasLogo): void
    {
        if (! $sponsorShown) {
            return;
        }

        $prefs = ['prefsSponsors' => 'Y'];
        if ($hasLogo) {
            $prefs['prefsSponsorLogos'] = 'Y';
        }

        DB::table('preferences')->where('id', 1)->update($prefs);
    }

    private static function checkHttp(string $input): ?string
    {
        if ($input === '') {
            return null;
        }

        if (str_contains($input, 'http://') || str_contains($input, 'https://')) {
            return $input;
        }

        return 'http://'.$input;
    }

    private static function blankToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
