<!DOCTYPE html>
@php($isAdminSide = request()->is('admin') || request()->is('admin/*') || request()->is('backoffice*') || request()->is('eval*'))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($isAdminSide) data-theme="bcoem-brux" @endif>
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
                        <a class="nav-item nav-link" href="{{ url('/list') }}">{{ __('site.my_account') }}</a>
                        <a class="nav-item nav-link" href="{{ url('/list/edit-account') }}">{{ __('site.edit_account') }}</a>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="nav-item nav-link btn btn-link">{{ __('site.log_out') }}</button>
                        </form>
                    @else
                        <a class="nav-item nav-link" href="{{ route('login') }}">{{ __('site.log_in') }}</a>
                    @endif
                </div>
            </section>
        </div>
    </nav>
    {{-- Legacy renders section alerts (login nudge, archived-data notice)
         between the nav and the hero (headers.inc.php via alerts.pub.php). --}}
    @if ((int) request('msg') === 99)
        <p class="alert alert-warning"><strong>{{ __('site.please_log_in') }}</strong></p>
    @elseif ((int) request('msg') === 8)
        <p class="alert alert-warning"><strong>{{ __('site.archived_not_available') }}</strong></p>
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

<div id="main-content" class="container-xxl">
    {{ $slot }}
</div>

<footer class="site-footer text-white justify-content-center container-fluid fixed bottom-0 pt-4 print:hidden">
    <p class="text-center">{{ $ctx->contestStr('contestName') }} &ndash; BCOE&amp;M 3.1.0 &ndash; {{ (int) $ctx->prefsStr('prefsProEdition') === 1 ? __('site.edition_pro') : __('site.edition_amateur') }} 2009-{{ now()->format('Y') }}</p>
</footer>

</body>
</html>
