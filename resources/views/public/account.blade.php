{{-- Legacy pub/list.pub.php account surface (section=list). The body
     lives in public/partials/account-main, shared with /pay — index.pub.php
     renders the same list.pub.php block for both sections. --}}
@php($band = '<h1 class="fw-bold">'.e($ctx->contestStr('contestName')).'</h1>'
    .'<p class="landing-page-salutation"><small>'.__('site.welcome').' '.e($info['brewer']->brewerFirstName ?? '').'!</small></p>')
<x-public-layout :ctx="$ctx" :salutation="$band" :judging-started="$judgingStarted" :future-judging-sessions="$windows->futureJudgingSessions">
    @include('public.partials.account-main')
</x-public-layout>
