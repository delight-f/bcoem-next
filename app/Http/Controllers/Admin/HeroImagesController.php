<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Banner (hero) images admin — port of admin/hero_images.admin.php +
 * lib/hero_images.lib.php.
 *
 * The image set is discovered from public/images by filename convention:
 * known category prefixes (misc-/beer-/cider-/mead- or 0..3-), legacy
 * *_3000x500 names, or wide-banner dimensions (≥1200px, ratio ≥3.5).
 * Enabled choices persist as the prefsHeroImages JSON map
 * {filename: bool} on preferences id=1; newly discovered images default to
 * enabled when a stored map exists (load_hero_images_preferences()).
 *
 * Upload parity: category must be valid, extension/mime whitelisted, size
 * ≤5MB, minimum width 1200px and aspect ratio ≥3.5; the file is renamed
 * <category>-<sanitized-stem>[.<counter>].<ext> and saved into public/images.
 * Delete removes the file then re-saves the prefs map without it.
 */
final class HeroImagesController extends Controller
{
    private const CATEGORY_PREFIXES = ['0' => 'misc', '1' => 'beer', '2' => 'cider', '3' => 'mead'];

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const MIN_WIDTH = 1200;

    private const MIN_RATIO = 3.5;

    public function index(Request $request): View|RedirectResponse
    {
        $allImages = self::discover();

        return view('admin.hero-images', [
            'ctx' => TenantContext::load(),
            'imagesByCategory' => $allImages,
            'prefs' => $this->loadPrefs($allImages),
            'categories' => self::CATEGORY_PREFIXES,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $map = [];
        foreach (self::discover() as $images) {
            foreach ($images as $image) {
                // Checkbox name: non-alphanumerics folded to underscores.
                $field = 'hero_image_'.preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $image);
                $map[$image] = $request->boolean($field);
            }
        }

        DB::table('preferences')->where('id', 1)->update([
            'prefsHeroImages' => json_encode($map, JSON_THROW_ON_ERROR),
        ]);

        return redirect('/admin/hero-images?msg=saved');
    }

    public function upload(Request $request): RedirectResponse
    {
        $category = (string) $request->input('hero_image_category', '');
        if (! isset(self::CATEGORY_PREFIXES[$category])) {
            return back()->withErrors(['hero_image_category' => 'Please select a valid category.']);
        }

        $request->validate([
            'hero_image_file' => ['required', 'file', 'max:'.(int) (self::MAX_UPLOAD_BYTES / 1024)],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('hero_image_file');
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return back()->withErrors([
                'hero_image_file' => 'Unsupported file type. Allowed: JPG, JPEG, PNG, GIF, WebP.',
            ]);
        }

        if (! in_array((string) $file->getMimeType(), self::ALLOWED_MIMES, true)) {
            return back()->withErrors([
                'hero_image_file' => 'Uploaded file is not a valid image file.',
            ]);
        }

        [$width, $height] = self::dimensions($file->getRealPath());
        if ($width === 0 || $height === 0) {
            return back()->withErrors(['hero_image_file' => 'Uploaded file is not a valid image file.']);
        }

        if ($width < self::MIN_WIDTH || ($height > 0 && $width / $height < self::MIN_RATIO)) {
            return back()->withErrors([
                'hero_image_file' => sprintf(
                    'Image must be a wide banner. Uploaded dimensions: %dx%d. Minimum width %dpx and aspect ratio at least %s:1.',
                    $width, $height, self::MIN_WIDTH, '3.5',
                ),
            ]);
        }

        $stem = self::safeStem(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        if ($stem === '') {
            $stem = 'banner';
        }

        $directory = self::imagesDirectory();
        $base = self::CATEGORY_PREFIXES[$category].'-'.$stem;
        $target = $base.'.'.$extension;
        for ($counter = 1; file_exists($directory.'/'.$target); $counter++) {
            $target = $base.'-'.$counter.'.'.$extension;
        }

        try {
            $file->move($directory, $target);
        } catch (\Throwable) {
            // The image passed every check and still could not be written: the
            // folder is not writable by the web server. Say that, rather than
            // letting the failure surface as a 500.
            return back()->withErrors([
                'hero_image_file' => 'The image could not be saved: the web server is not allowed to write to the images folder. If you manage the server, make it writable (see the README); otherwise ask your host.',
            ]);
        }

        return redirect('/admin/hero-images?msg=uploaded');
    }

    public function delete(Request $request): RedirectResponse
    {
        $name = basename((string) $request->input('hero_image_delete', ''));
        $known = array_merge(...array_values(self::discover()));

        if ($name === '' || ! in_array($name, $known, true)) {
            return back()->withErrors(['hero_image_delete' => 'Please choose a valid image to delete.']);
        }

        $path = self::imagesDirectory().'/'.$name;
        if (! file_exists($path)) {
            return back()->withErrors(['hero_image_delete' => 'The selected image file could not be found.']);
        }

        if (! @unlink($path)) {
            return back()->withErrors(['hero_image_delete' => 'Unable to delete the selected image. Check folder permissions.']);
        }

        // Drop the deleted image from the stored prefs map.
        $prefs = $this->rawPrefs();
        unset($prefs[$name]);
        DB::table('preferences')->where('id', 1)->update([
            'prefsHeroImages' => json_encode($prefs, JSON_THROW_ON_ERROR),
        ]);

        return redirect('/admin/hero-images?msg=deleted');
    }

    /** @return array<0|1|2|3|string, list<string>> category id → sorted filenames */
    private static function discover(): array
    {
        $images = ['0' => [], '1' => [], '2' => [], '3' => []];

        // GLOB_BRACE is undefined on non-glibc builds — glob each extension.
        $paths = [];
        foreach (self::ALLOWED_EXTENSIONS as $extension) {
            foreach (glob(self::imagesDirectory().'/*.'.$extension) ?: [] as $path) {
                $paths[] = $path;
            }
        }

        foreach ($paths as $path) {
            $file = basename((string) $path);
            if (! self::isCandidate($file, $path)) {
                continue;
            }

            $images[self::categoryOf($file)][] = $file;
        }

        foreach ($images as &$categoryImages) {
            sort($categoryImages);
        }

        return $images;
    }

    private static function isCandidate(string $filename, string $path): bool
    {
        if (preg_match('/_3000x500\.(?:jpg|jpeg|png|gif|webp)$/i', $filename)
            || preg_match('/^(?:misc|beer|cider|mead)-/i', $filename)
            || preg_match('/^[0-3]-/', $filename)) {
            return true;
        }

        // No naming convention? Accept wide banner-shaped images.
        [$width, $height] = self::dimensions($path);

        return $width >= self::MIN_WIDTH && $height > 0 && $width / $height >= self::MIN_RATIO;
    }

    private static function categoryOf(string $filename): string
    {
        $filename = strtolower($filename);

        if (preg_match('/^([0-3])-/', $filename, $m)) {
            return $m[1];
        }
        foreach (self::CATEGORY_PREFIXES as $id => $prefix) {
            if (str_starts_with($filename, $prefix.'-')) {
                return (string) $id; // numeric-string array keys arrive as ints
            }
        }

        return '0'; // unknown patterns are miscellaneous
    }

    /**
     * Stored choices overlaid on the current image set; missing images
     * (newly uploaded) default to enabled.
     *
     * @param  array<0|1|2|3|string, list<string>>  $allImages
     * @return array<string, bool>
     */
    private function loadPrefs(array $allImages): array
    {
        $stored = $this->rawPrefs();
        $prefs = [];

        foreach ($allImages as $images) {
            foreach ($images as $image) {
                $prefs[$image] = isset($stored[$image]) ? (bool) $stored[$image] : true;
            }
        }

        return $prefs;
    }

    /** @return array<string, mixed> decoded prefsHeroImages, [] when absent/invalid */
    private function rawPrefs(): array
    {
        $row = DB::table('preferences')->where('id', 1)->first();
        $raw = $row->prefsHeroImages ?? null;
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0: int, 1: int} */
    private static function dimensions(string $path): array
    {
        $info = @getimagesize($path);

        return $info === false ? [0, 0] : [(int) $info[0], (int) $info[1]];
    }

    private static function safeStem(string $input): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($input)), '-');
    }

    private static function imagesDirectory(): string
    {
        return public_path('images');
    }
}
