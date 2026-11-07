<?php

namespace Database\Seeders\Concerns;

use App\Models\ClinicSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Small helpers for seeders that write records "as if" they happened
 * earlier, through the models so casts behave exactly as in the app.
 */
trait BackdatesRecords
{
    /**
     * Create a model whose created_at / updated_at are $at instead of now.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    protected function createAt(string $model, array $attributes, Carbon $at): Model
    {
        $record = new $model($attributes);
        $record->created_at = $at;
        $record->updated_at = $at;
        $record->save();

        return $record;
    }

    /**
     * Appointment slot start times for a day, as AppointmentController
     * builds them: every slot that fits fully inside the opening hours.
     *
     * @return array<int, Carbon>
     */
    protected function slotStarts(Carbon $day, ClinicSchedule $schedule): array
    {
        $cursor = Carbon::parse($day->toDateString().' '.$schedule->start_time);
        $end = Carbon::parse($day->toDateString().' '.$schedule->end_time);
        $slots = [];

        while ($cursor->copy()->addMinutes($schedule->slot_minutes)->lte($end)) {
            $slots[] = $cursor->copy();
            $cursor->addMinutes($schedule->slot_minutes);
        }

        return $slots;
    }
}
