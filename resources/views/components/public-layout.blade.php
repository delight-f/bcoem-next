<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ctx->contestStr('contestName') }} - Brew Competition Online Entry &amp; Management</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="{{ asset('css/common-3.min.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('css/default-3.min.css') }}">

    @if ($ctx->contestStr('contestName'))
        <meta property="og:title" content="{{ $ctx->contestStr('contestName') }}">
    @endif
    @if ($ctx->contestStr('contestLogo'))
        <meta property="og:image" content="{{ asset('user_images/'.$ctx->contestStr('contestLogo')) }}">
    @endif
</head>
<body data-bs-spy="scroll" data-bs-target="#site-nav">

<a name="top"></a>

<header id="home" class="site-header">
    <nav class="landing-nav d-print-none">
        {{-- Legacy nav (nav.pub.php): Rules/Volunteers only before judging
             starts, Entry Info while future sessions remain, sponsors when
             enabled, Contact always. --}}
        @if (! ($judgingStarted ?? false))
            <a href="#rules">{{ __('site.rules') }}</a>
            <a href="#volunteers">{{ __('site.volunteers') }}</a>
        @endif
        @if (($futureJudgingSessions ?? 0) > 0)
            <a href="#entry-info">{{ __('site.entry_info') }}</a>
        @endif
        @if ($sponsorsVisible ?? false)
            <a href="#sponsors">{{ __('site.sponsors') }}</a>
        @endif
        <a href="#contact">{{ __('site.contact') }}</a>
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
        <div id="hero" class="layout-hero text-light d-flex align-items-center d-print-none">
            <section class="container-fluid shadow-text color-hero px-3">
                <header>
                    <h1 class="text-center">{{ $ctx->contestStr('contestName') }}</h1>
                </header>
            </section>
        </div>
    @endif

    <div id="salutation" class="text-light bg-black pt-4 pb-3 d-print-none">
        <section class="container-xxl">
            {{ $salutation ?? '' }}
        </section>
    </div>
</header>

<div id="main-content" class="container-xxl">
    {{ $slot }}
</div>

<footer class="site-footer bg-dark text-light justify-content-center container-fluid fixed-bottom pt-3 d-print-none">
    <p class="text-center">{{ $ctx->contestStr('contestName') }} &ndash; BCOE&amp;M 3.1.0 &ndash; {{ (int) $ctx->prefsStr('prefsProEdition') === 1 ? __('site.edition_pro') : __('site.edition_amateur') }} 2009-{{ now()->format('Y') }}</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js"></script>
</body>
</html>
