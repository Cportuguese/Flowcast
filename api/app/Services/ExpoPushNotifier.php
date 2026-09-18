<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushNotifier
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function send(User $user, string $title, string $body, array $data = []): void
    {
        if (! $user->push_token) {
            return;
        }

        $response = Http::post('https://exp.host/--/api/v2/push/send', [
            'to' => $user->push_token,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        if ($response->failed()) {
            Log::warning('Expo push notification failed.', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);
        }
    }
}
