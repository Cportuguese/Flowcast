<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['date', 'label', 'note'])]
class EventDay extends Model
{
    protected function casts(): array
    {
        return [
            // Serialised as a plain calendar day. The bare `date` cast sends
            // a UTC timestamp, which in Manila names the day before.
            'date' => 'date:Y-m-d',
        ];
    }
}
