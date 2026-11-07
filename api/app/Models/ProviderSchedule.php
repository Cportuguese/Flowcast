<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * When one professional can be booked on one weekday, cut into slots of
 * `slot_minutes` that each take up to `capacity` bookings.
 */
#[Fillable(['user_id', 'day_of_week', 'start_time', 'end_time', 'slot_minutes', 'capacity', 'is_active'])]
class ProviderSchedule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'slot_minutes' => 'integer',
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every slot start on $date that fits fully inside the working hours.
     *
     * @return array<int, Carbon>
     */
    public function slotStarts(Carbon $date): array
    {
        $cursor = Carbon::parse($date->toDateString().' '.$this->start_time);
        $end = Carbon::parse($date->toDateString().' '.$this->end_time);
        $slots = [];

        while ($cursor->copy()->addMinutes($this->slot_minutes)->lte($end)) {
            $slots[] = $cursor->copy();
            $cursor->addMinutes($this->slot_minutes);
        }

        return $slots;
    }

    /** Whether $at is one of this schedule's slot starts on that day. */
    public function hasSlotAt(Carbon $at): bool
    {
        foreach ($this->slotStarts($at->copy()->startOfDay()) as $slot) {
            if ($slot->equalTo($at)) {
                return true;
            }
        }

        return false;
    }
}
