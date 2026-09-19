<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expired tokens are already refused by Sanctum; this just deletes the
// dead rows so personal_access_tokens doesn't grow forever.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Rolls yesterday's queue activity into hourly analytics_snapshots rows
// that the forecast endpoints read from.
Schedule::command('analytics:snapshot')->dailyAt('00:05');
