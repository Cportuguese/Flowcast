<?php

use Illuminate\Support\Facades\Schedule;

// Expired tokens are already refused by Sanctum; this just deletes the
// dead rows so personal_access_tokens doesn't grow forever.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Rolls yesterday's queue activity into hourly analytics_snapshots rows
// that the forecast endpoints read from.
Schedule::command('analytics:snapshot')->dailyAt('00:05');

// Refits the wait-time and volume models on accumulated history. Runs after
// Sunday's snapshot so the newest day is already in the panel, and only
// promotes a new version when it beats the active one on the holdout.
Schedule::command('ml:train')->weeklyOn(0, '00:30');
