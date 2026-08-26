<!DOCTYPE html>
@php($isAdminSide = request()->is('admin') || request()->is('admin/*') || request()->is('backoffice*') || request()->is('eval*'))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($isAdminSide) data-theme="bcoem-brux" @endif>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $ctx->contestStr('contestName') }} - Brew Competition Online Entry &amp; Management</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @if ($ctx->contestStr('contestName'))
        <meta property="og:title" content="{{ $ctx->contestStr('contestName') }}">
    @endif
    @if ($ctx->contestStr('contestLogo'))
        <meta property="og:image" content="{{ asset('user_images/'.$ctx->contestStr('contestLogo')) }}">
    @endif
</head>
<body>

<a name="top"></a>

@if ($isAdminSide)
    {{-- Legacy admin chrome (index.legacy.php + sections/nav.sec.php):
        inverse navbar (Home left; print, user dropdown, Admin offcanvas
        right), then the admin off-canvas "Admin Essentials" menu.
        Class names avoid daisyUI's .collapse (accordion) on purpose. --}}
    <nav class="navbar-inverse navbar-fixed-top print:hidden" style="z-index: 1000;">
        <div class="container-fluid">
            <div class="admin-nav-body">
                <ul class="nav navbar-nav">
                    <li><a class="hide-loader" href="{{ url('/') }}">Home</a></li>
                </ul>
                <ul class="nav navbar-nav navbar-right">
                    <li><a class="hide-loader hidden-xs hidden-sm hidden-md" href="#" onclick="window.print()" role="button"><span class="fa fa-print"></span></a></li>
                    @auth
                        <li class="dropdown">
                            <a href="#" class="my-dropdown" role="button"><span class="fa fa-user"></span> <span class="caret"></span></a>
                            <ul class="dropdown-menu">
                                <li class="dropdown-header"><strong>{{ auth()->user()->user_name }}</strong></li>
                                <li role="separator" class="divider"></li>
                                <li><a href="{{ url('/list') }}" tabindex="-1">{{ __('site.my_account') }}</a></li>
                                <li><a href="{{ url('/list/edit-account') }}" tabindex="-1">{{ __('site.edit_account') }}</a></li>
                                <li role="separator" class="divider"></li>
                                <li>
                                    <form method="post" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item" tabindex="-1">{{ __('site.log_out') }}</button>
                                    </form>
                                </li>
                            </ul>
                        </li>
                        @if (auth()->user()->isAdmin())
                            <li><a href="#" id="admin-offcanvas-open" role="button"><i class="fa fa-chevron-circle-left"></i> {{ __('site.admin_short') }}</a></li>
                        @endif
                    @endauth
                </ul>
            </div>
        </div>
    </nav>

    <div class="navbar-inverse navmenu navmenu-inverse navmenu-fixed-right offcanvas admin-nav-off-canvas" id="admin-offcanvas">
        <div class="navmenu-brand disabled off-canvas-header d-flex justify-content-between align-items-center">
            <span>Admin Essentials Menu</span>
            <button type="button" id="admin-offcanvas-close" class="btn-close btn-close-white" aria-label="Close Admin Essentials menu"></button>
        </div>
        <ul class="nav navmenu-nav">
            <li class="disabled"><a href="#"><em class="bcoem-admin-menu-disabled">This menu contains only essential functions. Select <strong>Admin Dashboard</strong> for all options.</em></a></li>
            <li><a href="{{ url('/admin') }}">Admin Dashboard</a></li>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" role="button">Competition Preparation <span class="caret"></span></a>
                <ul class="dropdown-menu navmenu-nav">
                    <li><a href="{{ url('/admin/dates') }}">Edit All Competition Dates</a></li>
                    <li><a href="{{ url('/admin/competition-info') }}">Edit Competition Info</a></li>
                    <li><a href="{{ url('/admin/contacts') }}">Manage Contacts</a></li>
                    <li><a href="{{ url('/admin/judging/special-best') }}">Manage Custom Categories</a></li>
                    <li><a href="{{ url('/admin/dropoff') }}">Manage Drop-Off Locations</a></li>
                    <li><a href="{{ url('/admin/judging/locations') }}">Manage Judging Sessions</a></li>
                    <li><a href="{{ url('/admin/judging/non-judging') }}">Manage Non-Judging Sessions</a></li>
                    <li><a href="{{ url('/admin/sponsors') }}">Manage Sponsors</a></li>
                    <li><a href="{{ url('/admin/styles') }}">Manage Styles Accepted</a></li>
                    <li><a href="{{ url('/admin/style-types') }}">Manage Style Types</a></li>
                    <li><a href="{{ url('/admin/hero-images') }}">Upload Logo Images</a></li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" role="button">Entries, Payments, and Participants <span class="caret"></span></a>
                <ul class="dropdown-menu navmenu-nav">
                    <li><a href="{{ url('/backoffice/entries') }}">Manage Entries</a></li>
                    <li><a href="{{ url('/backoffice/payments') }}">Manage Payments</a></li>
                    <li><a href="{{ url('/backoffice/participants') }}">Manage Participants</a></li>
                    <li><a href="{{ url('/admin/judging/flights') }}">Assign Judges</a></li>
                    <li><a href="{{ url('/admin/judging/flights') }}">Assign Stewards</a></li>
                    <li><a href="{{ url('/register/entrant') }}">Quick Register a Judge</a></li>
                    <li><a href="{{ url('/register/entrant') }}">Quick Register Steward</a></li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" role="button">Sorting <span class="caret"></span></a>
                <ul class="dropdown-menu navmenu-nav">
                    <li><a href="{{ url('/backoffice/entries') }}">Manually</a></li>
                    <li><a href="{{ url('/admin/judging/checkin') }}">Entry Check-in Via Barcode Scanner</a></li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" role="button">Organizing <span class="caret"></span></a>
                <ul class="dropdown-menu navmenu-nav">
                    <li><a href="{{ url('/admin/judging/tables') }}">Manage Tables</a></li>
                    <li><a href="{{ url('/admin/judging/tables') }}">Assign Judges/Stewards to Tables</a></li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" role="button">Scoring <span class="caret"></span></a>
                <ul class="dropdown-menu navmenu-nav">
                    <li><a href="{{ url('/admin/upload-scoresheets') }}">Upload Scoresheets</a></li>
                    <li><a href="{{ url('/admin/judging/scores') }}">Manage Scores</a></li>
                    <li><a href="{{ url('/admin/judging/bos') }}">Manage BOS Entries and Places</a></li>
                </ul>
            </li>
        </ul>
    </div>
@else
    <header id="home" class="site-header">
        <nav id="site-nav" class="site-nav family-sans navbar fixed top-0 text-white print:hidden" style="z-index: 1000;">
            <div class="container-fluid flex flex-wrap items-center">
                <a class="btn btn-ghost" href="{{ url()->current() === url('/') ? '#home' : url('/') }}"><i class="fas fa-home me-2"></i></a>
                <input type="checkbox" id="nav-toggle" class="peer hidden">
                <label for="nav-toggle" class="btn btn-ghost btn-square md:hidden" aria-label="Toggle Navigation"><i class="fas fa-bars"></i></label>
                <section id="nav-menu" class="md:ms-auto w-full md:w-auto flex-col md:flex-row items-start md:items-center hidden peer-checked:flex md:flex">
                    @php($onLanding = request()->routeIs('home'))
                    @if (! ($judgingStarted ?? false))
                        <a class="nav-item nav-link" href="{{ $onLanding ? '#rules' : url('/').'#rules' }}">{{ __('site.rules') }}</a>
                        <a class="nav-item nav-link" href="{{ $onLanding ? '#volunteers' : url('/').'#volunteers' }}">{{ __('site.volunteers') }}</a>
                    @endif
                    @if (($futureJudgingSessions ?? 0) > 0)
                        <a class="nav-item nav-link" href="{{ $onLanding ? '#entry-info' : url('/').'#entry-info' }}">{{ __('site.entry_info') }}</a>
                    @endif
                    @if ($sponsorsVisible ?? false)
                        <a class="nav-item nav-link" href="{{ $onLanding ? '#sponsors' : url('/').'#sponsors' }}">{{ __('site.sponsors') }}</a>
                    @endif
                    <a class="nav-item nav-link" href="{{ $onLanding ? '#contact' : url('/').'#contact' }}">{{ __('site.contact') }}</a>

                    @if(Auth::check())
                        @if (auth()->user()->isAdmin())
                            <a class="nav-item nav-link" href="{{ url('/admin') }}">{{ __('site.admin_short') }}</a>
                        @endif
                        {{-- pub/nav.pub.php: fa-user dropdown + flat logout icon --}}
                        @php($navWindows = \App\Support\Tenant\Windows::derive($ctx, time()))
                        <div class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" aria-expanded="false"><i class="fa fa-lg fa-fw fa-user"></i></a>
                            <ul class="dropdown-menu dropdown-menu-end">
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
                            </ul>
                        </div>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="nav-item nav-link" aria-label="{{ __('site.log_out') }}"><i class="fa fa-lg fa-fw fa-sign-out-alt"></i></button>
                        </form>
                    @else
                        <a class="nav-item nav-link" href="#" data-open-modal="login-modal">{{ __('site.log_in') }}</a>
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
        {{-- alerts.pub.php stacked info alerts ("For Your Information") --}}
        @if (! empty($fyiAlerts))
            <div class="alert alert-info print:hidden" role="alert">
                <strong>{{ __('site.fyi') }}</strong>
                @foreach ($fyiAlerts as $fyiAlert)
                    <p class="mb-1">{!! $fyiAlert !!}</p>
                @endforeach
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
            <div id="hero" class="layout-hero text-white flex items-center print:hidden">
                <section class="container-fluid shadow-text color-hero px-4">
                    <header>
                        <h1 class="text-center">{{ $ctx->contestStr('contestName') }}</h1>
                    </header>
                </section>
            </div>
        @endif

        <div id="salutation" class="text-white bg-black pt-6 pb-4 print:hidden">
            <section class="container-xxl">
                {!! $salutation ?? '' !!}
            </section>
        </div>

        {{-- Legacy renders a print-only h1 with the contest name on every page
             after the salutation (L4 DOM order: hero, salutation, print-h1); the
             text extractor sees it, so it must be present for content parity. --}}
        <div class="hidden print:block landing-page-section p-4">
            <h1>{{ $ctx->contestStr('contestName') }}</h1>
        </div>
    </header>
@endif


@guest
    {{-- index.pub.php #login-modal / #forgot-modal: the login form lives in
         a shell modal opened by the nav Log In button, not on a page. --}}
    <dialog id="login-modal" class="modal">
        <div class="modal-box max-w-2xl">
            <h1 class="text-lg font-bold mb-4">{{ __('site.log_in') }}</h1>
            @if ($errors->any())
                <div class="alert alert-error mb-4">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form method="post" action="{{ route('login.store') }}" class="needs-validation" novalidate>
                @csrf
                <label class="floating-label w-full mb-4">
                    <input class="input input-bordered input-lg w-full" id="login-user-name" type="email" name="loginUsername"
                           placeholder="{{ __('site.email') }}" value="{{ old('loginUsername') }}" required autofocus>
                    <span>{{ __('site.email') }}</span>
                </label>
                <label class="floating-label w-full mb-4">
                    <input class="input input-bordered input-lg w-full" id="login-password" type="password" name="loginPassword"
                           placeholder="{{ __('site.password') }}" required>
                    <span>{{ __('site.password') }}</span>
                </label>
                <div class="d-grid gap-2 mx-auto mb-4">
                    <button id="login-button" class="btn btn-lg btn-success" type="submit">
                        {{ __('site.log_in') }}<i class="fas fa-sign-in-alt ps-2"></i>
                    </button>
                </div>
            </form>
            <div class="d-grid gap-2 mx-auto text-center">
                {{ __('site.forgot_password') }}
                <button class="btn btn-sm btn-primary mt-1" data-open-modal="forgot-modal">{{ __('site.reset_password') }}</button>
            </div>
            <div class="modal-action">
                <button type="button" class="btn btn-danger" data-close-modal>{{ __('site.close') }}</button>
            </div>
        </div>
    </dialog>

    <dialog id="forgot-modal" class="modal">
        <div class="modal-box max-w-2xl">
            <h1 class="text-lg font-bold mb-4">{{ __('site.reset_password') }}</h1>
            <form method="post" action="{{ route('password.forgot') }}" class="needs-validation" novalidate>
                @csrf
                <label class="floating-label w-full mb-4">
                    <input class="input input-bordered input-lg w-full" id="forgot-user-name" name="email" type="email"
                           placeholder="{{ __('site.email') }}" value="{{ old('email') }}" required>
                    <span>{{ __('site.email') }}</span>
                </label>
                <button type="submit" class="btn btn-lg btn-primary">{{ __('reset.submit') }}</button>
            </form>
            <div class="modal-action">
                <button type="button" class="btn btn-dark" data-open-modal="login-modal"><i class="fas fa-chevron-left pe-2"></i>{{ __('site.log_in') }}</button>
                <button type="button" class="btn btn-danger" data-close-modal>{{ __('site.close') }}</button>
            </div>
        </div>
    </dialog>
@endguest
<div id="main-content" class="{{ $isAdminSide ? 'container-fluid' : 'container-xxl' }}">
    {{ $slot }}
</div>

<footer class="site-footer text-white justify-content-center container-fluid fixed bottom-0 pt-4 print:hidden">
    <p class="text-center">{{ $ctx->contestStr('contestName') }} &ndash; BCOE&amp;M 3.1.0 &ndash; {{ (int) $ctx->prefsStr('prefsProEdition') === 1 ? __('site.edition_pro') : __('site.edition_amateur') }} 2009-{{ now()->format('Y') }}</p>
</footer>

</body>
</html>
