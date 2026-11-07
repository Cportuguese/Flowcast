<?php

namespace App\Services;

use App\Mail\BookingCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The one-time code that confirms a booking before it reaches the nurse.
 *
 * One live code per patient, kept in the cache (never the database) and
 * stored hashed like a password, so not even someone reading the cache
 * table can lift it. A code is tied to the slot it was requested for: asking
 * for 9:00 and then submitting 10:00 needs a new code.
 */
class BookingOtp
{
    public const TTL_SECONDS = 600;

    public const RESEND_AFTER_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    /**
     * Email a fresh code, unless one went out less than a minute ago.
     *
     * @param  array{provider: string, when: string}  $details  shown in the email
     * @return array{status: 'sent'|'too_soon'|'failed', retry_after: int}
     */
    public function send(User $user, string $fingerprint, array $details): array
    {
        $key = $this->key($user);
        $existing = Cache::get($key);

        if (is_array($existing)) {
            $wait = self::RESEND_AFTER_SECONDS - (Carbon::now()->getTimestamp() - $existing['sent_at']);

            if ($wait > 0) {
                return ['status' => 'too_soon', 'retry_after' => $wait];
            }
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $expiresAt = Carbon::now()->addSeconds(self::TTL_SECONDS);

        Cache::put($key, [
            'hash' => Hash::make($code),
            'fingerprint' => $fingerprint,
            'attempts' => 0,
            'sent_at' => Carbon::now()->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        try {
            Mail::to($user->email)->send(new BookingCode($user, $code, $details, intdiv(self::TTL_SECONDS, 60)));
        } catch (Throwable $e) {
            // A code the patient never received must not count as sent, or
            // the resend cooldown would lock them out of retrying.
            Cache::forget($key);
            Log::warning('Booking code email could not be sent.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return ['status' => 'failed', 'retry_after' => 0];
        }

        return ['status' => 'sent', 'retry_after' => self::RESEND_AFTER_SECONDS];
    }

    /**
     * Check a submitted code. A correct code is used up; a wrong one costs
     * an attempt, and after MAX_ATTEMPTS the code stops working altogether
     * so six digits cannot be guessed by brute force.
     *
     * @return array{result: 'ok'|'expired'|'other_slot'|'locked'|'wrong', attempts_left: int}
     */
    public function verify(User $user, string $fingerprint, string $code): array
    {
        $key = $this->key($user);
        $entry = Cache::get($key);

        if (! is_array($entry)) {
            return ['result' => 'expired', 'attempts_left' => 0];
        }

        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            return ['result' => 'locked', 'attempts_left' => 0];
        }

        if (! hash_equals($entry['fingerprint'], $fingerprint)) {
            return ['result' => 'other_slot', 'attempts_left' => self::MAX_ATTEMPTS - $entry['attempts']];
        }

        if (! Hash::check($code, $entry['hash'])) {
            $entry['attempts']++;
            Cache::put($key, $entry, Carbon::createFromTimestamp($entry['expires_at']));

            return ['result' => $entry['attempts'] >= self::MAX_ATTEMPTS ? 'locked' : 'wrong', 'attempts_left' => self::MAX_ATTEMPTS - $entry['attempts']];
        }

        Cache::forget($key);

        return ['result' => 'ok', 'attempts_left' => self::MAX_ATTEMPTS];
    }

    /** "juan.cruz@gmail.com" -> "ju*******@gmail.com", for "we sent it to ...". */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, max(mb_strlen($local) - 1, 1)));

        return $visible.str_repeat('*', max(mb_strlen($local) - mb_strlen($visible), 1)).'@'.$domain;
    }

    private function key(User $user): string
    {
        return "booking-otp:{$user->id}";
    }
}
