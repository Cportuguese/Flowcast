<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['patient_id', 'provider_id', 'scheduled_at', 'reason', 'status', 'cancel_reason', 'reviewed_by_id', 'reviewed_at', 'review_note'])]
class Appointment extends Model
{
    use HasFactory;

    /**
     * Every status. `pending` is "under review", `booked` is what the
     * patient sees as "Confirmed" (the nurse approved it).
     */
    public const STATUSES = ['pending', 'booked', 'declined', 'cancelled', 'checked_in', 'no_show', 'completed'];

    /** Statuses that hold a slot. Cancelled and declined bookings free it. */
    public const SLOT_HOLDING_STATUSES = ['pending', 'booked', 'checked_in', 'no_show', 'completed'];

    /** Confirmed and not yet seen — still ahead of the patient. */
    public const OPEN_STATUSES = ['booked', 'checked_in'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /** The professional the patient booked with. */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    /** The record the dentist or physician wrote, for bookings seen outside the queue. */
    public function visit(): HasOne
    {
        return $this->hasOne(VisitRecord::class);
    }

    /**
     * Bookings with the nurse go through the walk-in queue (the patient
     * checks in and gets a number). The dentist and the school physician
     * see their patients at the booked time instead. Bookings from before
     * professionals existed have no provider and were always with the nurse.
     */
    public function goesThroughQueue(): bool
    {
        if ($this->provider_id === null) {
            return true;
        }

        return $this->provider?->profession === 'nurse';
    }

    /** Query form of goesThroughQueue(). */
    public function scopeThroughQueue(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('provider_id')
            ->orWhereIn('provider_id', User::query()->select('id')->where('profession', 'nurse')));
    }
}
