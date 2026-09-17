{{--
    One medal-grid row group for a single placing entry (legacy awards.php
    medal-grid markup). The fragment index AND the pos-N class both use
    place_heirarchy() — 1st=N 5 (gold, top row) through HM=N 1 (teal,
    bottom row) — matching css/awards.css grid rows.
--}}
<div class="medal-grid">
    <div class="fragment justify-right col-right" data-fragment-index="{{ $w->fh }}"><i class="fa fa-trophy icon pos-{{ $w->fh }}-medal-color"></i>{{ $w->place }}</div>
    <div class="fragment justify-left" data-fragment-index="{{ $w->fh }}">{{ $w->name }}@if ($w->coBrewer !== '')<span style="padding-top: .9em;" class="small">&nbsp;&amp;&nbsp;<em>{{ $w->coBrewer }}</em></span>@endif</div>
    @if ($w->club !== '')
        <div></div>
        <div class="fragment justify-left small" data-fragment-index="{{ $w->fh }}">{{ $w->club }}</div>
    @endif
    <div></div>
    <div class="fragment justify-left small" data-fragment-index="{{ $w->fh }}">{{ $w->entry }} ({{ $w->style }})</div>
</div>
