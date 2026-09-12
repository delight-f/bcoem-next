<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Central clubs list (issue #22, Task B.4). Lightweight and infrequent — a
// few hundred string comparisons — so it runs inline with no queue worker.
// ClubsSyncService no-ops when the upstream version has not changed.
Schedule::command('clubs:sync')->daily();
