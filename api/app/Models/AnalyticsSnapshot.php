<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['date', 'hour', 'arrival_count', 'avg_wait_time', 'avg_consult_time', 'peak_hour', 'event_day'])]
class AnalyticsSnapshot extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            // Deliberately not cast to 'date': this column is always queried
            // by exact string/whereIn match against 'Y-m-d' values (grouping,
            // forecasting). A date cast round-trips through datetime-with-
            // midnight-time on write, which breaks those exact matches.
            'hour' => 'integer',
            'arrival_count' => 'integer',
            'avg_wait_time' => 'integer',
            'avg_consult_time' => 'integer',
            'peak_hour' => 'boolean',
            'event_day' => 'boolean',
        ];
    }
}
