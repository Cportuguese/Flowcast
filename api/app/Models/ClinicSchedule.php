<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['day_of_week', 'start_time', 'end_time', 'slot_minutes', 'capacity', 'is_active'])]
class ClinicSchedule extends Model
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
}
