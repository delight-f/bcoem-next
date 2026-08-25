# Spec §8.4 (second half) — Upload validation review

Scope: every upload/write surface in bcoem-next (user_images/docs equivalents, scoresheet
PDFs, hero images, contest logo/sponsor images), compared with the legacy oracle
(brewcompetitiononlineentry modernization branch). Review method: sink search over `app/`
(storeAs/store/put/move/move_uploaded_file/copy/fwrite/file_put_contents/$request->file),
route audit, legacy comparison, live probes against `php -S` serving `public/`.

## Sink census

Grep of `app/` for filesystem-write sinks finds exactly one upload handler and two
disk-touching readers:

| Surface | File | Action |
|---|---|---|
| Hero/banner image upload | `app/Http/Controllers/Admin/HeroImagesController.php:83-142` | `$file->move()` to `public/images` |
| Scoresheet PDF serve | `app/Http/Controllers/Output/ScoresheetsController.php` | reads `public/user_docs/<name>` |
| Archive shuffle of user_docs | `app/Http/Controllers/Archive/ArchiveController.php:290-321` | rename/rmdir under `public/user_docs` |

Everything else matching the sink patterns is non-upload output (e.g.
`ExportController.php:97` `fopen('php://output')` CSV stream). There is **no** brewer
avatar/profile-image upload in either codebase (legacy `$_FILES` census: only
`handle.php`, `admin/hero_images.admin.php`, `includes/process/process_beerxml.inc.php`,
and BeerXML is parse-only).

## Per-surface verdicts

### 1. Hero/banner image upload — PASS

`HeroImagesController::upload()`:

- Auth: admin gate at `:85-87` before anything else.
- Category whitelist `:89-92`; extension allowlist jpg/jpeg/png/gif/webp `:100-105`
  (**drops legacy's `image/svg+xml`** — legacy `handle.php:32-34` allowed SVG, a
  stored-XSS vector when served same-origin; the port is strictly better here).
- MIME checked from file content, not client header (`$file->getMimeType()` at `:107`,
  Symfony content-guesses from the temp file), plus `getimagesize()` decode check
  (`dimensions()`, `:258-262`) and banner geometry gates `:118-127`.
- Size cap: Laravel `max:` rule = 5 MB (`:94-96`).
- Filename: never trusts the client name for the path — stem is rebuilt via
  `safeStem()` `[a-z0-9-]+` sanitization (`:129-135`, helper `:264-266`), extension comes
  from the allowlist, collisions get `-<n>` counters (`:137-139`). No user-controlled
  byte reaches `move()` as a path component.
- Destination `public/images` is intentionally public (banners), matching legacy
  `IMAGES`. Delete endpoint clamps with `basename()` **and** requires the name be in the
  discovered set (`:150-153`) — covered by the traversal test
  `tests/Feature/AdminScreensCrudTest.php:279-281` and bad-extension test `:284-295`.

Verdict: PASS. Tighter than legacy `hero_images.admin.php` (which allowed SVG and
HTML-escaped rather than sanitized the original name).

### 2. Scoresheet serve endpoint (`/admin/output/scoresheets`) — PASS on its own terms

`ScoresheetsController::__invoke()`:

- Auth: `isAdmin()` gate `:33-35` (route also sits behind `web`+`auth` middleware,
  `routes/outputs.php`).
- Traversal: `basename()` clamp `:38-42` reduces any `?file=` value to a bare filename;
  `../../.env` cannot escape `public/user_docs`.
- Live probe (unauthenticated, `php -S 127.0.0.1:8099 -t public`):
  - `GET /admin/output/scoresheets?file=../../../.env` → **302** (login redirect; auth
    fires before any file resolution)
  - `GET /admin/output/scoresheets?file=P52b-951001.pdf` → **302** (same)

So the controller itself leaks nothing without an admin session and permits no
traversal. The problem is what it shares a directory with — see FAIL below.

Divergence note (functional, not security): basename-clamping means archived scoresheets
under `user_docs/<suffix>/` are unreachable through this route (legacy served them);
the header-comment at `:24-26` documents this.

### 3. Entrant scoresheet PDFs under `user_docs` — **FAIL**

**Location:** storage location decision embodied at
`app/Http/Controllers/Output/ScoresheetsController.php:44`
(`public_path('user_docs/'.$name)`), `app/Http/Controllers/Archive/ArchiveController.php:291`,
and `app/Support/Entries/JudgingNumber.php:36-37` — combined with the missing web-server
deny in `public/.htaccess` (whole file reviewed; no user_docs rule).

**Attack:** scoresheets are stored **inside the HTTP document root**, and Laravel's
`public/.htaccess` serves any existing file directly (`RewriteCond %{REQUEST_FILENAME}
!-f` short-circuits the front-controller rewrite). Legacy explicitly defended against
exactly this with `RewriteRule ^.*user_docs/.*\.pdf$ - [F,NC,L]`
(legacy `.htaccess`, after the SEF rules) plus per-request forced download through
`handle.php` ("Discourages random viewing of scoresheets by inputting direct URL",
`handle.php:9-11`). The port dropped both halves: files live in the webroot and nothing
blocks direct fetches.

