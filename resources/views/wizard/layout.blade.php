<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Setup') &ndash; Brew Competition Online Entry &amp; Management</title>

    {{-- The wizard is standalone by design: a fresh upload may have no DB, no
         build manifest and no @vite() output, so Bootstrap comes from the CDN
         and every style lives HERE, in this one file. The screens use
         Bootstrap utility classes only. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Serif:ital,wght@0,400..700;1,400..700&family=Noto+Sans+Display:ital,wght@0,100..900;1,100..900&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>
        /* Legacy palette mapped onto Bootstrap's theme variables. Bootstrap
           bakes compiled colors into buttons/alerts/progress, so those
           components are remapped to the same values below. */
        :root {
            --bs-primary: #007bff;
            --bs-primary-rgb: 0, 123, 255;
            --bs-success: #28a745;
            --bs-success-rgb: 40, 167, 69;
            --bs-danger: #dc3545;
            --bs-danger-rgb: 220, 53, 69;
            --bs-info: #17a2b8;
            --bs-info-rgb: 23, 162, 184;
            --bs-warning: #ffc107;
            --bs-warning-rgb: 255, 193, 7;
            --bs-dark: #343a40;
            --bs-dark-rgb: 52, 58, 64;
            --bs-light: #f8f9fa;
            --bs-light-rgb: 248, 249, 250;
            --bs-body-bg: #f8f9fa;
            --bs-body-font-family: 'Noto Sans Display', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol';
            --bs-link-color: #007bff;
            --bs-link-hover-color: #0069d9;
            --bs-link-color-rgb: 0, 123, 255;
        }

        body {
            background-color: #f8f9fa;
            font-family: var(--bs-body-font-family);
        }

        /* App dark chrome (#212529 family) + wordmark. There is no DB, so this
           cannot read the contest name. */
        .wizard-topbar {
            background-color: #212529;
            color: #fff;
        }

        .wizard-wordmark {
            font-weight: 700;
            letter-spacing: .02em;
        }

        .wizard-step {
            color: #6c757d;
            font-size: .875rem;
        }

        .btn-primary {
            --bs-btn-bg: #007bff;
            --bs-btn-border-color: #007bff;
            --bs-btn-hover-bg: #0069d9;
            --bs-btn-hover-border-color: #0062cc;
            --bs-btn-active-bg: #0062cc;
            --bs-btn-active-border-color: #005cbf;
            --bs-btn-disabled-bg: #007bff;
            --bs-btn-disabled-border-color: #007bff;
            --bs-btn-focus-shadow-rgb: 0, 123, 255;
        }

        .btn-outline-primary {
            --bs-btn-color: #007bff;
            --bs-btn-border-color: #007bff;
            --bs-btn-hover-bg: #007bff;
            --bs-btn-hover-border-color: #007bff;
            --bs-btn-active-bg: #007bff;
            --bs-btn-active-border-color: #007bff;
            --bs-btn-focus-shadow-rgb: 0, 123, 255;
        }

        .btn-success {
            --bs-btn-bg: #28a745;
            --bs-btn-border-color: #28a745;
            --bs-btn-hover-bg: #218838;
            --bs-btn-hover-border-color: #1e7e34;
            --bs-btn-active-bg: #1e7e34;
            --bs-btn-active-border-color: #1c7430;
        }

        .alert-success {
            --bs-alert-color: #155724;
            --bs-alert-bg: #d4edda;
            --bs-alert-border-color: #c3e6cb;
        }

        .alert-info {
            --bs-alert-color: #0c5460;
            --bs-alert-bg: #d1ecf1;
            --bs-alert-border-color: #bee5eb;
        }

        .alert-warning {
            --bs-alert-color: #856404;
            --bs-alert-bg: #fff3cd;
            --bs-alert-border-color: #ffeeba;
        }

        .alert-danger {
            --bs-alert-color: #721c24;
            --bs-alert-bg: #f8d7da;
            --bs-alert-border-color: #f5c6cb;
        }

        .progress {
            --bs-progress-bar-bg: #007bff;
        }

        .wizard-progress {
            height: .5rem;
        }
    </style>
</head>
<body>
<header class="wizard-topbar py-3 mb-4">
    <div class="container">
        <span class="wizard-wordmark">Brew Competition Online Entry &amp; Management</span>
    </div>
</header>

<div class="container pb-5">
    <div class="wizard-card mx-auto">
        <p class="wizard-step mb-1">@yield('step')</p>
        <h1 class="h3 mb-4">@yield('heading')</h1>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <strong>Please check the following:</strong>
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-body p-4">
                @yield('content')
            </div>
        </div>
    </div>
</div>
@stack('scripts')
</body>
</html>
