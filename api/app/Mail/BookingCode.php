<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The 6-digit code a patient types to send a booking for review. Sent
 * immediately rather than queued: the patient is waiting on the screen,
 * and no queue worker runs on the clinic laptop.
 */
class BookingCode extends Mailable
{
    use Queueable;

    /**
     * @param  array{provider: string, when: string}  $details
     */
    public function __construct(
        public User $patient,
        public string $code,
        public array $details,
        public int $validMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your Flowcast booking code: {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.booking-code',
            text: 'mail.booking-code-text',
        );
    }
}
