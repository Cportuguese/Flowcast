<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpoPushNotifier
{
    /**
     * Pushes go out inline, inside the request that changed the queue, so a
     * slow Expo must not hold a nurse's "Call next" for the HTTP client's
     * default 30 seconds. Expo normally answers in well under one.
     */
    private const TIMEOUT_SECONDS = 5;

    private const CONNECT_TIMEOUT_SECONDS = 3;

    /**
     * Best-effort, like the WebSocket broadcasts. By the time this runs the
     * queue change is already in the database and the patient will see it on
     * their queue screen, so an unreachable Expo — no outbound internet on the
     * clinic server, DNS, an outage — is logged and swallowed rather than
     * failing the action that triggered it.
     *
     * @param  array<string, mixed>  $data
     */
    public function send(User $user, string $title, string $body, array $data = []): void
    {
        if (! $user->push_token) {
            return;
        }

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $user->push_token,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ]);
        } catch (Throwable $e) {
            Log::warning('Expo push notification could not be sent.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($response->failed()) {
            Log::warning('Expo push notification failed.', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);
        }
    }
}
