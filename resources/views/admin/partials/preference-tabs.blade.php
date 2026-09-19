{{-- Canonical preference tab bar (issue #58), shared by the five
     site-preferences tabs and the Judging/Competition Organization page so the
     two cannot drift. The canonical tab list lives here; callers pass only the
     active tab key. --}}
@php
    $preferenceTabs = [
        'default' => 'General',
        'entries' => 'Entries',
        'email' => 'Email & Contact',
        'payment' => 'Currency and payments',
        'best' => 'Best Brewer and/or Club',
        'judging' => 'Judging/Competition Organization',
    ];
    $active = $active ?? 'default';
@endphp
<ul class="nav nav-tabs mb-4">
    @foreach ($preferenceTabs as $tabGo => $label)
        @php($isActive = $active === $tabGo)
        <li class="nav-item">
            <a class="nav-link {{ $isActive ? 'active' : '' }}"{!! $isActive ? ' aria-current="page"' : '' !!} href="{{ $tabGo === 'judging' ? route('admin.judging.preferences.show') : url('/admin/site-preferences/'.$tabGo) }}">{{ $label }}</a>
        </li>
    @endforeach
</ul>
