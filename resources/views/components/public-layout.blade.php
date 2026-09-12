<!DOCTYPE html>
@php
    // Legacy renders the admin chrome whenever the dispatch ran through
    // section=admin with userLevel<=1 (index.legacy.php:87) — including
    // pages whose port URL is public-shaped: register (quick-register
    // links), brew add/edit (admin entry add), list/edit-account and
    // user/password (admin editing another user). Chrome therefore keys
    // on the viewer's level for those paths, not the path alone.
    $isAdminSide = request()->is('admin') || request()->is('admin/*') || request()->is('backoffice*') || request()->is('eval*')
        || (auth()->check() && (int) auth()->user()->userLevel <= 1
            && (request()->is('register') || request()->is('register/*')
                || request()->is('brew') || request()->is('brew/*')
                || request()->is('list/edit-account') || request()->is('user/password') || request()->is('user/username')));
    // PARITY-028: DB-stored equivalent of the legacy
    // custom_competition_info.pub.php drop-in (index.pub.php:405 +
    // pub/nav.pub.php:109 "Other Info" nav link). NULL/empty = absent,
    // mirroring legacy's file_exists() gate.
    $contestInfoExtra = trim((string) ($ctx->contestStr('contestInfoExtra') ?? ''));
    // PARITY-027: public mods render, file-based like legacy.
    // index.pub.php:357+618 include mods_top/bottom.inc.php, which
    // include-render the FILE mods/<mod_filename> when it exists
    // (mod_display() in includes/db/mods.db.php + the realpath guard in
    // mods_top.inc.php) — mod_description is never rendered on public
    // pages, and a missing file renders nothing (the admin dashboard
    // carries the missing-file alert instead). A row whose file throws is
    // skipped so one broken mod cannot take the page down.
    // Gates (mods.db.php:54-108 + mods_top/bottom.inc.php): prefsUseMods=Y,
    // mod_enable=1, mod_type=0 (Static HTML informational), display_section
    // map (default/rules/volunteers/sponsors/contact/pay ->1, register->6,
    // list->8), mod_extend_function equal to the section or 0,
    // mod_permission >= userLevel (0 uber / 1 admin / 2 all; anon is 2 per
    // mods_top.inc.php:5), mod_display_rank == page_location (1=before
    // core, 2=after core).
    $modsTop = [];
    $modsBottom = [];
    if ($ctx->prefsStr('prefsUseMods') === 'Y' && ! $isAdminSide) {
        $modsUserLevel = auth()->check() ? (int) auth()->user()->userLevel : 2;
        $modsSection = 1;
        if (request()->is('register') || request()->is('register/*')) {
            $modsSection = 6;
        } elseif (request()->is('list') || request()->is('list/*')) {
            $modsSection = 8;
        }
        $modsRealDir = realpath(base_path('mods'));
        foreach (DB::table('mods')->orderBy('mod_rank')->get() as $mod) {
            if ((int) $mod->mod_enable !== 1 || (int) $mod->mod_type !== 0) {
                continue;
            }
            $extend = (int) $mod->mod_extend_function;
            if ($extend !== $modsSection && $extend !== 0) {
                continue;
            }
            if ((int) $mod->mod_permission < $modsUserLevel) {
                continue;
            }
            // Legacy realpath guard (mods_top.inc.php): the file must sit
            // directly inside mods/ — no traversal.
            $modRealPath = realpath(base_path('mods/'.$mod->mod_filename));
            if ($modRealPath === false || $modsRealDir === false
                || ! str_starts_with($modRealPath, $modsRealDir.DIRECTORY_SEPARATOR)) {
                continue;
            }
            ob_start();
            try {
                $base_url = url('/').'/';
                include $modRealPath;
                $content = (string) ob_get_clean();
            } catch (\Throwable) {
                ob_end_clean();
                continue;
            }
            if (trim($content) === '') {
                continue;
            }
            if ((int) $mod->mod_display_rank === 1) {
                $modsTop[] = $content;
            } elseif ((int) $mod->mod_display_rank === 2) {
                $modsBottom[] = $content;
            }
        }
    }
    // Legacy headers.inc.php:443-475 sets $label_admin = "Administration" then
    // appends ": {nav label}" per go. Port admin routes map to that label here
    // (a static map is fine per spec). The dashboard keeps its own chrome.
    $adminRouteName = request()->route()?->getName();
    $adminTitleMap = [
        'admin.dashboard' => 'Dashboard',
        'admin.upload_scoresheets' => 'Upload Scoresheets and Other Documents',
        'admin.competition_info.edit' => 'Competition Info',
        'admin.site_preferences.edit' => 'Website Preferences',
        'admin.dates.edit' => 'Competition-Related Dates',
        'admin.hero_images.index' => 'Upload Images',
        'admin.upload.index' => 'Upload Images',
        'admin.sponsors.index' => 'Sponsors',
        'admin.contacts.index' => 'Contacts',
        'admin.mods.index' => 'Custom Modules',
        'admin.style_types.index' => 'Style Types',
        'admin.styles.index' => 'Styles',
        'admin.make_admin.edit' => 'Change User Level',
        'admin.change_user_password.edit' => 'Change User Password',
        'admin.send_test_email.show' => 'Send Test Email',
    ];
    $adminPageTitle = null;
    // Admin off-canvas nav (legacy sections/nav.sec.php) conditions: full-menu
    // sections need userLevel==0, reporting rows need userAdminObfuscate==0,
    // BOS/results rows need judging started, eval row needs prefsEval==1.
    $adminNavLevel0 = auth()->check() && (int) auth()->user()->userLevel === 0;
    $adminNavObfuscate = auth()->check() ? (int) (auth()->user()->userAdminObfuscate ?? 0) : 0;
    $adminNavJudgingStarted = false;
    if ($isAdminSide && auth()->check()) {
        $adminNavWindows = \App\Support\Tenant\Windows::derive($ctx, time());
        $adminNavJudgingStarted = $adminNavWindows->firstJudgingDate !== null && time() > $adminNavWindows->firstJudgingDate;
    }
    // nav.sec.php:223-231 — Scoring rows need userLevel==0 OR userAdminObfuscate==0;
    // the eval row additionally needs prefsEval==1.
    $adminNavScoring = $adminNavLevel0 || $adminNavObfuscate === 0;
    $adminNavEval = (int) $ctx->prefsStr('prefsEval') === 1;
    // Tables Planning Mode withholds pull sheets (see PullsheetsController and
    // the judging-tables page lead text), so the nav must not offer them.
    $adminNavPlanning = (string) $ctx->judgingStr('jPrefsTablePlanning') === '1';
    // nav.sec.php:201-207 — barcode/QR checkin rows need userAdminObfuscate==0 AND
    // prefsEntryForm in barcode_qrcode_array (constants.inc.php:560 = every form id).
    $adminNavBarcode = $adminNavObfuscate === 0
        && in_array((string) $ctx->prefsStr('prefsEntryForm'), ['0', '2', 'N', 'C', '3', '4', '5', '6', '1'], true);
    // Payments plan W3: "Payments" nav presence keys off the tenant's Stripe
    // connection, not the retired prefsPaypalIPN.
    $stripeConnected = str_contains((string) $ctx->prefsStr('prefsStripe'), 'account_id');
    // Theme (prefsTheme): the port ships exactly two palettes — the default
    // public palette and the brux palette. Only those are offered in Site
    // Preferences; admin chrome is always brux (the BS5 frame gate keys on
    // data-bs-theme="bcoem-brux"), so the public side is the only place the
    // preference can differ. Anything unsupported falls back to default.
    $htmlTheme = ($isAdminSide || $ctx->prefsStr('prefsTheme') === 'bcoem-brux') ? 'bcoem-brux' : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($htmlTheme !== null) data-bs-theme="{{ $htmlTheme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $ctx->contestStr('contestName') }} - Brew Competition Online Entry &amp; Management</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    @if ($isAdminSide)
        {{-- Modern admin date/time picker: flatpickr (no jQuery/moment), from the
             same jsdelivr CDN used for fontawesome. moment + the eonasdan picker +
             its vendored glyphicons are gone (glyphicons had no other consumer). --}}
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
        <style>
            /* Flatpickr beside daisyUI: match the admin theme primary accent
               (#1565C0) and a 4px radius. A modern widget, not a legacy clone. */
            .flatpickr-calendar { border-radius: 4px; }
            .flatpickr-day.selected,
            .flatpickr-day.selected:hover,
            .flatpickr-day.startRange,
            .flatpickr-day.endRange {
                background: #1565C0;
                border-color: #1565C0;
                color: #fff;
            }
            .flatpickr-day.today { border-color: #1565C0; }
            .flatpickr-day.today:hover,
            .flatpickr-day.today:focus {
                background: #1565C0;
                border-color: #1565C0;
                color: #fff;
            }
            .flatpickr-months .flatpickr-prev-month:hover,
            .flatpickr-months .flatpickr-next-month:hover { color: #1565C0; }
            .flatpickr-months .flatpickr-prev-month:hover svg,
            .flatpickr-months .flatpickr-next-month:hover svg { fill: #1565C0; }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @if ($ctx->contestStr('contestName'))
        <meta property="og:title" content="{{ $ctx->contestStr('contestName') }}">
    @endif
    @if ($ctx->contestStr('contestLogo'))
        <meta property="og:image" content="{{ asset('user_images/'.$ctx->contestStr('contestLogo')) }}">
    @endif
</head>
<body>

<a id="top" name="top"></a>

@if ($isAdminSide)
        {{-- Issue-5: the admin top bar is a Bootstrap 5 dark navbar. .admin-topbar
         (custom class, unlayered CSS in app.css) preserves the brux gradient
         look; BS5's data-api drives the user dropdown. The off-canvas navmenu
         below is still BS3 until issue 6. --}}
    <nav class="navbar navbar-expand navbar-dark admin-topbar fixed-top d-print-none" style="z-index: 1000;">
        <div class="container-fluid">
            <div class="admin-nav-body">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link hide-loader" href="{{ url('/') }}">Home</a></li>
                </ul>
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link hide-loader d-none d-xl-block" href="#" onclick="window.print()" role="button"><span class="fa fa-print"></span></a></li>
                    @auth
                        <li class="nav-item dropdown">
                            <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" role="button" aria-expanded="false"><span class="fa fa-user"></span></a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li class="dropdown-header"><strong>{{ auth()->user()->user_name }}</strong></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="{{ url('/list') }}" tabindex="-1">{{ __('site.my_account') }}</a></li>
                                <li><a class="dropdown-item" href="{{ url('/list/edit-account') }}" tabindex="-1">{{ __('site.edit_account') }}</a></li>
                                <li><a class="dropdown-item" href="{{ url('/user/username?id='.auth()->id()) }}" tabindex="-1">{{ __('site.change_email') }}</a></li>
                                <li><a class="dropdown-item" href="{{ url('/user/password') }}" tabindex="-1">{{ __('site.change_password') }}</a></li>
                                <li><a class="dropdown-item" href="{{ url('/pay') }}" tabindex="-1">{{ __('site.pay') }}</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item" tabindex="-1">{{ __('site.log_out') }}</button>
                                    </form>
                                </li>
                            </ul>
                        </li>
                        @if (auth()->user()->isAdmin())
                            <li class="nav-item"><a class="nav-link" href="#" id="admin-offcanvas-open" data-bs-toggle="offcanvas" data-bs-target="#admin-offcanvas" role="button"><i class="fa fa-chevron-circle-left"></i> {{ __('site.admin_short') }}</a></li>
                        @endif
                    @endauth
                </ul>
            </div>
        </div>
    </nav>

    <div class="offcanvas offcanvas-end admin-nav-offcanvas" tabindex="-1" id="admin-offcanvas" aria-labelledby="admin-offcanvas-label">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="admin-offcanvas-label">Admin Essentials Menu</h5>
            <button type="button" id="admin-offcanvas-close" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close Admin Essentials menu"></button>
        </div>
        <div class="offcanvas-body">
            <p class="bcoem-admin-menu-disabled small">This menu contains only essential functions. Select <strong>Admin Dashboard</strong> for all options.</p>
            <ul class="nav flex-column admin-oc-nav">
                <li class="nav-item"><a class="nav-link" href="{{ url('/admin') }}">Admin Dashboard</a></li>
                <li class="nav-item">
                    <a class="nav-link oc-group-toggle" href="#" data-bs-toggle="collapse" data-bs-target="#oc-g1" aria-expanded="false" role="button">Competition Preparation</a>
                    <div class="collapse" id="oc-g1">
                        <ul class="nav flex-column admin-oc-subnav">
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/dates') }}">Edit All Competition Dates</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/competition-info') }}">Edit Competition Info</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/contacts') }}">Manage Contacts</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/special-best') }}">Manage Custom Categories</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/dropoff') }}">Manage Drop-Off Locations</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/locations') }}">Manage Judging Sessions</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/non-judging') }}">Manage Non-Judging Sessions</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/sponsors') }}">Manage Sponsors</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/styles') }}">Manage Styles Accepted</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/style-types') }}">Manage Style Types</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/upload') }}">Upload Logo Images</a></li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link oc-group-toggle" href="#" data-bs-toggle="collapse" data-bs-target="#oc-g2" aria-expanded="false" role="button">Entries{{ $stripeConnected ? ', Payments,' : '' }} and Participants</a>
                    <div class="collapse" id="oc-g2">
                        <ul class="nav flex-column admin-oc-subnav">
                            <li class="nav-item"><a class="nav-link" href="{{ url('/backoffice/entries') }}">Manage Entries</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/backoffice/count-by-style') }}">Entry Count By Style</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/backoffice/count-by-substyle') }}">Entry Count By Sub-Style</a></li>
                            @if ($stripeConnected)
                                <li class="nav-item"><a class="nav-link" href="{{ url('/admin/payments') }}">Manage Payments</a></li>
                            @endif
                            <li class="nav-item"><a class="nav-link" href="{{ url('/backoffice/participants') }}">Manage Participants</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/pool-assign?filter=judges') }}">Assign Judges</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/pool-assign?filter=stewards') }}">Assign Stewards</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/register/judge') }}?view=quick">Quick Register a Judge</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/register/steward') }}?view=quick">Quick Register Steward</a></li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link oc-group-toggle" href="#" data-bs-toggle="collapse" data-bs-target="#oc-g3" aria-expanded="false" role="button">Sorting</a>
                    <div class="collapse" id="oc-g3">
                        <ul class="nav flex-column admin-oc-subnav">
                            <li class="nav-item"><a class="nav-link" href="{{ url('/backoffice/entries') }}">Manually</a></li>
                            @if ($adminNavBarcode)
                                <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/checkin') }}">Entry Check-in Via Barcode Scanner</a></li>
                                <li class="nav-item"><a class="hide-loader nav-link" href="{{ url('/qr') }}" target="_blank" rel="noopener">Entry Check-in Via Mobile Devices <span class="fa fa-external-link"></span></a></li>
                            @endif
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link oc-group-toggle" href="#" data-bs-toggle="collapse" data-bs-target="#oc-g4" aria-expanded="false" role="button">Organizing</a>
                    <div class="collapse" id="oc-g4">
                        <ul class="nav flex-column admin-oc-subnav">
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/tables') }}">Manage Tables</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/tables') }}">Assign Judges/Stewards to Tables</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/pool-assign?filter=bos') }}">Add BOS Judges</a></li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link oc-group-toggle" href="#" data-bs-toggle="collapse" data-bs-target="#oc-g5" aria-expanded="false" role="button">Scoring</a>
                    <div class="collapse" id="oc-g5">
                        <ul class="nav flex-column admin-oc-subnav">
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/upload-scoresheets') }}">Upload Scoresheets</a></li>
                            @if ($adminNavScoring)
                                @if ($adminNavEval)
                                    <li class="nav-item"><a class="nav-link" href="{{ url('/eval') }}">Manage Entry Evaluations</a></li>
                                @endif
                                <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/scores') }}">Manage Scores</a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/bos') }}">Manage BOS Entries and Places</a></li>
                            @endif
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link oc-group-toggle" href="#" data-bs-toggle="collapse" data-bs-target="#oc-g6" aria-expanded="false" role="button">Reports</a>
                    <div class="collapse" id="oc-g6">
                        <ul class="nav flex-column admin-oc-subnav">
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/output/table_cards') }}?id=default">Table Cards</a></li>
                            @if ($adminNavObfuscate === 0 && ! $adminNavPlanning)
                                <li class="nav-item"><a class="nav-link" href="{{ url('/admin/output/pullsheets') }}?go=judging_tables&id=default&view=entry">Pullsheets - Entry Numbers</a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ url('/admin/output/pullsheets') }}?go=judging_tables&id=default">Pullsheets - Judging Numbers</a></li>
                                @if ($adminNavJudgingStarted)
                                    <li class="nav-item"><a class="nav-link" href="{{ url('/admin/output/pullsheets') }}?go=judging_scores_bos">BOS Pullsheets</a></li>
                                    <li class="nav-item"><a class="nav-link" href="{{ url('/admin/output/bos_mat') }}">BOS Cup Mats - Judging Numbers</a></li>
                                    <li class="nav-item"><a class="nav-link" href="{{ url('/admin/output/bos_mat') }}?filter=entry">BOS Cup Mats - Entry Numbers</a></li>
                                @endif
                            @endif
                            @if ($adminNavJudgingStarted)
                                <li class="nav-item"><a class="nav-link" href="{{ url('/admin/output/results') }}?action=print&filter=scores&go=judging_scores&view=winners">Winners with Scores</a></li>
                                <li class="nav-item"><a class="nav-link" href="{{ url('/admin/output/results') }}?action=print&filter=none&go=judging_scores&view=winners">Winners without Scores</a></li>
                            @endif
                        </ul>
                    </div>
                </li>
                @if ($adminNavLevel0)
                <li class="nav-item">
                    <a class="nav-link oc-group-toggle" href="#" data-bs-toggle="collapse" data-bs-target="#oc-g7" aria-expanded="false" role="button">Data Management</a>
                    <div class="collapse" id="oc-g7">
                        <ul class="nav flex-column admin-oc-subnav">
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/archive') }}">Manage Archives</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/archive') }}?action=add">Archive Current Data</a></li>
                        </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a class="nav-link oc-group-toggle" href="#" data-bs-toggle="collapse" data-bs-target="#oc-g8" aria-expanded="false" role="button">Preferences</a>
                    <div class="collapse" id="oc-g8">
                        <ul class="nav flex-column admin-oc-subnav">
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/site-preferences') }}">General</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/site-preferences/entries') }}">Entry</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/site-preferences/email') }}">Email Sending</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/site-preferences/payment') }}">Currency and Payment</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/site-preferences/best') }}">Best Brewer and/or Club</a></li>
                            <li class="nav-item"><a class="nav-link" href="{{ url('/admin/judging/preferences') }}">Judging/Competition Organization</a></li>
                        </ul>
                    </div>
                </li>
                @endif
                <li class="nav-item"><a class="hide-loader nav-link" href="https://github.com/geoffhumphrey/brewcompetitiononlineentry/issues" target="_blank">Report an Issue</a></li>
            </ul>
        </div>
    </div>
