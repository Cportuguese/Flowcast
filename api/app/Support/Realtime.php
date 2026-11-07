<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Live updates — the queue screens, the TV board, patients' phones — are
 * best-effort: the change they announce is already saved.
 *
 * When the realtime server (Reverb) is down, every attempt waits for a
 * connection that never comes (about two seconds each on the clinic
 * laptop), and a single queue action announces a dozen things: enough to
 * run the request past PHP's time limit and fail the nurse's click. So
 * after one failure the following announcements are skipped for a short
 * while instead of each paying that wait. Screens catch up by themselves:
 * the TV board re-reads the queue every minute, the patient app reloads
 * when a tab is opened, and the nurse's own actions return fresh data.
 */
final class Realtime
{
    private const UNAVAILABLE_UNTIL = 'realtime.unavailable_until';

    private const PAUSE_SECONDS = 30;

    public static function broadcast(object $event): void
    {
        if (Cache::get(self::UNAVAILABLE_UNTIL, 0) > Carbon::now()->getTimestamp()) {
            return;
        }

        try {
            broadcast($event);
        } catch (Throwable $e) {
            Cache::put(self::UNAVAILABLE_UNTIL, Carbon::now()->addSeconds(self::PAUSE_SECONDS)->getTimestamp(), self::PAUSE_SECONDS);
            report($e);
        }
    }
}
