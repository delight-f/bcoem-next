{{-- warnings.eval.php port: session countdown timers. The legacy file
     shipped two flavors — scoresheet elapsed-time warnings and dashboard
     countdowns to judging close / next open session; the dashboard flavor
     is what the ported surfaces include. Timers degrade gracefully
     without JS (the text below simply stays). --}}
<p id="judging-ends-p" class="fs-6 text-muted">
    <strong>Judging closes:</strong> <span id="judging-ends">{{ \Carbon\Carbon::createFromTimestamp((int) ($ctx->judgingStr('jPrefsJudgingClosed') ?? 0))->toDayDateTimeString() }}</span>
</p>
<script>
    (function () {
        var end = {{ (int) ($ctx->judgingStr('jPrefsJudgingClosed') ?? 0) }} * 1000;
        function tick() {
            var left = Math.floor((end - Date.now()) / 1000);
            if (left <= 0) { document.getElementById('judging-ends').textContent = 'closed'; return; }
            var h = String(Math.floor(left / 3600)).padStart(2, '0');
            var m = String(Math.floor((left % 3600) / 60)).padStart(2, '0');
            var s = String(left % 60).padStart(2, '0');
            document.getElementById('judging-ends').textContent = h + ':' + m + ':' + s;
        }
        tick();
        setInterval(tick, 1000);
    })();
</script>