@else
    <header id="home" class="site-header">
        <nav id="site-nav" class="site-nav family-sans navbar navbar-expand-md navbar-dark fixed-top text-white d-print-none" style="z-index: 1000;">
            <div class="container-fluid">
                <a class="btn btn-link nav-icon-btn" href="{{ url()->current() === url('/') ? '#home' : url('/') }}"><i class="fas fa-home me-2"></i></a>
                <button type="button" class="btn btn-link nav-icon-btn d-md-none" data-bs-toggle="collapse" data-bs-target="#nav-menu" aria-controls="nav-menu" aria-expanded="false" aria-label="Toggle Navigation"><i class="fas fa-bars"></i></button>
                <section id="nav-menu" class="collapse navbar-collapse justify-content-end">
                    @php
                        // The root route is named home.legacy (routes/web.php):
                        // "/" doubles as the legacy URL entry point. Matching the
                        // real name is what makes the in-page anchors relative (#…)
                        // on the landing page; every non-landing page keeps
                        // absolute url('/').'#…' links so a click navigates home
                        // and then scrolls.
                        $onLanding = request()->routeIs('home.*');
                    @endphp
                    {{-- Each link's gate mirrors the include gate of the section it
                         points at (home.blade.php): Rules/Entry Info follow
                         $windows->futureJudgingSessions > 0, Volunteers follows
                         ! $judgingStarted. --}}
                    @if (($futureJudgingSessions ?? 0) > 0)
                        <a class="nav-item nav-link" href="{{ $onLanding ? '#rules' : url('/').'#rules' }}">{{ __('site.rules') }}</a>
                    @endif
                    @if (! ($judgingStarted ?? false))
                        <a class="nav-item nav-link" href="{{ $onLanding ? '#volunteers' : url('/').'#volunteers' }}">{{ __('site.volunteers') }}</a>
                    @endif
                    @if (($futureJudgingSessions ?? 0) > 0)
                        <a class="nav-item nav-link" href="{{ $onLanding ? '#entry-info' : url('/').'#entry-info' }}">{{ __('site.entry_info') }}</a>
                    @endif
                    @if ($sponsorsVisible ?? false)
                        <a class="nav-item nav-link" href="{{ $onLanding ? '#sponsors' : url('/sponsors') }}">{{ __('site.sponsors') }}</a>
                    @endif
                    @if ($contestInfoExtra !== '')
                        {{-- pub/nav.pub.php:109 — "Other Info" link when the
                             custom competition-info block is present. --}}
                        <a class="nav-item nav-link" href="{{ $onLanding ? '#custom-competition-info' : url('/').'#custom-competition-info' }}">{{ __('site.other_info') }}</a>
                    @endif
                    <a class="nav-item nav-link" href="{{ $onLanding ? '#contact' : url('/').'#contact' }}">{{ __('site.contact') }}</a>

                    {{-- pub/nav.pub.php:130-152 — runtime language toggle:
                         globe dropdown, gated on prefsLanguageToggle=Y +
                         >1 option. ?lang= sets a 30-day userLanguage cookie. --}}
                    @php
                        $langToggle = $ctx->prefsStr('prefsLanguageToggle');
                        $langOptions = json_decode((string) ($ctx->prefsStr('prefsLanguageOptions') ?? ''), true);
                        if (! is_array($langOptions)) {
                            $langOptions = \App\Support\Tenant\Language::availableCodes();
                        }
                        $langNames = [
                            'en-US' => 'English (US)',
                            'en-GB' => 'English (UK)',
                            'cs-CZ' => 'Čeština',
                            'es-419' => 'Español',
                            'fr-FR' => 'Français',
                            'hu-HU' => 'Magyar',
                            'pt-BR' => 'Português (BR)',
                        ];
                        $langCurrent = app()->getLocale() === 'en'
                            ? ((string) ($ctx->prefsStr('prefsLanguage') ?: 'en-US'))
                            : strtoupper(substr(app()->getLocale(), 0, 2)).'-'.ucfirst(app()->getLocale());
                        $langMenu = array_filter($langOptions, fn ($c) => isset($langNames[$c]));
                    @endphp
                    @if ($langToggle === 'Y' && count($langMenu) > 1)
                        <div class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false" title="Language"><i class="fa fa-lg fa-fw fa-globe"></i></a>
                            <ul class="dropdown-menu dropdown-menu-end" data-bs-theme="dark">
                                @foreach ($langMenu as $langCode)
                                    @php
                                        $langActive = $langCode === $langCurrent;
                                        $langUrl = url()->current();
                                        $langSep = str_contains($langUrl, '?') ? '&' : '?';
                                        $langUrl .= $langSep.'lang='.$langCode;
                                    @endphp
                                    <li class="small">
                                        <a class="dropdown-item {{ $langActive ? 'active' : '' }}" href="{{ $langUrl }}">
                                            @if ($langActive)<i class="fa fa-check text-success me-1"></i>@endif{{ $langNames[$langCode] }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if(Auth::check())
                        @if (auth()->user()->isAdmin())
                            <a class="nav-item nav-link" href="{{ url('/admin') }}">{{ __('site.admin_short') }}</a>
                        @endif
                        {{-- pub/nav.pub.php: fa-user dropdown + flat logout icon --}}
                        @php
                            $navWindows = \App\Support\Tenant\Windows::derive($ctx, time());
                        @endphp
                        <div class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button" aria-expanded="false"><i class="fa fa-lg fa-fw fa-user"></i></a>
                            {{-- Dark theme like the language dropdown: .site-nav styles
                                 this menu dark (#212529 bg), so BS5 muted utility colors
                                 (text-body-secondary on the Auto-Log-Out row) must resolve
                                 to the dark theme or they render illegibly. --}}
                            <ul class="dropdown-menu dropdown-menu-end" data-bs-theme="dark">
                                <li class="small"><a class="dropdown-item {{ request()->routeIs('list') ? 'disabled' : '' }}" href="{{ url('/list') }}">{{ __('site.my_account') }}</a></li>
                                <li class="small"><a class="dropdown-item" href="{{ url('/list') }}#entries">{{ __('site.entries') }}</a></li>
                                @if ($navWindows->entry === \App\Support\Tenant\WindowState::Open
                                    && ! $navWindows->compEntryLimitReached
                                    && ! $navWindows->compPaidEntryLimitReached)
                                    <li class="small"><a class="dropdown-item {{ request()->routeIs('brew.*') ? 'disabled' : '' }}" href="{{ url('/brew') }}">{{ __('site.add_entry') }}</a></li>
                                @endif
                                @if (! $navWindows->compPaidEntryLimitReached)
                                    <li class="small"><a class="dropdown-item" href="{{ url('/pay') }}">{{ __('site.pay') }}</a></li>
                                @endif
                                <li class="small"><hr class="dropdown-divider"></li>
                                <li class="small"><a class="dropdown-item" href="{{ url('/list/edit-account') }}">{{ __('site.edit_account') }}</a></li>
                                <li class="small"><a class="dropdown-item" href="{{ url('/user/username?id='.auth()->id()) }}">{{ __('site.change_email') }}</a></li>
                                <li class="small"><a class="dropdown-item" href="{{ url('/user/password') }}">{{ __('site.change_password') }}</a></li>
                                @if ((int) $ctx->prefsStr('prefsEval') === 1
                                    && \Illuminate\Support\Facades\DB::table('staff')->where('uid', auth()->id())->value('staff_judge') == 1
                                    && \Illuminate\Support\Facades\DB::table('brewer')->where('uid', auth()->id())->value('brewerJudge') === 'Y'
                                    && time() > (int) ($ctx->judgingStr('jPrefsJudgingOpen') ?: 0))
                                    {{-- pub/nav.pub.php:180-183 — Judging Dashboard link, disabled on
                                         the eval section itself. --}}
                                    <li class="small"><a class="dropdown-item {{ request()->is('eval*') ? 'disabled' : '' }}" href="{{ url('/eval') }}">{{ __('site.judging_dashboard') }}</a></li>
                                @endif
                                <li class="small"><hr class="dropdown-divider"></li>
                                <li class="small" style="font-size: .75em;">
                                    {{-- pub/nav.pub.php:189 — "Auto Log Out in <span id=session-end>"
                                         countdown footer. app.js ticks #session-end from the
                                         effective session timeout (preferences.prefsSessionTimeout
                                         when set, else config('session.lifetime')) and auto-logs-out
                                         at zero (nav.pub.php session-end JS). --}}
                                    <span class="dropdown-item-text text-body-secondary">{{ __('site.auto_log_out') }} <span id="session-end" data-session-end-seconds="{{ $ctx->sessionTimeoutMinutes() * 60 }}" data-session-heartbeat-url="{{ route('ajax.heartbeat') }}"></span></span>
                                </li>
                            </ul>
                        </div>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="nav-item nav-link" aria-label="{{ __('site.log_out') }}"><i class="fa fa-lg fa-fw fa-sign-out-alt"></i></button>
                        </form>
                    @else
                        <a class="nav-item nav-link" href="#" data-bs-toggle="modal" data-bs-target="#login-modal">{{ __('site.log_in') }}</a>
                    @endif
                </section>
            </div>
        </nav>
        {{-- Legacy renders section alerts (login nudge, archived-data notice)
             between the nav and the hero (headers.inc.php via alerts.pub.php). --}}
        @if ((int) request('msg') === 99)
            <p class="alert alert-warning"><strong>{{ __('site.please_log_in') }}</strong></p>
        @elseif ((int) request('msg') === 8)
            <p class="alert alert-warning"><strong>{{ __('site.archived_not_available') }}</strong></p>
        @elseif ((int) request('msg') === 11)
            <p class="alert alert-warning"><span class="fa fa-lg fa-exclamation-circle"></span>
                <strong>{{ __('site.login_problem') }}</strong> {{ __('site.login_problem_detail') }}</p>
        @endif
        {{-- Upgrade-available banner (issue 27, Task 2.3): shared by
             EnsureInstalled for Top-Level Administrators only, when newer
             files are on disk. Per-session dismissal posts to
             wizard.upgrade.dismiss. --}}
        @if (! empty($upgradeBanner ?? null))
            <div class="alert alert-warning d-print-none mb-0" role="alert">
                <div class="container-fluid d-flex align-items-center gap-3">
                    <i class="fa fa-arrow-circle-up fa-lg" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <strong>Version {{ $upgradeBanner['version'] }} is available</strong>
                        (you are running {{ $upgradeBanner['current'] }}).
                        <a class="alert-link" href="{{ $upgradeBanner['url'] }}">Update your site</a>.
                    </div>
                    <form method="post" action="{{ $upgradeBanner['dismiss'] }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-dark">Dismiss</button>
                    </form>
                </div>
            </div>
        @endif
        {{-- Stacked info alerts ("For Your Information"): a slim, non-urgent
             strip (role=status, not role=alert) so it reads as an invitation
             rather than a warning. --}}
        @if (! empty($fyiAlerts))
            <div class="fyi-alert d-print-none" role="status" aria-live="polite">
                <div class="container-fluid d-flex align-items-start gap-3">
                    <i class="fa fa-circle-info fyi-alert-icon" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <span class="fyi-alert-title">{{ __('site.fyi') }}</span>
                        @foreach ($fyiAlerts as $fyiAlert)
                            <p class="fyi-alert-line">{!! $fyiAlert !!}</p>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
        @if (isset($showHero) && $showHero)
            {{-- Hero: gradient overlay over a random style-type-appropriate image,
                 mirroring the live hero band. --}}
            <style>
                .layout-hero {
                    background: linear-gradient(rgba(0, 0, 0, 0.45), rgba(0, 0, 0, 0.75)), url('{{ asset('images/'.($heroImage ?? 'misc-cropped-bottles_3000x500.webp')) }}');
                    background-repeat: no-repeat;
                    background-size: cover;
                    background-position: center top;
                }
            </style>
            <div id="hero" class="layout-hero text-white d-flex align-items-center d-print-none">
                <section class="container-fluid shadow-text color-hero px-4">
                    <header>
                        <h1 class="text-center">{{ $ctx->contestStr('contestName') }}</h1>
                    </header>
                </section>
            </div>
        @endif

        <div id="salutation" class="text-white bg-black pt-3 pb-2 d-print-none">
            <section class="container-xxl">
                {!! $salutation ?? '' !!}
            </section>
        </div>

        {{-- Legacy renders a print-only h1 with the contest name on every page
             after the salutation (L4 DOM order: hero, salutation, print-h1); the
             text extractor sees it, so it must be present for content parity. --}}
        <div class="d-none d-print-block landing-page-section p-4">
            <h1>{{ $ctx->contestStr('contestName') }}</h1>
        </div>
    </header>
@endif

@guest
    {{-- index.pub.php #login-modal / #forgot-modal: the login form lives in
         a shell modal opened by the nav Log In button, not on a page. --}}
    <div class="modal fade" id="login-modal" tabindex="-1" role="dialog" aria-labelledby="login-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title" id="login-modal-title">{{ __('site.log_in') }}</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('site.close') }}"></button>
                </div>
                <div class="modal-body">
                    @if (isset($errors) && $errors->any())
                        <div class="alert alert-danger mb-4">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <form method="post" action="{{ route('login.store') }}" class="needs-validation" novalidate>
                        @csrf
                        <div class="form-floating mb-4">
                            <input class="form-control" id="login-user-name" type="email" name="loginUsername"
                                   placeholder="{{ __('site.email') }}" value="{{ old('loginUsername') }}" required autofocus>
                            <label for="login-user-name">{{ __('site.email') }}</label>
                        </div>
                        <div class="form-floating mb-4">
                            <input class="form-control" id="login-password" type="password" name="loginPassword"
                                   placeholder="{{ __('site.password') }}" required>
                            <label for="login-password">{{ __('site.password') }}</label>
                        </div>
                        <div class="d-grid gap-2 mx-auto mb-4">
                            <button id="login-button" class="btn btn-lg btn-success" type="submit">
                                {{ __('site.log_in') }}<i class="fas fa-sign-in-alt ps-2"></i>
                            </button>
                        </div>
                    </form>
                    <div class="d-grid gap-2 mx-auto text-center">
                        {{ __('site.forgot_password') }}
                        <button class="btn btn-sm btn-primary mt-1" data-bs-toggle="modal" data-bs-target="#forgot-modal"
                            data-bs-dismiss="modal">{{ __('site.reset_password') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="forgot-modal" tabindex="-1" role="dialog" aria-labelledby="forgot-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title" id="forgot-modal-title">{{ __('site.reset_password') }}</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('site.close') }}"></button>
                </div>
                <div class="modal-body">
                    <form method="post" action="{{ route('password.forgot') }}" class="needs-validation" novalidate>
                        @csrf
                        <div class="form-floating mb-4">
                            <input class="form-control" id="forgot-user-name" name="email" type="email"
                                   placeholder="{{ __('site.email') }}" value="{{ old('email') }}" required>
                            <label for="forgot-user-name">{{ __('site.email') }}</label>
                        </div>
                        <button type="submit" class="btn btn-lg btn-primary">{{ __('reset.submit') }}</button>
                    </form>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#login-modal"
                            data-bs-dismiss="modal"><i class="fas fa-chevron-left pe-2"></i>{{ __('site.log_in') }}</button>
                        <button type="button" class="btn btn-danger" data-bs-dismiss="modal">{{ __('site.close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endguest
    @if (! empty($modsTop))
        {{-- index.pub.php:357 — mods_top.inc.php render point (before core). --}}
        <section id="mods-top" class="landing-page-section pb-3">
            @foreach ($modsTop as $modContent)
                <div class="mod mb-3">{!! $modContent !!}</div>
            @endforeach
        </section>
    @endif
<div id="main-content" class="{{ $isAdminSide ? 'container-fluid' : 'container-xxl' }}">
    @if ($adminPageTitle !== null)
        {{-- Legacy index.legacy.php:97-98: admin pages render the page-header
             chrome (Administration: <label>) around the blade's own <p class="lead">. --}}
        <div class="admin-page-title">
            <h1>{{ $adminPageTitle }}</h1>
        </div>
    @endif
    @if ($contestInfoExtra !== '' && ! $isAdminSide)
        {{-- index.pub.php:405 — the optional custom-competition-info section,
             legacy id custom-competition-info, rendered above the slot. --}}
        <section id="custom-competition-info" class="landing-page-section pb-3">
            {!! $contestInfoExtra !!}
        </section>
    @endif
    @if (($withSidebar ?? false) && ! $isAdminSide)
        <div class="row g-4">
            <div class="col-12 col-md-8 col-lg-9">
                {{ $slot }}
            </div>
            <x-public-sidebar :ctx="$ctx" />
        </div>
    @else
        {{ $slot }}
    @endif
    @if (! empty($modsBottom))
        {{-- index.pub.php:618 — mods_bottom.inc.php render point (after core). --}}
        <section id="mods-bottom" class="landing-page-section pt-3">
            @foreach ($modsBottom as $modContent)
                <div class="mod mb-3">{!! $modContent !!}</div>
            @endforeach
        </section>
    @endif
</div>

{{-- Public footer flows with the document (static) so it can never overlap
     content or the fixed #sticky-home back-to-top button at short viewport
     heights. The admin frame keeps its fixed dark footer (its layout expects a
     fixed bottom bar under the fixed topbar). --}}
<footer class="site-footer text-white container-fluid {{ $isAdminSide ? 'fixed-bottom' : 'mt-5' }} pt-4 d-print-none">
    <p class="text-center">{{ $ctx->contestStr('contestName') }} &ndash; BCOE&amp;M 3.1.0 &ndash; {{ (int) $ctx->prefsStr('prefsProEdition') === 1 ? __('site.edition_pro') : __('site.edition_amateur') }} 2009-{{ now()->format('Y') }}</p>
</footer>

@if ($isAdminSide)
    @auth
        {{-- Legacy index.legacy.php:259-302 — the two session-expire modals are on
             every admin page. Copy modal bodies verbatim (labels from
             lang/en/en-US.lang.php:1782-1784, alert_text_090/091 at :1862-1863).
             lifetimeMin/endSeconds come from the effective timeout
             (preferences.prefsSessionTimeout, else session.lifetime) and
             heartbeatUrl feeds the app.js resync (ajax/heartbeat.ajax.php). --}}
        <script>window.bcoemAdminSession = { endSeconds: {{ time() + $ctx->sessionTimeoutMinutes() * 60 }}, lifetimeMin: {{ $ctx->sessionTimeoutMinutes() }}, heartbeatUrl: "{{ route('ajax.heartbeat') }}", redirect: "{{ route('logout') }}" };</script>

        <!-- Session Expiring Modal: 2 Minute Warning -->
        <div class="modal fade" id="session-expire-warning" tabindex="-1" aria-labelledby="session-expire-warning-label" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="session-expire-warning-label">Session About To Expire</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p>Your session will expire in two minutes. Stay on the current page to finish your work before time expires. Need more time? Refresh this page to continue your current session (unsaved form data may be lost). Or, simply log out.</p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Stay Here</button>
                <button type="button" class="btn btn-success" data-bs-dismiss="modal" onclick="window.location.reload()">Refresh This Page</button>
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal" onclick="window.bcoemLogout('{{ route('logout') }}')">Log Out</button>
              </div>
            </div>
          </div>
        </div>
        <!-- Session Expiring Modal: 30 Second Warning -->
        <div class="modal fade" id="session-expire-warning-30" tabindex="-1" aria-labelledby="session-expire-warning-30-label" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="session-expire-warning-30-label">Session About To Expire</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p>Your session will expire in 30 seconds. You can refresh to continue your current session or log out.</p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-success" data-bs-dismiss="modal" onclick="window.location.reload()">Refresh This Page</button>
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal" onclick="window.bcoemLogout('{{ route('logout') }}')">Log Out</button>
              </div>
            </div>
          </div>
        </div>
    @endauth
@endif

</body>
</html>