Filenames are bare judging numbers (`%06d.pdf`; see `JudgingNumber.php:37` and legacy
`includes/data_cleanup.inc.php:99-101`), so once any top-level PDF exists the namespace
is enumerable (000001.pdf … 999999.pdf).

**Proof (live, unauthenticated):**

```
$ curl http://127.0.0.1:8099/user_docs/p56s/.../P56A/P52b-951001.pdf
HTTP 200  text→ application/pdf  37 bytes  magic: 25 50 44 46 2d 31 2e 34  (%PDF-1.4)
```

An anonymous visitor downloads an entrant's scoresheet PDF. Scoresheets carry entrant
names/contact details and judge identities → unauthenticated PII disclosure.

**Fix direction (either suffices):** move USER_DOCS-equivalent storage out of the
webroot (`storage_path('user_docs')`) and update the three touch points above, or keep
the layout and replicate legacy's control in `public/.htaccess`
(`RewriteRule ^.*user_docs/.*\.pdf$ - [F,NC,L]`, plus nginx equivalent
`location ~* ^/user_docs/.+\.pdf { deny all; }`) — noting .htaccess only protects Apache
with AllowOverride, so the storage move is the robust fix.

### 4. Contest logo / sponsor images — PASS (surface reduced, gap noted)

- Sponsors: `SponsorsController` only *lists* `public/user_images` for a dropdown
  (`:157`); there is no sponsor-image upload in the port. Legacy uploaded sponsor/contest
  logos through `handle.php` into `USER_IMAGES` — web-public by design, same as port's
  `user_images`. Nothing to exploit; no write path exists.
- Contest logo: port stores `contestLogo` as a plain string (`string|max:255`,
  `CompetitionInfoController.php:97`) and renders it escaped through Blade + `asset()`
  (`resources/views/components/public-layout.blade.php:18`) — no XSS, no traversal (a
  bogus value just yields a broken `<img>`/`og:image`).
- **Functional gap vs legacy:** the "Upload Logo Image" flow (`admin/
  competition_info.admin.php:423-426` → `handle.php`) and the batch scoresheet uploader
  (`upload_scoresheets.admin.php`) are unported — there is currently **no way to get a
  logo into `user_images` or a scoresheet PDF into `user_docs` through the app**. That
  makes finding #3 latent today but immediately exploitable the moment any upload path
  (or manual file drop, as already present in this working tree) lands.

## Legacy-vs-port comparison table

| Control | Legacy | Port |
|---|---|---|
| Logo upload (→ user_images) | `handle.php`: admin-only (userLevel==0), mime+ext allowlist (incl. **SVG**), blacklist php/exe, 20 MB cap, `clean_filename()` | Not ported; logo is a validated text field, rendered escaped |
| Scoresheet upload (→ user_docs) | Same handler; PDF-only allowlist, server-side `mime_content_type`, 20 MB cap | Not ported (no writer exists) |
| Direct-web access to user_docs PDFs | Blocked: `.htaccess` `RewriteRule ^.*user_docs/.*\.pdf$ - [F,NC,L]` | **Nothing blocks it — FAIL #3, proven live** |
| Brew-facing single-PDF download | `handle.php` section=pdf-download: `[a-zA-Z0-9._-]+` + no `..` + `realpath` prefix clamp, login required | Admin-only route, `basename()` clamp, `auth` middleware — equivalent or stricter (but brewer download itself unported) |
| Hero/banner upload | `hero_images.admin.php`: ext+mime (via `getimagesize`) allowlist incl. **SVG**, 2 MB, htmlspecialchars on name | `HeroImagesController`: strict allowlist w/o SVG, content-guessed MIME, getimagesize, 5 MB, fully server-built filename — PASS |
| Deletion endpoints | `unlink($upload_dir.basename($filter))` — basename-clamped only | Hero delete additionally whitelists against discovered filenames; archive rmtree is suffix-driven (no user input in path) |

## Observations (non-security, flagged for owning lanes)

- `public/user_docs/` in this tree contains a ~80-level nested chain
  `p56s/p56d/p56k/p56a/…/P56A/P52b-951001.pdf`: each archive run nests the previous
  suffix directories into the new suffix folder because `archiveUserDocs()`
  (ArchiveController.php:305-311) moves *all* top-level entries including earlier
  suffix dirs. Matches legacy `rmove` semantics, but produces unbounded directory depth
  across repeated archive cycles.
- `ScoresheetsController` slurps whole PDFs via `file_get_contents` — fine at
  competition scale; stream if multi-MB PDFs ever become common.

## Summary

| Surface | Verdict |
|---|---|
| Hero image upload | PASS |
| Scoresheet serve route (auth+traversal) | PASS |
| Scoresheet storage/serving (webroot exposure) | **FAIL** — unauth PII download, proven |
| Contest logo / sponsors | PASS (upload flow unported = gap) |
| Brewer avatar/images | N/A in both codebases |

Reviewed paths: all of `app/` via sink greps; routes/*; public/.htaccess; resources/views
logo rendering; legacy handle.php, hero_images.admin.php, upload.admin.php,
upload_scoresheets.admin.php, process_delete.inc.php, paths.php, .htaccess,
scoresheets.output.php headers; tests/Feature/AdminScreensCrudTest.php. Live probes ran
against `php -S 127.0.0.1:8099 -t public` from the repo root.
