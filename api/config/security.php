<?php

/*
|--------------------------------------------------------------------------
| Security knobs
|--------------------------------------------------------------------------
|
| Everything here is env-driven so it can be tuned per deployment (or
| bumped for a busy event day) without a code change. Values are requests
| per minute unless stated.
|
| Limits are keyed by user ID once authenticated, so crowd size never
| matters — 200 students on one campus WiFi are 200 separate buckets. Only
| guests share an IP bucket, and the only guest-reachable routes are login,
| register and the TV board.
|
*/

return [

    'rate_limits' => [

        // Every authenticated request, per user.
        'api' => (int) env('RATE_LIMIT_API', 120),

        // Unauthenticated requests, per IP. Generous because a whole campus
        // network can sit behind one NAT during orientation.
        'guest' => (int) env('RATE_LIMIT_GUEST', 300),

        // Login/register, per (email, IP) pair — stops password guessing on
        // one account without locking out everyone on the same network.
        'auth' => (int) env('RATE_LIMIT_AUTH', 5),

        // The public TV board, per IP. It fetches once and rides WebSockets.
        'public' => (int) env('RATE_LIMIT_PUBLIC', 60),

        // Actions that verify the current password (password change), per
        // user — otherwise a live session on an unattended desktop could
        // guess the password at the general API rate.
        'sensitive' => (int) env('RATE_LIMIT_SENSITIVE', 5),
    ],

];
